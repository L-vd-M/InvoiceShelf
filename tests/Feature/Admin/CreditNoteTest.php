<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);

    // R200 + R100 lines, no tax, no discount: total R300 (30000 cents)
    $this->makeInvoice = function (array $over = []) {
        $invoice = Invoice::factory()->create($over + [
            'company_id' => $this->company->id,
            'status' => Invoice::STATUS_SENT,
            'paid_status' => Invoice::STATUS_UNPAID,
            'sub_total' => 30000, 'total' => 30000, 'tax' => 0, 'discount_val' => 0, 'discount' => 0,
            'due_amount' => 30000, 'credited_total' => 0,
        ]);
        $this->lineA = InvoiceItem::create(['invoice_id' => $invoice->id, 'company_id' => $this->company->id, 'name' => 'Screen mount', 'quantity' => 2, 'price' => 10000, 'total' => 20000, 'discount_type' => 'fixed', 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'base_price' => 0, 'base_total' => 0, 'base_tax' => 0, 'base_discount_val' => 0, 'exchange_rate' => 1]);
        $this->lineB = InvoiceItem::create(['invoice_id' => $invoice->id, 'company_id' => $this->company->id, 'name' => 'Cable', 'quantity' => 1, 'price' => 10000, 'total' => 10000, 'discount_type' => 'fixed', 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'base_price' => 0, 'base_total' => 0, 'base_tax' => 0, 'base_discount_val' => 0, 'exchange_rate' => 1]);

        return $invoice;
    };
});

test('a full credit clears the balance and gets its own gap-free number', function () {
    $invoice = ($this->makeInvoice)();

    $response = postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'billing_error'])->assertCreated();

    $response->assertJsonPath('data.total', 30000)
        ->assertJsonPath('data.applied_amount', 30000)
        ->assertJsonPath('data.unapplied_amount', 0)
        ->assertJsonPath('data.credit_note_number', 'CN-000001')
        ->assertJsonPath('data.reason', 'Billing error');

    $invoice->refresh();
    expect($invoice->due_amount)->toBe(0)
        ->and((int) $invoice->credited_total)->toBe(30000)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PAID);

    $second = ($this->makeInvoice)();
    postJson("api/v1/invoices/{$second->id}/credit-notes", ['reason_code' => 'goodwill'])
        ->assertJsonPath('data.credit_note_number', 'CN-000002');
});

test('a partial credit takes some quantity and leaves the rest creditable', function () {
    $invoice = ($this->makeInvoice)();

    postJson("api/v1/invoices/{$invoice->id}/credit-notes", [
        'reason_code' => 'goods_returned',
        'items' => [['invoice_item_id' => $this->lineA->id, 'quantity' => 1]],
    ])->assertCreated()->assertJsonPath('data.total', 10000);

    expect($invoice->fresh()->due_amount)->toBe(20000);

    postJson("api/v1/invoices/{$invoice->id}/credit-notes", [
        'reason_code' => 'goods_returned',
        'items' => [['invoice_item_id' => $this->lineA->id, 'quantity' => 2]],
    ])->assertJsonValidationErrors('items');

    postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'goodwill'])
        ->assertCreated()->assertJsonPath('data.total', 20000);

    postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'goodwill'])
        ->assertJsonValidationErrors('items');
});

test('credit on a paid invoice is held as unapplied amount', function () {
    $invoice = ($this->makeInvoice)(['due_amount' => 0, 'paid_status' => Invoice::STATUS_PAID, 'status' => Invoice::STATUS_COMPLETED]);

    postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'service_not_delivered'])
        ->assertCreated()
        ->assertJsonPath('data.applied_amount', 0)
        ->assertJsonPath('data.unapplied_amount', 30000);
});

test('draft invoices are refused, other needs a reason, and a credited invoice cannot be deleted', function () {
    $draft = ($this->makeInvoice)(['status' => Invoice::STATUS_DRAFT]);
    postJson("api/v1/invoices/{$draft->id}/credit-notes", ['reason_code' => 'goodwill'])->assertJsonValidationErrors('invoice');

    $invoice = ($this->makeInvoice)();
    postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'other'])->assertJsonValidationErrors('reason');
    postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'other', 'reason' => 'Customer renegotiated'])->assertCreated();

    postJson('api/v1/invoices/delete', ['ids' => [$invoice->id], 'reason_code' => 'created_in_error'])
        ->assertJsonValidationErrors('ids.0');
    expect(Invoice::find($invoice->id))->not->toBeNull();
});

test('credit notes can be listed, read and rendered as PDF', function () {
    $invoice = ($this->makeInvoice)();
    $id = postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'goodwill'])->json('data.id');

    getJson("api/v1/credit-notes?invoice_id={$invoice->id}")->assertOk()->assertJsonCount(1, 'data');
    getJson("api/v1/credit-notes/{$id}")->assertOk()->assertJsonCount(2, 'data.items');

    $pdf = $this->get("api/v1/credit-notes/{$id}/pdf");
    $pdf->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and(CreditNote::count())->toBe(1);
});

test('the creditable endpoint reports what is left per line', function () {
    $invoice = ($this->makeInvoice)();
    postJson("api/v1/invoices/{$invoice->id}/credit-notes", [
        'reason_code' => 'goods_returned',
        'items' => [['invoice_item_id' => $this->lineA->id, 'quantity' => 1]],
    ])->assertCreated();

    $data = getJson("api/v1/invoices/{$invoice->id}/creditable")->assertOk()->json('data');

    expect($data['remaining'])->toBe(20000)
        ->and($data['can_credit'])->toBeTrue()
        ->and($data['items'][0]['remaining_quantity'])->toEqual(1)
        ->and($data['items'][0]['credited_quantity'])->toEqual(1)
        ->and($data['items'][1]['remaining_quantity'])->toEqual(1);
});
