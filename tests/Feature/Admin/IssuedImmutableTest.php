<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Tax;
use App\Models\User;
use App\Services\Document\InvoiceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);

    $this->payload = fn () => Invoice::factory()->raw([
        'company_id' => $this->company->id,
        'taxes' => [Tax::factory()->raw()],
        'items' => [InvoiceItem::factory()->raw()],
    ]);

    $this->draft = function (): int {
        return postJson('api/v1/invoices', ($this->payload)())->assertOk()->json('data.id');
    };

    // the factory also takes a number on every raw(), so read the counter right before the call under test
    $this->counter = fn () => (int) DB::table('document_counters')
        ->where(['company_id' => $this->company->id, 'document_type' => 'invoice'])->value('last_sequence');
});

test('a new draft has a draft reference and no legal number', function () {
    $id = ($this->draft)();

    $invoice = Invoice::find($id);
    expect($invoice->invoice_number)->toStartWith('DRAFT-')
        ->and($invoice->sequence_number)->toBeNull()
        ->and($invoice->issued_at)->toBeNull();
});

test('deleting a draft never leaves a gap in the numbers', function () {
    $first = ($this->draft)();
    $second = ($this->draft)();

    postJson('api/v1/invoices/delete', ['ids' => [$first], 'reason_code' => 'duplicate'])->assertOk();
    $before = ($this->counter)();

    $number = postJson("api/v1/invoices/{$second}/issue")->assertOk()->json('data.sequence_number');

    expect($number)->toBe($before + 1);
});

test('issue assigns the number, stamps issued_at and freezes the invoice', function () {
    $id = ($this->draft)();

    $response = postJson("api/v1/invoices/{$id}/issue")->assertOk();

    $response->assertJsonPath('data.is_issued', true)
        ->assertJsonPath('data.allow_edit', false)
        ->assertJsonPath('data.status', Invoice::STATUS_SENT);
    expect($response->json('data.invoice_number'))->not->toStartWith('DRAFT-')
        ->and($response->json('data.issued_at'))->not->toBeNull();
});

test('issuing twice returns the same invoice and takes no second number', function () {
    $id = ($this->draft)();

    $a = postJson("api/v1/invoices/{$id}/issue")->assertOk()->json('data');
    $b = postJson("api/v1/invoices/{$id}/issue")->assertOk()->json('data');

    expect($b['invoice_number'])->toBe($a['invoice_number'])
        ->and($b['sequence_number'])->toBe($a['sequence_number'])
        ->and($b['issued_at'])->toBe($a['issued_at']);

    $other = ($this->draft)();
    $before = ($this->counter)();
    $next = postJson("api/v1/invoices/{$other}/issue")->assertOk()->json('data.sequence_number');
    expect($next)->toBe($before + 1);
});

test('issuing several drafts gives unique, increasing numbers in issue order', function () {
    $ids = [($this->draft)(), ($this->draft)(), ($this->draft)()];

    $numbers = collect(array_reverse($ids))->map(
        fn ($id) => postJson("api/v1/invoices/{$id}/issue")->assertOk()->json('data.sequence_number')
    );

    expect($numbers->unique())->toHaveCount(3)
        ->and($numbers->all())->toBe(collect($numbers)->sort()->values()->all())
        ->and($numbers[2] - $numbers[0])->toBe(2);
});

