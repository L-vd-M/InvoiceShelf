<?php

use App\Models\Invoice;
use App\Models\User;
use App\Services\Document\SerialNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);

    $this->serial = fn () => (new SerialNumberService)->setModel(new Invoice)->setCompany($this->company->id)->setCustomer(null);
});

test('two reservations never receive the same sequence number', function () {
    $a = ($this->serial)()->reserveNextNumbers()->nextSequenceNumber;
    $b = ($this->serial)()->reserveNextNumbers()->nextSequenceNumber;

    expect($b)->toBe($a + 1);
});

test('a reservation rolled back with its document returns the number', function () {
    $taken = null;

    try {
        DB::transaction(function () use (&$taken) {
            $taken = ($this->serial)()->reserveNextNumbers()->nextSequenceNumber;
            throw new RuntimeException('document failed to save');
        });
    } catch (RuntimeException) {
    }

    $again = ($this->serial)()->reserveNextNumbers()->nextSequenceNumber;

    expect($again)->toBe($taken);
});

test('the counter starts above the highest existing number', function () {
    Invoice::factory()->create(['company_id' => $this->company->id, 'sequence_number' => 41]);
    DB::table('document_counters')->where('company_id', $this->company->id)->delete();

    expect(($this->serial)()->reserveNextNumbers()->nextSequenceNumber)->toBeGreaterThanOrEqual(42);
});

test('the database refuses a second invoice with the same sequence number', function () {
    expect(collect(Schema::getIndexes('invoices'))->contains('name', 'invoices_company_sequence_unique'))->toBeTrue();

    Invoice::factory()->create(['company_id' => $this->company->id, 'sequence_number' => 9001]);

    expect(fn () => Invoice::factory()->create(['company_id' => $this->company->id, 'sequence_number' => 9001]))
        ->toThrow(QueryException::class);
});

test('a number text already issued by a request that arrived first is replaced by the reserved number', function () {
    $existing = Invoice::factory()->create(['company_id' => $this->company->id, 'invoice_number' => 'INV-STALE', 'sequence_number' => 500]);

    // bypass form validation: the race is between validation and insert
    $service = app(App\Services\Document\InvoiceService::class);
    $request = new App\Http\Requests\InvoicesRequest;
    $request->headers->set('company', (string) $this->company->id);
    $request->merge([
        'invoice_date' => '2026-10-12', 'due_date' => '2026-10-26', 'customer_id' => $existing->customer_id,
        'invoice_number' => 'INV-STALE', 'exchange_rate' => 1, 'template_name' => 'invoice1',
        'discount' => 0, 'discount_val' => 0, 'sub_total' => 1000, 'total' => 1000, 'tax' => 0,
        'items' => [['name' => 'x', 'quantity' => 1, 'price' => 1000, 'discount_type' => 'fixed', 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'total' => 1000]],
    ]);
    $request->setUserResolver(fn () => $this->user);

    $invoice = $service->create($request);

    expect($invoice->invoice_number)->not->toBe('INV-STALE')
        ->and($invoice->sequence_number)->toBeGreaterThan(500);
});
