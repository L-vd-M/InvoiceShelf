<?php

use App\Models\CreditNote;
use App\Models\DocumentRevision;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\Document\InvoiceService;
use App\Services\Document\RevisionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

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

    $this->draft = fn (): int => postJson('api/v1/invoices', ($this->payload)())->assertOk()->json('data.id');

    $this->revisions = fn (string $type, int $id) => DocumentRevision::where('document_type', $type)
        ->where('document_id', $id)->orderBy('revision_no')->get();

    $this->reasons = fn (string $type, int $id) => ($this->revisions)($type, $id)->pluck('reason_code')->all();

    // R200 + R100 lines, no tax, no discount: total R300 (30000 cents)
    $this->makeInvoice = function () {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'status' => Invoice::STATUS_SENT,
            'paid_status' => Invoice::STATUS_UNPAID,
            'sub_total' => 30000, 'total' => 30000, 'tax' => 0, 'discount_val' => 0, 'discount' => 0,
            'due_amount' => 30000, 'credited_total' => 0,
        ]);
        foreach ([['Screen mount', 2, 20000], ['Cable', 1, 10000]] as [$name, $qty, $total]) {
            InvoiceItem::create(['invoice_id' => $invoice->id, 'company_id' => $this->company->id, 'name' => $name, 'quantity' => $qty, 'price' => 10000, 'total' => $total, 'discount_type' => 'fixed', 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'base_price' => 0, 'base_total' => 0, 'base_tax' => 0, 'base_discount_val' => 0, 'exchange_rate' => 1]);
        }

        return $invoice;
    };

    $this->estimatePayload = fn () => Estimate::factory()->raw([
        'items' => [EstimateItem::factory()->raw()],
        'taxes' => [Tax::factory()->raw()],
    ]);
});

test('creating a draft writes exactly one created revision with a verifiable hash', function () {
    $id = ($this->draft)();

    $revisions = ($this->revisions)('invoice', $id);
    expect($revisions)->toHaveCount(1);

    $first = $revisions->first();
    expect($first->revision_no)->toBe(1)
        ->and($first->reason_code)->toBe('created')
        ->and($first->status)->toBe(Invoice::STATUS_DRAFT)
        ->and($first->diff_from_previous)->toBeNull()
        ->and($first->actor_user_id)->toBe($this->user->id)
        ->and($first->actor_role)->toBe('owner')
        ->and($first->source)->toBe('ui')
        ->and($first->snapshot['invoice_number'])->toStartWith('DRAFT-')
        ->and($first->snapshot['items'])->toHaveCount(1)
        ->and($first->snapshot['customer']['id'])->toBe(Invoice::find($id)->customer_id)
        ->and($first->hashMatches())->toBeTrue()
        ->and($first->totals_hash)->toBe(RevisionService::hash($first->snapshot));
});

test('the same snapshot always hashes the same, any other snapshot differently', function () {
    $snapshot = ['b' => 1, 'a' => ['y' => 'x', 'x' => null], 'items' => [['n' => '1'], ['n' => '2']]];
    $reordered = ['items' => [['n' => '1'], ['n' => '2']], 'a' => ['x' => null, 'y' => 'x'], 'b' => 1];

    expect(RevisionService::hash($snapshot))->toBe(RevisionService::hash($reordered))
        ->and(RevisionService::hash($snapshot))->not->toBe(RevisionService::hash($snapshot + ['c' => 1]))
        ->and(RevisionService::hash(['items' => [['n' => '2'], ['n' => '1']]]))->not->toBe(RevisionService::hash(['items' => [['n' => '1'], ['n' => '2']]]));
});

test('an edit writes one edited revision with the changed paths, a repeat save writes none', function () {
    $id = ($this->draft)();
    $payload = ($this->payload)();
    $payload['notes'] = 'Customer asked for 10 % off';

    putJson("api/v1/invoices/{$id}", $payload)->assertOk();

    $revisions = ($this->revisions)('invoice', $id);
    expect($revisions->pluck('reason_code')->all())->toBe(['created', 'edited']);

    $paths = collect($revisions->last()->diff_from_previous)->keyBy('path');
    expect($paths['notes']['after'])->toBe('Customer asked for 10 % off');

    putJson("api/v1/invoices/{$id}", $payload)->assertOk();

    expect(($this->revisions)('invoice', $id))->toHaveCount(2);
});

