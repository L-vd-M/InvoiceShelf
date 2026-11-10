<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OutboxEvent;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

/** The arithmetic rules of the contract kit (tests/contracts/semantic.py) for document.issued / document.voided. */
function assertAccountingArithmetic(array $data): void
{
    $currency = $data['total']['currency'];
    $net = $tax = $gross = 0;
    foreach ($data['lines'] as $i => $line) {
        foreach (['net', 'tax', 'gross'] as $k) {
            expect($line[$k]['currency'])->toBe($currency, "line {$i} {$k} currency");
        }
        expect($line['net']['amount_minor'] + $line['tax']['amount_minor'])->toBe($line['gross']['amount_minor'], "line {$i} net + tax = gross");
        expect($line['quantity'])->toMatch('/^-?\d+(\.\d+)?$/');
        $net += $line['net']['amount_minor'];
        $tax += $line['tax']['amount_minor'];
        $gross += $line['gross']['amount_minor'];
    }
    expect($data['totals']['net']['amount_minor'])->toBe($net)
        ->and($data['totals']['tax']['amount_minor'])->toBe($tax)
        ->and($data['totals']['gross']['amount_minor'])->toBe($gross)
        ->and($data['total']['amount_minor'])->toBe($data['totals']['gross']['amount_minor']);

    if (! $data['tenant_vat']['registered']) {
        foreach ($data['lines'] as $line) {
            expect($line['tax']['amount_minor'])->toBe(0)->and($line['tax_code'])->toBe('NONE');
        }
    }

    if ($currency === 'ZAR') {
        expect($data)->not->toHaveKey('fx');
    } else {
        $expect = (int) round($data['total']['amount_minor'] * (float) $data['fx']['rate']);
        expect(abs($data['fx']['zar']['amount_minor'] - $expect))->toBeLessThanOrEqual(1)->and($data['fx']['zar']['currency'])->toBe('ZAR');
    }
}

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $zar = Currency::where('code', 'ZAR')->value('id');
    CompanySetting::setSettings(['currency' => $zar], $this->company->id);
    $this->zar = $zar;

    $token = $this->user->createToken('integration:acct', ['documents:read', 'documents:write', 'documents:issue', 'company:'.$this->company->id])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$token}"]);

    $this->event = fn (string $type) => OutboxEvent::where('type', $type)->orderByDesc('id')->first();
    $this->issuedInvoice = function (?array $lines = null) {
        $lines ??= [['name' => 'Sign', 'quantity' => 1, 'unit_price_minor' => 50000]];
        $draft = postJson('api/v1/invoices', [
            'customer_ref' => 'signage:C-9', 'customer' => ['name' => 'Acme Signs (Pty) Ltd'],
            'total' => ['amount_minor' => array_sum(array_map(fn ($l) => (int) round($l['quantity'] * $l['unit_price_minor']), $lines)), 'currency' => 'ZAR'],
            'lines' => $lines,
        ])->assertCreated();

        return postJson('api/v1/invoices/'.$draft->json('id').'/issue', [], ['If-Match' => $draft->headers->get('ETag')])->assertOk()->json('id');
    };
});

test('document.issued carries lines, totals, customer and tenant_vat for a non-vendor', function () {
    ($this->issuedInvoice)([
        ['name' => 'LED sign 2m', 'quantity' => 2, 'unit_price_minor' => 125000, 'kind' => 'goods', 'stream' => 'signage', 'part_ref' => 'PART-0001'],
        ['name' => 'Installation', 'quantity' => 1.5, 'unit_price_minor' => 40000, 'kind' => 'service'],
    ]);

    $data = ($this->event)('document.issued')->data;
    assertAccountingArithmetic($data);

    expect($data['lines'])->toHaveCount(2)
        ->and($data['lines'][0])->toMatchArray(['kind' => 'goods', 'description' => 'LED sign 2m', 'quantity' => '2', 'stream' => 'signage', 'part_ref' => 'PART-0001', 'tax_code' => 'NONE', 'tax_rate_bps' => 0])
        ->and($data['lines'][1]['quantity'])->toBe('1.5')
        ->and($data['totals']['net']['amount_minor'])->toBe(310000)
        ->and($data['customer'])->toBe(['ref' => 'signage:C-9', 'name' => 'Acme Signs (Pty) Ltd', 'country' => 'ZA', 'currency' => 'ZAR'])
        ->and($data['tenant_vat'])->toBe(['registered' => false, 'effective_from' => null]);
});

test('a line without a kind is other', function () {
    ($this->issuedInvoice)();

    expect(($this->event)('document.issued')->data['lines'][0]['kind'])->toBe('other');
});

