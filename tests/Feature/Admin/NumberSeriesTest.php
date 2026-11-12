<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Document\NumberSeries;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    Carbon::setTestNow('2026-11-12 10:00:00');
    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $this->currency = Currency::find(CompanySetting::getSetting('currency', $this->company->id))->code;

    $this->tokenFor = function (array $abilities) {
        $plain = $this->user->createToken('integration:test', [...$abilities, 'company:'.$this->company->id])->plainTextToken;
        app('auth')->forgetGuards();

        return $plain;
    };
    $this->asIntegration = fn () => $this->withHeaders(['Authorization' => 'Bearer '.($this->tokenFor)(['documents:read', 'documents:write', 'documents:issue'])]);
    $this->setPrefix = fn (?string $prefix = 'TDS') => CompanySetting::setSettings([NumberSeries::SETTING => $prefix ?? ''], $this->company->id);
    $this->money = fn (int $minor) => ['amount_minor' => $minor, 'currency' => $this->currency];
    $this->doc = fn () => [
        'customer_ref' => 'signage:C-1',
        'customer' => ['name' => 'Signage Customer', 'email' => 'c1@example.test'],
        'total' => ($this->money)(150000),
    ];
    $this->issueInvoice = function () {
        $draft = $this->postJson('api/v1/invoices', ($this->doc)())->assertCreated();

        return $this->postJson('api/v1/invoices/'.$draft->json('id').'/issue', [], ['If-Match' => $draft->headers->get('ETag')])->assertOk();
    };
});

afterEach(fn () => Carbon::setTestNow());

test('a company without a prefix keeps the legacy numbers', function () {
    ($this->asIntegration)();

    expect(($this->issueInvoice)()->json('number'))->toStartWith('INV-0');
});

test('each type has its own gap-free series: TDS-EST, TDS-INV, TDS-CN', function () {
    ($this->setPrefix)();
    ($this->asIntegration)();

    $est1 = $this->postJson('api/v1/estimates', ($this->doc)())->assertCreated();
    $est2 = $this->postJson('api/v1/estimates', ($this->doc)())->assertCreated();
    $inv1 = ($this->issueInvoice)();
    $inv2 = ($this->issueInvoice)();

    expect($est1->json('number'))->toBe('TDS-EST-2026-000001')
        ->and($est2->json('number'))->toBe('TDS-EST-2026-000002')
        ->and($est1->json('payment_reference'))->toBe('TDS-EST-2026-000001')
        ->and($inv1->json('number'))->toBe('TDS-INV-2026-000001')
        ->and($inv2->json('number'))->toBe('TDS-INV-2026-000002');

    $cn1 = $this->postJson('api/v1/invoices/'.$inv1->json('id').'/credit-notes', ['total_minor' => 50000])->assertCreated();
    $cn2 = $this->postJson('api/v1/invoices/'.$inv2->json('id').'/credit-notes', [])->assertCreated();

    expect($cn1->json('number'))->toBe('TDS-CN-2026-000001')
        ->and($cn2->json('number'))->toBe('TDS-CN-2026-000002');

    // the invoice series was not touched by the other types
    expect(($this->issueInvoice)()->json('number'))->toBe('TDS-INV-2026-000003');
});

test('a draft takes no number, so abandoned drafts leave no gap', function () {
    ($this->setPrefix)();
    ($this->asIntegration)();

    $this->postJson('api/v1/invoices', ($this->doc)())->assertCreated()->assertJsonPath('number', null);
    $this->postJson('api/v1/invoices', ($this->doc)())->assertCreated();

    expect(($this->issueInvoice)()->json('number'))->toBe('TDS-INV-2026-000001');
});

test('the sequence restarts every year and old numbers are never renumbered', function () {
    ($this->setPrefix)();
    ($this->asIntegration)();

    $december = ($this->issueInvoice)()->json('number');
    Carbon::setTestNow('2027-01-02 09:00:00');
    $january = ($this->issueInvoice)()->json('number');
    $second = ($this->issueInvoice)()->json('number');

    expect($december)->toBe('TDS-INV-2026-000001')
        ->and($january)->toBe('TDS-INV-2027-000001')
        ->and($second)->toBe('TDS-INV-2027-000002')
        ->and(Invoice::where('invoice_number', 'TDS-INV-2026-000001')->count())->toBe(1);
});