test('issue writes one issued revision, a second issue writes none', function () {
    $id = ($this->draft)();

    postJson("api/v1/invoices/{$id}/issue")->assertOk();
    postJson("api/v1/invoices/{$id}/issue")->assertOk();

    $revisions = ($this->revisions)('invoice', $id);
    expect($revisions->pluck('reason_code')->all())->toBe(['created', 'issued']);

    $issued = $revisions->last();
    $changed = collect($issued->diff_from_previous)->keyBy('path');
    expect($changed['invoice_number']['before'])->toStartWith('DRAFT-')
        ->and($changed['invoice_number']['after'])->not->toStartWith('DRAFT-')
        ->and($issued->snapshot['issued_at'])->not->toBeNull()
        ->and($issued->status)->toBe(Invoice::STATUS_SENT);
});

test('marking a draft as sent records created then issued', function () {
    $id = ($this->draft)();

    postJson("api/v1/invoices/{$id}/status", ['status' => Invoice::STATUS_SENT])->assertOk();

    expect(($this->reasons)('invoice', $id))->toBe(['created', 'issued']);
});

test('sending records a sent revision after the draft is issued', function () {
    Mail::fake();
    $id = ($this->draft)();

    postJson("api/v1/invoices/{$id}/send", [
        'subject' => 'Invoice {INVOICE_NUMBER}', 'body' => 'Hello', 'from' => 'a@b.test', 'to' => 'c@d.test',
    ])->assertOk();

    expect(($this->reasons)('invoice', $id))->toBe(['created', 'issued', 'sent']);
});

test('revision numbers run 1..n without gaps or repeats', function () {
    Mail::fake();
    $id = ($this->draft)();
    putJson("api/v1/invoices/{$id}", array_merge(($this->payload)(), ['notes' => 'v2']))->assertOk();
    postJson("api/v1/invoices/{$id}/issue")->assertOk();
    postJson("api/v1/invoices/{$id}/send", ['subject' => 's', 'body' => 'b', 'from' => 'a@b.test', 'to' => 'c@d.test'])->assertOk();

    $numbers = ($this->revisions)('invoice', $id)->pluck('revision_no')->all();

    expect($numbers)->toBe(range(1, count($numbers)))->and(count($numbers))->toBe(4);
});