test('credit notes and document.voided carry the same accounting content', function () {
    $id = ($this->issuedInvoice)([['name' => 'Sign', 'quantity' => 2, 'unit_price_minor' => 30000, 'kind' => 'goods']]);

    postJson("api/v1/invoices/{$id}/credit-notes", ['total_minor' => 20000])->assertCreated();
    $partial = OutboxEvent::where('type', 'document.issued')->orderByDesc('id')->first()->data;
    assertAccountingArithmetic($partial);
    expect($partial['document_type'])->toBe('credit_note')->and($partial['total']['amount_minor'])->toBe(20000)->and($partial['lines'])->toHaveCount(1);

    postJson("api/v1/invoices/{$id}/credit-notes", [])->assertCreated();
    $voided = ($this->event)('document.voided')->data;
    assertAccountingArithmetic($voided);
    expect($voided['lines'][0]['kind'])->toBe('goods')->and($voided['total']['amount_minor'])->toBe(60000)->and($voided['lines'][0]['quantity'])->toBe('2');
});

test('a document discount becomes a discount line and the lines still add up', function () {
    Sanctum::actingAs($this->user, ['*']);
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);

    $payload = Invoice::factory()->raw([
        'discount_type' => 'fixed', 'discount' => 10, 'discount_val' => 1000, 'discount_per_item' => 'NO', 'tax_per_item' => 'NO',
        'taxes' => [], 'items' => [InvoiceItem::factory()->raw(['price' => 10000, 'quantity' => 3, 'discount' => 0, 'discount_val' => 0, 'tax' => 0])],
    ]);
    $id = postJson('api/v1/invoices', $payload)->assertOk()->json('data.id');
    postJson("api/v1/invoices/{$id}/issue")->assertOk();

    $data = ($this->event)('document.issued')->data;
    assertAccountingArithmetic($data);
    expect(collect($data['lines'])->pluck('kind')->all())->toContain('discount')
        ->and($data['total']['amount_minor'])->toBe(29000);
});

test('a foreign-currency document carries fx fixed at issue; a ZAR one carries none', function () {
    Sanctum::actingAs($this->user, ['*']);
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);

    $usd = Currency::where('code', 'USD')->value('id');
    $customer = Customer::factory()->create(['company_id' => $this->company->id, 'currency_id' => $usd]);
    $payload = Invoice::factory()->raw([
        'customer_id' => $customer->id, 'currency_id' => $usd, 'exchange_rate' => 18.5, 'discount_val' => 0, 'discount' => 0, 'tax_per_item' => 'NO',
        'taxes' => [], 'items' => [InvoiceItem::factory()->raw(['price' => 10000, 'quantity' => 1, 'discount' => 0, 'discount_val' => 0, 'tax' => 0])],
    ]);
    $id = postJson('api/v1/invoices', $payload)->assertOk()->json('data.id');
    postJson("api/v1/invoices/{$id}/issue")->assertOk();

    $data = ($this->event)('document.issued')->data;
    assertAccountingArithmetic($data);
    expect($data['total']['currency'])->toBe('USD')
        ->and($data['fx']['rate'])->toBe('18.5')
        ->and($data['fx']['zar']['amount_minor'])->toBe(185000)
        ->and($data['fx']['rate_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

test('a document that carries tax is issued by a registered tenant with tax codes', function () {
    config(['invoiceshelf.tenant_vat_registered' => true, 'invoiceshelf.tenant_vat_effective_from' => '2026-12-01']);
    Sanctum::actingAs($this->user, ['*']);
    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);

    $taxType = Tax::factory()->raw(['percent' => 15, 'amount' => 1500, 'compound_tax' => 0]);
    $payload = Invoice::factory()->raw([
        'discount_val' => 0, 'discount' => 0, 'tax_per_item' => 'NO', 'taxes' => [$taxType],
        'items' => [InvoiceItem::factory()->raw(['price' => 10000, 'quantity' => 1, 'discount' => 0, 'discount_val' => 0, 'tax' => 0])],
    ]);
    $id = postJson('api/v1/invoices', $payload)->assertOk()->json('data.id');
    postJson("api/v1/invoices/{$id}/issue")->assertOk();

    $data = ($this->event)('document.issued')->data;
    assertAccountingArithmetic($data);
    expect($data['tenant_vat'])->toBe(['registered' => true, 'effective_from' => '2026-12-01'])
        ->and($data['totals']['tax']['amount_minor'])->toBe(1500)
        ->and($data['lines'][0]['tax_code'])->toBe('STANDARD')->and($data['lines'][0]['tax_rate_bps'])->toBe(1500);
});
