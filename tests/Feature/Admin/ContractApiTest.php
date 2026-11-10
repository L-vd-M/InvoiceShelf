<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $this->currency = Currency::find(CompanySetting::getSetting('currency', $this->company->id))->code;

    $this->tokenFor = function (array $abilities) {
        $this->withHeaders([]);
        $plain = $this->user->createToken('integration:test', [...$abilities, 'company:'.$this->company->id])->plainTextToken;
        app('auth')->forgetGuards();

        return $plain;
    };

    $full = ['documents:read', 'documents:write', 'documents:issue', 'events:read', 'webhooks:manage'];
    $this->as = fn (array $abilities = null) => $this->withHeaders(['Authorization' => 'Bearer '.($this->tokenFor)($abilities ?? $full)]);

    $this->events = fn (string $type) => OutboxEvent::where('type', $type)->orderBy('id')->get();
    $this->money = fn (int $minor) => ['amount_minor' => $minor, 'currency' => $this->currency];
    $this->doc = fn (array $extra = []) => array_replace_recursive([
        'customer_ref' => 'signage:C-1',
        'customer' => ['name' => 'Signage Customer', 'email' => 'c1@example.test'],
        'total' => ($this->money)(150000),
    ], $extra);
    $this->etag = fn (string $path) => $this->getJson($path)->assertOk()->headers->get('ETag');
});

test('create a quote: contract shape, ETag, customer created from the reference', function () {
    ($this->as)();

    $response = $this->postJson('api/v1/estimates', ($this->doc)(['external_ref' => ['source' => 'signage', 'id' => 'Q-1']]), ['Idempotency-Key' => '01JABCDEFGHJKMNPQRSTVWXYZ1'])
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('customer_ref', 'signage:C-1')
        ->assertJsonPath('total', ($this->money)(150000))
        ->assertJsonPath('external_ref', ['source' => 'signage', 'id' => 'Q-1']);

    expect($response->headers->get('ETag'))->toStartWith('"')
        ->and($response->json('payment_reference'))->toBe($response->json('number'))
        ->and($response->json('id'))->toBeString();

    expect(Customer::whereExternalRef('signage:C-1')->count())->toBe(1);

    $replay = $this->postJson('api/v1/estimates', ($this->doc)(['external_ref' => ['source' => 'signage', 'id' => 'Q-1']]), ['Idempotency-Key' => '01JABCDEFGHJKMNPQRSTVWXYZ2'])->assertOk();
    expect($replay->json('id'))->toBe($response->json('id'));
});

test('lists page by cursor and find by external_ref', function () {
    ($this->as)();
    foreach (range(1, 3) as $i) {
        $this->postJson('api/v1/estimates', ($this->doc)(['external_ref' => ['source' => 'signage', 'id' => "Q-{$i}"]]))->assertCreated();
    }

    $page1 = $this->getJson('api/v1/estimates?limit=2')->assertOk();
    expect($page1->json('data'))->toHaveCount(2)->and($page1->json('next_cursor'))->not->toBeNull();
    $page2 = $this->getJson('api/v1/estimates?limit=2&cursor='.$page1->json('next_cursor'))->assertOk();
    expect($page2->json('data'))->toHaveCount(1)->and($page2->json('next_cursor'))->toBeNull();

    $this->getJson('api/v1/estimates?external_ref=signage:Q-2')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('api/v1/estimates?limit=999')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $this->getJson('api/v1/estimates?external_ref=nocolon')->assertStatus(422);
});

test('accept needs If-Match, writes quote.accepted and deal.won once and is idempotent', function () {
    ($this->as)();
    $id = $this->postJson('api/v1/estimates', ($this->doc)())->assertCreated()->json('id');
    $etag = ($this->etag)("api/v1/estimates/{$id}");

    $this->postJson("api/v1/estimates/{$id}/accept")->assertStatus(428)->assertJsonPath('code', 'precondition_required');
    $this->postJson("api/v1/estimates/{$id}/accept", [], ['If-Match' => '"stale"'])->assertStatus(412)->assertJsonPath('code', 'precondition_failed');

    $accepted = $this->postJson("api/v1/estimates/{$id}/accept", [], ['If-Match' => $etag])->assertOk()->assertJsonPath('status', 'accepted');
    expect(($this->events)('quote.accepted'))->toHaveCount(1)->and(($this->events)('deal.won'))->toHaveCount(1);

    $again = $this->postJson("api/v1/estimates/{$id}/accept", [], ['If-Match' => $accepted->headers->get('ETag')])->assertOk();
    expect($again->json('status'))->toBe('accepted')->and(($this->events)('quote.accepted'))->toHaveCount(1);
});

