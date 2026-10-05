<?php

use App\Mail\SendInvoiceMail;
use App\Models\Company;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
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

    $this->estimate = Estimate::factory()->create([
        'company_id' => $this->company->id,
        'status' => Estimate::STATUS_ACCEPTED,
        'estimate_date' => now(),
        'expiry_date' => now()->addMonth(),
        'total' => 50000,
        'sub_total' => 50000,
        'tax' => 0,
    ]);
    $this->estimate->customer->update(['email' => 'client@example.com']);

    $this->payload = fn (array $over = []) => array_merge([
        'payment_date' => '2026-10-06',
        'amount' => 50000,
        'send_email' => false,
    ], $over);
    $this->url = "api/v1/estimates/{$this->estimate->id}/issue-invoice";
});

test('issues a paid, linked invoice with a payment in one go', function () {
    $response = postJson($this->url, ($this->payload)())->assertCreated();

    $invoice = Invoice::find($response->json('data.id'));
    $payment = Payment::where('invoice_id', $invoice->id)->first();

    expect($invoice->estimate_id)->toBe($this->estimate->id)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PAID)
        ->and($invoice->due_amount)->toBe(0)
        ->and($payment->amount)->toBe(50000)
        ->and($payment->customer_id)->toBe($this->estimate->customer_id)
        ->and($this->estimate->fresh()->status)->toBe(Estimate::STATUS_ACCEPTED)
        ->and($response->json('created'))->toBeTrue();
});

test('double submit is idempotent: one invoice, one payment', function () {
    $first = postJson($this->url, ($this->payload)())->assertCreated();
    $second = postJson($this->url, ($this->payload)())->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('created'))->toBeFalse()
        ->and(Invoice::where('estimate_id', $this->estimate->id)->count())->toBe(1)
        ->and(Payment::where('invoice_id', $first->json('data.id'))->count())->toBe(1);
});

test('amount different from the quote total is rejected and nothing is created', function () {
    postJson($this->url, ($this->payload)(['amount' => 49999]))->assertJsonValidationErrors('amount');

    expect(Invoice::where('estimate_id', $this->estimate->id)->exists())->toBeFalse()
        ->and(Payment::count())->toBe(0);
});

test('only an accepted quote can be invoiced', function () {
    $this->estimate->update(['status' => Estimate::STATUS_SENT]);

    postJson($this->url, ($this->payload)())->assertJsonValidationErrors('status');

    expect(Invoice::where('estimate_id', $this->estimate->id)->exists())->toBeFalse();
});

test('invoice is emailed with the paid invoice attached', function () {
    Mail::fake();

    postJson($this->url, ($this->payload)(['send_email' => true]))->assertCreated();

    Mail::assertSent(SendInvoiceMail::class, fn ($m) => $m->data['to'] === 'client@example.com');
});

test('mail failure rolls everything back so the call can be retried', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    postJson($this->url, ($this->payload)(['send_email' => true]))->assertJsonValidationErrors('send_email');

    expect(Invoice::where('estimate_id', $this->estimate->id)->exists())->toBeFalse()
        ->and(Payment::count())->toBe(0)
        ->and($this->estimate->fresh()->status)->toBe(Estimate::STATUS_ACCEPTED);
});

test('emailing without a customer email address is refused and nothing is recorded', function () {
    $this->estimate->customer->update(['email' => null]);

    postJson($this->url, ($this->payload)(['send_email' => true]))->assertJsonValidationErrors('send_email');

    expect(Invoice::where('estimate_id', $this->estimate->id)->exists())->toBeFalse();
});

test('an accepted quote can no longer be edited', function () {
    $payload = Estimate::factory()->raw([
        'estimate_number' => $this->estimate->estimate_number,
        'customer_id' => $this->estimate->customer_id,
        'items' => [EstimateItem::factory()->raw()],
        'taxes' => [Tax::factory()->raw()],
    ]);

    putJson("api/v1/estimates/{$this->estimate->id}", $payload)->assertJsonValidationErrors('status');
});

test('another company quote cannot be invoiced', function () {
    $foreign = Estimate::factory()->create([
        'company_id' => Company::factory()->create()->id,
        'status' => Estimate::STATUS_ACCEPTED,
        'estimate_date' => now(),
        'expiry_date' => now()->addMonth(),
    ]);

    postJson("api/v1/estimates/{$foreign->id}/issue-invoice", ($this->payload)())->assertForbidden();
});

test('a member without invoice/payment abilities cannot issue an invoice', function () {
    $member = User::factory()->create();
    $member->companies()->attach($this->company->id);
    Sanctum::actingAs($member, ['*']);

    postJson($this->url, ($this->payload)())->assertForbidden();

    expect(Invoice::where('estimate_id', $this->estimate->id)->exists())->toBeFalse();
});
