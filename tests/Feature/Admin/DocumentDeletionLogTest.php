<?php

use App\Models\DocumentDeletion;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);
});

test('deleting an invoice without a reason is refused and nothing is deleted', function () {
    $invoice = Invoice::factory()->create(['company_id' => $this->company->id]);

    postJson('api/v1/invoices/delete', ['ids' => [$invoice->id]])->assertJsonValidationErrors('reason');
    postJson('api/v1/invoices/delete', ['ids' => [$invoice->id], 'reason' => 'x'])->assertJsonValidationErrors('reason');

    expect(Invoice::find($invoice->id))->not->toBeNull()
        ->and(DocumentDeletion::count())->toBe(0);
});

test('a deleted invoice leaves a record with number, who, why and state', function () {
    $invoice = Invoice::factory()->create(['company_id' => $this->company->id, 'status' => Invoice::STATUS_DRAFT]);

    postJson('api/v1/invoices/delete', ['ids' => [$invoice->id], 'reason' => 'Customer cancelled before it was sent'])->assertOk();

    $log = DocumentDeletion::first();

    expect(Invoice::find($invoice->id))->toBeNull()
        ->and($log->document_type)->toBe('invoice')
        ->and($log->document_id)->toBe($invoice->id)
        ->and($log->document_number)->toBe($invoice->invoice_number)
        ->and($log->sequence_number)->toBe($invoice->sequence_number)
        ->and($log->status_at_deletion)->toBe(Invoice::STATUS_DRAFT)
        ->and($log->reason)->toBe('Customer cancelled before it was sent')
        ->and($log->deleted_by)->toBe($this->user->id)
        ->and($log->company_id)->toBe($this->company->id);
});

test('estimates and payments are logged too', function () {
    $estimate = App\Models\Estimate::factory()->create(['company_id' => $this->company->id]);
    $payment = App\Models\Payment::factory()->create(['company_id' => $this->company->id]);

    postJson('api/v1/estimates/delete', ['ids' => [$estimate->id], 'reason' => 'Superseded by new quote'])->assertOk();
    postJson('api/v1/payments/delete', ['ids' => [$payment->id], 'reason' => 'Recorded against wrong invoice'])->assertOk();

    expect(DocumentDeletion::pluck('document_type')->sort()->values()->all())->toBe(['estimate', 'payment']);
});