test('invoice: create draft, issue assigns the number, pdf carries a sha-256 Digest', function () {
    ($this->as)();
    $draft = $this->postJson('api/v1/invoices', ($this->doc)(['external_ref' => ['source' => 'signage', 'id' => 'INV-1']]))->assertCreated()
        ->assertJsonPath('status', 'draft')->assertJsonPath('number', null);
    $id = $draft->json('id');

    $this->get("api/v1/invoices/{$id}/pdf")->assertStatus(409)->assertJsonPath('code', 'document_immutable');

    $issued = $this->postJson("api/v1/invoices/{$id}/issue", [], ['If-Match' => $draft->headers->get('ETag')])->assertOk()->assertJsonPath('status', 'issued');
    expect($issued->json('number'))->toStartWith('INV-')->and($issued->json('payment_reference'))->not->toBeEmpty();

    $pdf = $this->get("api/v1/invoices/{$id}/pdf")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($pdf->headers->get('Digest'))->toBe('sha-256='.base64_encode(hash('sha256', $pdf->getContent(), true)));

    $event = ($this->events)('document.issued')->first();
    expect($event->data['sha256'])->toBe(hash('sha256', $pdf->getContent()));

    $this->postJson("api/v1/invoices/{$id}/issue", [], ['If-Match' => $issued->headers->get('ETag')])->assertOk();
    expect(($this->events)('document.issued'))->toHaveCount(1);
});

test('credit notes: partial then the rest voids the invoice; issue is idempotent', function () {
    ($this->as)();
    $draft = $this->postJson('api/v1/invoices', ($this->doc)())->assertCreated();
    $id = $draft->json('id');
    $this->postJson("api/v1/invoices/{$id}/issue", [], ['If-Match' => $draft->headers->get('ETag')])->assertOk();

    $this->postJson("api/v1/invoices/{$id}/credit-notes", ['total_minor' => 999999])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $partial = $this->postJson("api/v1/invoices/{$id}/credit-notes", ['total_minor' => 50000, 'reason' => 'Goodwill discount'])->assertCreated()
        ->assertJsonPath('status', 'issued')->assertJsonPath('total', ($this->money)(50000));
    expect($partial->json('number'))->toStartWith('CN-')->and(($this->events)('document.voided'))->toHaveCount(0);

    $this->postJson('api/v1/credit-notes/'.$partial->json('id').'/issue', [], ['If-Match' => $partial->headers->get('ETag')])->assertOk()
        ->assertJsonPath('number', $partial->json('number'));

    $rest = $this->postJson("api/v1/invoices/{$id}/credit-notes", [])->assertCreated()->assertJsonPath('total', ($this->money)(100000));
    expect(($this->events)('document.voided'))->toHaveCount(1)
        ->and(($this->events)('document.issued'))->toHaveCount(3);
    $this->getJson("api/v1/invoices/{$id}")->assertJsonPath('status', 'voided');
    $this->getJson('api/v1/credit-notes')->assertOk()->assertJsonCount(2, 'data');
});

test('payments are idempotent on external_ref and refuse a different amount or too much', function () {
    ($this->as)();
    $draft = $this->postJson('api/v1/invoices', ($this->doc)())->assertCreated();
    $id = $draft->json('id');
    $this->postJson("api/v1/invoices/{$id}/issue", [], ['If-Match' => $draft->headers->get('ETag')])->assertOk();

    $body = ['invoice_id' => $id, 'amount' => ($this->money)(100000), 'external_ref' => 'bank-tx-0001'];
    $first = $this->postJson('api/v1/payments', $body)->assertCreated()->assertJsonPath('external_ref', 'bank-tx-0001')->assertJsonPath('invoice_id', $id);
    $second = $this->postJson('api/v1/payments', $body)->assertOk();
    expect($second->json('id'))->toBe($first->json('id'))->and(DB::table('payments')->where('bank_transaction_id', 'bank-tx-0001')->count())->toBe(1);

    $event = ($this->events)('payment.recorded');
    expect($event)->toHaveCount(1)->and($event[0]->data['external_ref'])->toBe('bank-tx-0001')
        ->and($event[0]->data['amount'])->toBe(($this->money)(100000));

    $this->postJson('api/v1/payments', ['amount' => ($this->money)(5)] + $body)->assertStatus(409)->assertJsonPath('code', 'already_exists');
    $this->postJson('api/v1/payments', ['external_ref' => 'bank-tx-0002', 'amount' => ($this->money)(60000)] + $body)->assertStatus(422);
    $this->postJson('api/v1/payments', ['invoice_id' => '999999'] + $body)->assertStatus(404)->assertJsonPath('code', 'not_found');

    expect(Invoice::find($id)->paid_status)->toBe(Invoice::STATUS_PARTIALLY_PAID);
});