test('a rolled back issue returns the number', function () {
    $service = app(InvoiceService::class);
    $first = Invoice::find(($this->draft)());
    $second = Invoice::find(($this->draft)());
    $before = ($this->counter)();

    try {
        DB::transaction(function () use ($service, $first) {
            $service->issue($first);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect($first->fresh()->issued_at)->toBeNull();

    expect($service->issue($second)->sequence_number)->toBe($before + 1);
});

test('editing an issued invoice is refused with 409 document_immutable', function () {
    $id = ($this->draft)();
    postJson("api/v1/invoices/{$id}/issue")->assertOk();
    $number = Invoice::find($id)->invoice_number;

    $response = putJson("api/v1/invoices/{$id}", ($this->payload)())->assertStatus(409);

    $response->assertJsonPath('code', 'document_immutable')
        ->assertJsonPath('status', 409);
    expect($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and(Invoice::find($id)->invoice_number)->toBe($number);
});

test('deleting an issued invoice is refused and a mixed batch deletes nothing', function () {
    $draftId = ($this->draft)();
    $issuedId = ($this->draft)();
    postJson("api/v1/invoices/{$issuedId}/issue")->assertOk();

    postJson('api/v1/invoices/delete', ['ids' => [$draftId, $issuedId], 'reason_code' => 'duplicate'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'document_immutable');

    expect(Invoice::find($draftId))->not->toBeNull()
        ->and(Invoice::find($issuedId))->not->toBeNull()
        ->and(DB::table('document_deletions')->where('document_id', $issuedId)->exists())->toBeFalse();
});

test('an issued invoice can still be viewed, cloned and paid', function () {
    $id = ($this->draft)();
    $issued = postJson("api/v1/invoices/{$id}/issue")->assertOk()->json('data');

    getJson("api/v1/invoices/{$id}")->assertOk()->assertJsonPath('data.is_issued', true);

    $clone = postJson("api/v1/invoices/{$id}/clone")->assertSuccessful()->json('data');
    expect($clone['is_issued'])->toBeFalse()
        ->and($clone['invoice_number'])->toStartWith('DRAFT-');

    $payment = Payment::factory()->raw([
        'company_id' => $this->company->id,
        'customer_id' => $issued['customer_id'],
        'invoice_id' => $id,
        'amount' => 1,
    ]);
    postJson('api/v1/payments', $payment + ['exchange_rate' => 1])->assertOk();

    expect(Payment::where('invoice_id', $id)->count())->toBe(1)
        ->and(Invoice::find($id)->isIssued())->toBeTrue();
});

test('recording a payment against a draft issues it first', function () {
    $id = ($this->draft)();
    $customerId = Invoice::find($id)->customer_id;

    postJson('api/v1/payments', Payment::factory()->raw([
        'company_id' => $this->company->id,
        'customer_id' => $customerId,
        'invoice_id' => $id,
        'amount' => 1,
    ]) + ['exchange_rate' => 1])->assertOk();

    expect(Invoice::find($id)->isIssued())->toBeTrue()
        ->and(Invoice::find($id)->invoice_number)->not->toStartWith('DRAFT-');
});

test('sending a draft issues it first so the email carries the legal number', function () {
    Mail::fake();
    $id = ($this->draft)();

    postJson("api/v1/invoices/{$id}/send", [
        'subject' => 'Invoice {INVOICE_NUMBER}',
        'body' => 'Hello {INVOICE_NUMBER}',
        'from' => 'a@b.test',
        'to' => 'c@d.test',
    ])->assertOk();

    $invoice = Invoice::find($id);
    expect($invoice->isIssued())->toBeTrue()
        ->and($invoice->invoice_number)->not->toStartWith('DRAFT-');
});

test('marking a draft as sent issues it', function () {
    $id = ($this->draft)();

    postJson("api/v1/invoices/{$id}/status", ['status' => Invoice::STATUS_SENT])->assertOk();

    expect(Invoice::find($id)->isIssued())->toBeTrue();
});

test('creating an invoice as sent issues it in the same transaction', function () {
    $request = new App\Http\Requests\InvoicesRequest;
    $request->headers->set('company', (string) $this->company->id);
    $request->merge(($this->payload)() + ['invoiceSend' => true]);
    $request->setUserResolver(fn () => $this->user);

    $invoice = app(InvoiceService::class)->create($request);

    expect($invoice->isIssued())->toBeTrue()
        ->and($invoice->sequence_number)->not->toBeNull()
        ->and($invoice->invoice_number)->not->toStartWith('DRAFT-');
});

test('migration backfills sent invoices as issued and leaves drafts open, and reverses cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_15_090000_add_issued_at_to_invoices_table.php');

    $migration->down();
    expect(Schema::hasColumn('invoices', 'issued_at'))->toBeFalse();

    $sent = Invoice::factory()->create(['status' => Invoice::STATUS_SENT, 'sent' => true]);
    $draft = Invoice::factory()->create(['status' => Invoice::STATUS_DRAFT, 'sent' => false]);
    $number = $sent->invoice_number;

    $migration->up();

    expect(Schema::hasColumn('invoices', 'issued_at'))->toBeTrue();
    expect(Invoice::find($sent->id)->issued_at)->not->toBeNull()
        ->and(Invoice::find($sent->id)->invoice_number)->toBe($number)
        ->and(Invoice::find($draft->id)->issued_at)->toBeNull();
});