test('a rolled back change leaves no revision', function () {
    $id = ($this->draft)();
    $invoice = Invoice::find($id);

    try {
        DB::transaction(function () use ($invoice) {
            $invoice->update(['notes' => 'changed then abandoned']);
            RevisionService::record($invoice, 'edited');
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect(($this->revisions)('invoice', $id))->toHaveCount(1);
});

test('a failed issue leaves neither a number nor a revision', function () {
    $id = ($this->draft)();
    $invoice = Invoice::find($id);

    try {
        DB::transaction(function () use ($invoice) {
            app(InvoiceService::class)->issue($invoice);
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect(($this->reasons)('invoice', $id))->toBe(['created'])
        ->and(Invoice::find($id)->isIssued())->toBeFalse();
});

test('revision rows cannot be changed or removed by the application', function () {
    $id = ($this->draft)();
    $revision = ($this->revisions)('invoice', $id)->first();

    expect(fn () => $revision->update(['note' => 'rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $revision->delete())->toThrow(LogicException::class);
});

test('tampering with a stored snapshot is detected', function () {
    $id = ($this->draft)();
    $revision = ($this->revisions)('invoice', $id)->first();
    expect($revision->hashMatches())->toBeTrue();

    $snapshot = $revision->snapshot;
    $snapshot['total'] = 1;
    DB::table('document_revisions')->where('id', $revision->id)->update(['snapshot' => json_encode($snapshot)]);

    expect($revision->fresh()->hashMatches())->toBeFalse();
});

test('deleting a draft keeps its history', function () {
    $id = ($this->draft)();

    postJson('api/v1/invoices/delete', ['ids' => [$id], 'reason_code' => 'duplicate'])->assertOk();

    expect(Invoice::find($id))->toBeNull()
        ->and(($this->reasons)('invoice', $id))->toBe(['created']);
});

test('creating a quote records created, status changes record their own reason', function () {
    $id = postJson('api/v1/estimates', ($this->estimatePayload)())->assertStatus(201)->json('data.id');

    postJson("api/v1/estimates/{$id}/status", ['status' => Estimate::STATUS_ACCEPTED])->assertOk();
    postJson("api/v1/estimates/{$id}/status", ['status' => Estimate::STATUS_ACCEPTED])->assertOk();

    expect(($this->reasons)('estimate', $id))->toBe(['created', 'accepted']);

    $other = postJson('api/v1/estimates', ($this->estimatePayload)())->assertStatus(201)->json('data.id');
    postJson("api/v1/estimates/{$other}/status", ['status' => Estimate::STATUS_REJECTED])->assertOk();

    expect(($this->reasons)('estimate', $other))->toBe(['created', 'rejected']);
});

test('editing a quote records edited', function () {
    $id = postJson('api/v1/estimates', ($this->estimatePayload)())->assertStatus(201)->json('data.id');
    $payload = ($this->estimatePayload)();
    $payload['notes'] = 'Valid for 14 days';

    putJson("api/v1/estimates/{$id}", $payload)->assertOk();

    expect(($this->reasons)('estimate', $id))->toBe(['created', 'edited']);
});

test('the expiry job records expired as a system revision', function () {
    $estimate = Estimate::factory()->create([
        'company_id' => $this->company->id,
        'status' => Estimate::STATUS_SENT,
        'expiry_date' => now()->subDays(3),
    ]);
    RevisionService::record($estimate, 'created');

    Artisan::call('check:estimates:status');

    $last = ($this->revisions)('estimate', $estimate->id)->last();
    expect($last->reason_code)->toBe('expired')
        ->and($last->source)->toBe('system')
        ->and($last->actor_user_id)->toBeNull();
});

test('converting a quote records converted on the quote and created on the invoice draft', function () {
    $id = postJson('api/v1/estimates', ($this->estimatePayload)())->assertStatus(201)->json('data.id');

    $invoiceId = postJson("api/v1/estimates/{$id}/convert-to-invoice")->assertOk()->json('data.id');
    postJson("api/v1/estimates/{$id}/convert-to-invoice")->assertOk();

    expect(($this->reasons)('estimate', $id))->toBe(['created', 'converted'])
        ->and(($this->reasons)('invoice', $invoiceId))->toBe(['created']);
});

test('a partial credit records credited on the invoice, a full one voided', function () {
    $invoice = ($this->makeInvoice)();
    RevisionService::backfill();

    $itemId = $invoice->items()->orderBy('id')->first()->id;
    $first = postJson("api/v1/invoices/{$invoice->id}/credit-notes", [
        'reason_code' => 'goodwill', 'items' => [['invoice_item_id' => $itemId, 'quantity' => 1]],
    ])->assertCreated();

    expect(($this->reasons)('invoice', $invoice->id))->toBe(['created', 'credited'])
        ->and(($this->reasons)('credit_note', $first->json('data.id')))->toBe(['created', 'issued']);

    $credited = ($this->revisions)('invoice', $invoice->id)->last();
    expect($credited->snapshot['credited_total'])->toBe(10000)
        ->and($credited->note)->toContain($first->json('data.credit_note_number'));

    postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'billing_error'])->assertCreated();

    expect(($this->reasons)('invoice', $invoice->id))->toBe(['created', 'credited', 'voided']);
});

test('backfill gives every document without history one created revision and is idempotent', function () {
    $invoice = ($this->makeInvoice)();
    $estimate = Estimate::factory()->create(['company_id' => $this->company->id]);
    $creditNote = CreditNote::create([
        'company_id' => $this->company->id, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
        'credit_note_number' => 'CN-LEGACY', 'credit_note_date' => now()->toDateString(), 'status' => CreditNote::STATUS_ISSUED,
        'reason_code' => 'goodwill', 'reason' => 'Goodwill', 'sub_total' => 100, 'tax' => 0, 'total' => 100,
        'applied_amount' => 0, 'unapplied_amount' => 100, 'currency_id' => $invoice->currency_id, 'exchange_rate' => 1,
    ]);

    $existing = Invoice::count() + Estimate::count() + CreditNote::count();
    expect(DocumentRevision::count())->toBe(0);

    $this->artisan('documents:backfill-revisions')->assertExitCode(0);
    expect(DocumentRevision::count())->toBe($existing);

    $this->artisan('documents:backfill-revisions')->assertExitCode(0);
    expect(DocumentRevision::count())->toBe($existing);

    $revision = ($this->revisions)('invoice', $invoice->id)->sole();
    expect($revision->reason_code)->toBe('created')
        ->and($revision->source)->toBe('system')
        ->and($revision->created_at->equalTo($invoice->created_at))->toBeTrue()
        ->and($revision->hashMatches())->toBeTrue()
        ->and(($this->reasons)('estimate', $estimate->id))->toBe(['created'])
        ->and(($this->reasons)('credit_note', $creditNote->id))->toBe(['created']);
});

test('a backfilled invoice continues its history with the next revision number', function () {
    $invoice = ($this->makeInvoice)();
    RevisionService::backfill();

    RevisionService::record($invoice, 'sent');

    expect(($this->revisions)('invoice', $invoice->id)->pluck('revision_no')->all())->toBe([1, 2]);
});

test('unknown reasons and sources are refused', function () {
    $invoice = ($this->makeInvoice)();

    expect(fn () => RevisionService::record($invoice, 'tweaked'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RevisionService::record($invoice, 'edited', ['source' => 'telepathy']))->toThrow(InvalidArgumentException::class);
});