test('supplied_number needs documents:shadow, conflicts return 409 and the counter is not consumed', function () {
    ($this->as)(['documents:read', 'documents:write', 'documents:issue']);
    $this->postJson('api/v1/estimates', ($this->doc)(['supplied_number' => 'QT-LEGACY-1']))->assertStatus(403)->assertJsonPath('code', 'insufficient_ability');

    ($this->as)(['documents:read', 'documents:write', 'documents:issue', 'documents:shadow']);
    $counter = fn () => (int) DB::table('document_counters')->where(['company_id' => $this->company->id, 'document_type' => 'estimate'])->value('last_sequence');
    $before = $counter();

    $quote = $this->postJson('api/v1/estimates', ($this->doc)(['supplied_number' => 'QT-LEGACY-1']))->assertCreated()->assertJsonPath('number', 'QT-LEGACY-1')->assertJsonPath('payment_reference', 'QT-LEGACY-1');
    expect($counter())->toBe($before);

    $this->postJson('api/v1/estimates', ($this->doc)(['supplied_number' => 'QT-LEGACY-1']))->assertStatus(409)->assertJsonPath('code', 'number_conflict');
    $this->postJson('api/v1/estimates', ($this->doc)(['supplied_number' => 'bad number']))->assertStatus(422);

    $invCounter = fn () => (int) DB::table('document_counters')->where(['company_id' => $this->company->id, 'document_type' => 'invoice'])->value('last_sequence');
    $invBefore = $invCounter();
    $draft = $this->postJson('api/v1/invoices', ($this->doc)(['supplied_number' => 'INV-LEGACY-9']))->assertCreated();
    $this->postJson('api/v1/invoices', ($this->doc)(['supplied_number' => 'INV-LEGACY-9']))->assertStatus(409);
    $this->postJson('api/v1/estimates', ($this->doc)(['supplied_number' => 'INV-LEGACY-9']))->assertStatus(409);
    $issued = $this->postJson('api/v1/invoices/'.$draft->json('id').'/issue', [], ['If-Match' => $draft->headers->get('ETag')])->assertOk();
    expect($issued->json('number'))->toBe('INV-LEGACY-9')->and($invCounter())->toBe($invBefore);
});

test('validation: total must match the lines, currency must match the customer, unknown customers are refused', function () {
    ($this->as)();

    $this->postJson('api/v1/estimates', ($this->doc)(['lines' => [['name' => 'Sign', 'quantity' => 2, 'unit_price_minor' => 10000]]]))
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    $this->postJson('api/v1/estimates', ($this->doc)(['lines' => [['name' => 'Sign', 'quantity' => 3, 'unit_price_minor' => 50000]]]))
        ->assertCreated();

    $this->postJson('api/v1/estimates', ($this->doc)(['customer_ref' => 'signage:C-1', 'total' => ['currency' => 'USD']]))->assertStatus(422);
    $this->postJson('api/v1/estimates', ['customer_ref' => 'signage:nobody', 'total' => ($this->money)(1000)])->assertStatus(422)->assertJsonPath('errors.customer_ref.0', 'unknown');
    $this->postJson('api/v1/estimates', ['total' => ($this->money)(1000)])->assertStatus(422)->assertJsonValidationErrors('customer_ref');
});

test('a read-only token cannot create; the web representation is unchanged for full tokens', function () {
    ($this->as)(['documents:read']);
    $this->postJson('api/v1/estimates', ($this->doc)())->assertStatus(403)->assertJsonPath('code', 'insufficient_ability');

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);
    App\Models\Estimate::factory()->create(['company_id' => $this->company->id]);
    $native = $this->getJson('api/v1/estimates?page=1')->assertOk();
    expect($native->json())->toHaveKey('data')->and($native->json('data.0'))->toHaveKey('estimate_number');
});