test('changing the prefix leaves issued numbers and continues the counter under the new prefix', function () {
    ($this->setPrefix)('TDS');
    ($this->asIntegration)();
    $first = ($this->issueInvoice)()->json('number');

    ($this->setPrefix)('TXX');
    $next = ($this->issueInvoice)()->json('number');

    expect($first)->toBe('TDS-INV-2026-000001')
        ->and($next)->toBe('TXX-INV-2026-000002')
        ->and(Invoice::where('invoice_number', $first)->exists())->toBeTrue();
});

test('a company can continue an existing sequence and cannot go backwards', function () {
    ($this->setPrefix)();
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeaders(['company' => $this->company->id]);

    $this->putJson('api/v1/number-series', ['next' => ['INV' => 1042, 'CN' => 7]])
        ->assertOk()
        ->assertJsonPath('prefix', 'TDS')
        ->assertJsonPath('series.INV.next_number', 'TDS-INV-2026-001042')
        ->assertJsonPath('series.CN.next_number', 'TDS-CN-2026-000007')
        ->assertJsonPath('series.EST.next_number', 'TDS-EST-2026-000001');

    ($this->asIntegration)();
    expect(($this->issueInvoice)()->json('number'))->toBe('TDS-INV-2026-001042');

    Sanctum::actingAs($this->user, ['*']);
    $this->putJson('api/v1/number-series', ['next' => ['INV' => 1042]])->assertStatus(422);
    $this->putJson('api/v1/number-series', ['next' => ['INV' => 1043]])->assertOk();
    $this->putJson('api/v1/number-series', ['next' => ['XYZ' => 5]])->assertStatus(422);
    $this->putJson('api/v1/number-series', ['prefix' => 'bad prefix'])->assertStatus(422);
    $this->getJson('api/v1/number-series')->assertOk()->assertJsonPath('series.INV.next', 1043);
});

test('only an owner with a full-access session manages the series', function () {
    ($this->asIntegration)();

    $this->getJson('api/v1/number-series', ['company' => $this->company->id])->assertForbidden();
    $this->putJson('api/v1/number-series', ['prefix' => 'EVL'], ['company' => $this->company->id])->assertForbidden();
});

test('reservations are unique and rolled back with their document', function () {
    $numbers = array_map(fn () => NumberSeries::reserve($this->company->id, 'INV', 2026), range(1, 200));

    expect($numbers)->toBe(range(1, 200));

    $taken = null;
    try {
        DB::transaction(function () use (&$taken) {
            $taken = NumberSeries::reserve($this->company->id, 'INV', 2026);
            throw new RuntimeException('document failed to save');
        });
    } catch (RuntimeException) {
    }

    expect(NumberSeries::reserve($this->company->id, 'INV', 2026))->toBe($taken);
});

test('the database refuses a duplicate number in the same company, year and sequence', function () {
    ($this->setPrefix)();
    ($this->asIntegration)();
    ($this->issueInvoice)();
    $other = Invoice::factory()->create(['company_id' => $this->company->id]);

    expect(fn () => $other->forceFill(['number_year' => 2026, 'series_number' => 1])->save())->toThrow(QueryException::class);
});

test('new customer_ref without customer is 422, with customer it is created', function () {
    ($this->asIntegration)();

    $this->postJson('api/v1/invoices', ['customer_ref' => 'signage:NEW-9', 'total' => ($this->money)(1000)])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->postJson('api/v1/invoices', ['customer_ref' => 'signage:NEW-9', 'customer' => ['name' => 'New One', 'email' => 'n@example.test'], 'total' => ($this->money)(1000)])
        ->assertCreated();
    $this->postJson('api/v1/invoices', ['customer_ref' => 'signage:NEW-9', 'total' => ($this->money)(1000)])->assertCreated();
});
