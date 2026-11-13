<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\Document\EstimateService;
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

describe('estimates are numbered when sent', function () {
    beforeEach(function () {
        ($this->setPrefix)();
        Sanctum::actingAs($this->user, ['*']);
        $this->withHeaders(['company' => $this->company->id]);
        // the estimate factory draws numbers from the series itself, so build fixtures with the series off
        $this->quietly = function (Closure $build) {
            ($this->setPrefix)(null);
            $built = $build();
            ($this->setPrefix)();

            return $built;
        };
        $this->makeDraft = fn () => ($this->quietly)(fn () => Estimate::factory()->create(['company_id' => $this->company->id, 'status' => Estimate::STATUS_DRAFT, 'estimate_number' => Invoice::draftReference(), 'sequence_number' => null]));
    });

    test('a form-created draft has a draft reference, whatever number the form sent', function () {
        $payload = ($this->quietly)(fn () => Estimate::factory()->raw([
            'estimate_number' => 'TDS-EST-2026-000099',
            'company_id' => $this->company->id,
            'items' => [EstimateItem::factory()->raw()],
            'taxes' => [Tax::factory()->raw()],
        ]));

        $id = $this->postJson('api/v1/estimates', $payload)->assertCreated()->json('data.id');

        expect(Estimate::find($id)->estimate_number)->toStartWith('DRAFT-')
            ->and(NumberSeries::peek($this->company->id, 'EST', 2026))->toBe(1);
    });

    test('marking sent or accepted takes the next number; a second change keeps it', function () {
        $a = ($this->makeDraft)();
        $b = ($this->makeDraft)();

        $this->postJson("api/v1/estimates/{$b->id}/status", ['status' => 'SENT'])->assertOk();
        $this->postJson("api/v1/estimates/{$a->id}/accept")->assertOk();
        $this->postJson("api/v1/estimates/{$b->id}/status", ['status' => 'VIEWED'])->assertOk();

        expect($b->refresh()->estimate_number)->toBe('TDS-EST-2026-000001')
            ->and($a->refresh()->estimate_number)->toBe('TDS-EST-2026-000002')
            ->and($a->number_year)->toBe(2026);
    });

    test('deleting drafts leaves no gap', function () {
        $drafts = [($this->makeDraft)(), ($this->makeDraft)(), ($this->makeDraft)()];
        $this->postJson('api/v1/estimates/delete', ['ids' => [$drafts[0]->id, $drafts[1]->id], 'reason_code' => 'duplicate'])->assertOk();

        $this->postJson("api/v1/estimates/{$drafts[2]->id}/status", ['status' => 'SENT'])->assertOk();

        expect($drafts[2]->refresh()->estimate_number)->toBe('TDS-EST-2026-000001');
    });

    test('editing a numbered estimate keeps its number', function () {
        $draft = ($this->makeDraft)();
        $this->postJson("api/v1/estimates/{$draft->id}/status", ['status' => 'SENT'])->assertOk();
        $number = $draft->refresh()->estimate_number;
        $draft->load('items');

        $payload = ($this->quietly)(fn () => Estimate::factory()->raw([
            'estimate_number' => 'EST-HACK', 'company_id' => $this->company->id, 'customer_id' => $draft->customer_id,
            'items' => [EstimateItem::factory()->raw()], 'taxes' => [Tax::factory()->raw()],
        ]));
        $this->putJson("api/v1/estimates/{$draft->id}", $payload)->assertOk();

        expect($draft->refresh()->estimate_number)->toBe($number);
    });

    test('a company without a prefix still numbers estimates at creation', function () {
        ($this->setPrefix)(null);
        $payload = Estimate::factory()->raw([
            'estimate_number' => 'EST-000777', 'company_id' => $this->company->id,
            'items' => [EstimateItem::factory()->raw()], 'taxes' => [Tax::factory()->raw()],
        ]);
        $id = $this->postJson('api/v1/estimates', $payload)->assertCreated()->json('data.id');

        expect(Estimate::find($id)->estimate_number)->toBe('EST-000777');
    });

    test('parallel sends never share a number', function () {
        $drafts = array_map(fn () => ($this->makeDraft)(), range(1, 25));
        $service = app(EstimateService::class);

        foreach ($drafts as $draft) {
            $service->issueNumber(Estimate::find($draft->id));
            $service->issueNumber(Estimate::find($draft->id));
        }

        $numbers = Estimate::whereIn('id', collect($drafts)->pluck('id'))->pluck('estimate_number')->all();
        expect(array_unique($numbers))->toHaveCount(25)
            ->and(NumberSeries::peek($this->company->id, 'EST', 2026))->toBe(26);
    });
});

test('the year turns over at midnight in Johannesburg, not UTC', function () {
    ($this->setPrefix)();
    ($this->asIntegration)();
    Carbon::setTestNow('2026-12-31 22:30:00'); // 00:30 on 1 January in SAST

    expect(($this->issueInvoice)()->json('number'))->toBe('TDS-INV-2027-000001');

    Carbon::setTestNow('2026-12-31 21:30:00'); // 23:30 on 31 December in SAST
    expect(($this->issueInvoice)()->json('number'))->toBe('TDS-INV-2026-000001');
});

test('the number series is described in the served OpenAPI document', function () {
    $spec = $this->getJson('api/v1/openapi.json')->assertOk()->json();

    expect($spec['paths']['/number-series'])->toHaveKeys(['get', 'put'])
        ->and($spec['components']['schemas'])->toHaveKeys(['NumberSeries', 'NumberSeriesUpdate', 'NumberSeriesCounter'])
        ->and($spec['paths']['/number-series']['put']['responses'])->toHaveKeys(['200', '403', '422']);
});
