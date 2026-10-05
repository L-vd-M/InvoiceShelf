<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->user = $user;
    $this->company = $user->companies()->first();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($user, ['*']);

    $this->estimate = Estimate::factory()->create([
        'company_id' => $this->company->id,
        'estimate_date' => now(),
        'expiry_date' => now()->addMonth(),
    ]);
});

test('conversion links the invoice to the estimate and copies its number as reference', function () {
    $response = postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->assertOk();

    $invoice = Invoice::find($response->json('data.id'));

    expect($invoice->estimate_id)->toBe($this->estimate->id)
        ->and($invoice->source_estimate_number)->toBe($this->estimate->estimate_number)
        ->and($invoice->reference_number)->toBe($this->estimate->estimate_number)
        ->and($this->estimate->fresh()->invoice->id)->toBe($invoice->id);
});

test('converting the same estimate twice returns the existing invoice', function () {
    $first = postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->assertOk();
    $second = postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Invoice::where('estimate_id', $this->estimate->id)->count())->toBe(1);
});

test('delete_estimate convert action no longer deletes the estimate', function () {
    CompanySetting::setSettings(['estimate_convert_action' => 'delete_estimate'], $this->company->id);

    postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->assertOk();

    $this->assertDatabaseHas('estimates', ['id' => $this->estimate->id]);
});

test('mark_estimate_as_accepted convert action still works', function () {
    CompanySetting::setSettings(['estimate_convert_action' => 'mark_estimate_as_accepted'], $this->company->id);

    postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->assertOk();

    expect($this->estimate->fresh()->status)->toBe(Estimate::STATUS_ACCEPTED);
});

test('deleting an estimate that has an invoice is refused', function () {
    postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->assertOk();

    postJson('api/v1/estimates/delete', ['ids' => [$this->estimate->id]])
        ->assertJsonValidationErrors('ids.0');

    $this->assertDatabaseHas('estimates', ['id' => $this->estimate->id]);
});

test('an estimate without an invoice can still be deleted', function () {
    postJson('api/v1/estimates/delete', ['ids' => [$this->estimate->id]])->assertOk();

    $this->assertDatabaseMissing('estimates', ['id' => $this->estimate->id]);
});

test('api resources expose the link in both directions', function () {
    $invoiceId = postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->json('data.id');

    getJson("api/v1/invoices/{$invoiceId}")
        ->assertOk()
        ->assertJsonPath('data.estimate_id', $this->estimate->id)
        ->assertJsonPath('data.source_estimate_number', $this->estimate->estimate_number)
        ->assertJsonPath('data.estimate.estimate_number', $this->estimate->estimate_number);

    getJson("api/v1/estimates/{$this->estimate->id}")
        ->assertOk()
        ->assertJsonPath('data.invoice.id', $invoiceId);
});

test('two invoices cannot link to the same estimate', function () {
    postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->assertOk();

    expect(fn () => Invoice::factory()->create([
        'company_id' => $this->company->id,
        'estimate_id' => $this->estimate->id,
    ]))->toThrow(QueryException::class);
});

test('deleting a linked estimate at database level keeps the invoice and its snapshot', function () {
    $invoiceId = postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->json('data.id');

    $this->estimate->delete();

    $invoice = Invoice::find($invoiceId);
    expect($invoice->estimate_id)->toBeNull()
        ->and($invoice->source_estimate_number)->toBe($this->estimate->estimate_number);
});

test('conversion falls back to the creators default invoice template when the quote template has no invoice twin', function () {
    UserSetting::create(['user_id' => $this->user->id, 'key' => 'default_invoice_template', 'value' => 'invoice3']);
    $this->estimate->update(['template_name' => 'no-such-estimate-template', 'creator_id' => $this->user->id]);

    $invoiceId = postJson("api/v1/estimates/{$this->estimate->id}/convert-to-invoice")->json('data.id');

    expect(Invoice::find($invoiceId)->template_name)->toBe('invoice3');
});
