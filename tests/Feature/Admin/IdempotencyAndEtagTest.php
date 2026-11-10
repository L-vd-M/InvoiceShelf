<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);

    $this->invoicePayload = fn () => Invoice::factory()->raw([
        'taxes' => [Tax::factory()->raw()],
        'items' => [InvoiceItem::factory()->raw()],
    ]);
});

test('same key and same body replays the first response and creates nothing twice', function () {
    $payload = ($this->invoicePayload)();
    $before = Invoice::count();

    $first = postJson('api/v1/invoices', $payload, ['Idempotency-Key' => '01JABCDEFGHJKMNPQRSTVWXYZ0'])->assertOk();
    $second = postJson('api/v1/invoices', $payload, ['Idempotency-Key' => '01JABCDEFGHJKMNPQRSTVWXYZ0'])->assertOk();

    expect(Invoice::count())->toBe($before + 1)
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull();
});

test('key order in the body does not matter', function () {
    $payload = ['name' => 'Zed Ltd', 'email' => 'zed@example.test'];
    $reordered = ['email' => 'zed@example.test', 'name' => 'Zed Ltd'];

    $a = postJson('api/v1/customers', $payload, ['Idempotency-Key' => 'key-order-0001'])->assertOk();
    $b = postJson('api/v1/customers', $reordered, ['Idempotency-Key' => 'key-order-0001'])->assertOk();

    expect($b->json('data.id'))->toBe($a->json('data.id'));
});

test('same key with a different body is 422 idempotency_conflict', function () {
    postJson('api/v1/customers', ['name' => 'One', 'email' => 'one@example.test'], ['Idempotency-Key' => 'conflict-key-1'])->assertOk();

    postJson('api/v1/customers', ['name' => 'Two', 'email' => 'two@example.test'], ['Idempotency-Key' => 'conflict-key-1'])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_conflict');
    expect(Customer::where('name', 'Two')->exists())->toBeFalse();
});

test('failed validation is replayed too and a malformed key is refused', function () {
    $first = postJson('api/v1/customers', [], ['Idempotency-Key' => 'invalid-body-1'])->assertStatus(422);
    $second = postJson('api/v1/customers', [], ['Idempotency-Key' => 'invalid-body-1'])->assertStatus(422);
    expect($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($second->json())->toBe($first->json());

    postJson('api/v1/customers', ['name' => 'x'], ['Idempotency-Key' => 'short'])->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_invalid');
});

test('requests without a key are unaffected and keys are scoped to the company', function () {
    postJson('api/v1/customers', ['name' => 'A', 'email' => 'a@example.test'])->assertOk();
    postJson('api/v1/customers', ['name' => 'B', 'email' => 'b@example.test'])->assertOk();
    expect(App\Models\IdempotencyKey::count())->toBe(0);
});

test('a crashed in-flight request does not block the key forever', function () {
    App\Models\IdempotencyKey::create([
        'company_id' => $this->company->id, 'key' => 'stale-lock-0001', 'method' => 'POST', 'path' => 'api/v1/customers',
        'request_hash' => str_repeat('a', 64), 'locked_at' => now()->subMinutes(10), 'expires_at' => now()->addDay(),
    ]);

    postJson('api/v1/customers', ['name' => 'Fresh', 'email' => 'fresh@example.test'], ['Idempotency-Key' => 'stale-lock-0001'])->assertOk();

    $fresh = App\Models\IdempotencyKey::create([
        'company_id' => $this->company->id, 'key' => 'live-lock-00001', 'method' => 'POST', 'path' => 'api/v1/customers',
        'request_hash' => str_repeat('b', 64), 'locked_at' => now(), 'expires_at' => now()->addDay(),
    ]);
    postJson('api/v1/customers', ['name' => 'X'], ['Idempotency-Key' => 'live-lock-00001'])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_conflict');
    expect($fresh->fresh())->not->toBeNull();
});

test('expired keys are pruned and reusable', function () {
    App\Models\IdempotencyKey::create([
        'company_id' => $this->company->id, 'key' => 'expired-key-01', 'method' => 'POST', 'path' => 'api/v1/customers',
        'request_hash' => str_repeat('c', 64), 'response_status' => 200, 'response_body' => '{}', 'expires_at' => now()->subHour(),
    ]);

    Artisan::call('idempotency:prune');
    expect(App\Models\IdempotencyKey::count())->toBe(0);
});

test('GET returns an ETag, If-None-Match gives 304', function () {
    $id = postJson('api/v1/invoices', ($this->invoicePayload)())->assertOk()->json('data.id');

    $etag = getJson("api/v1/invoices/{$id}")->assertOk()->headers->get('ETag');
    expect($etag)->toStartWith('"');

    getJson("api/v1/invoices/{$id}", ['If-None-Match' => $etag])->assertStatus(304);
});

test('PUT with a stale If-Match is 412 and with the current one succeeds with a new ETag', function () {
    $payload = ($this->invoicePayload)();
    $id = postJson('api/v1/invoices', $payload)->assertOk()->json('data.id');
    $etag = getJson("api/v1/invoices/{$id}")->headers->get('ETag');

    $update = $payload + [];
    $update['invoice_number'] = Invoice::find($id)->invoice_number;
    $update['notes'] = 'first edit';
    $ok = putJson("api/v1/invoices/{$id}", $update, ['If-Match' => $etag])->assertOk();
    expect($ok->headers->get('ETag'))->not->toBe($etag);

    $update['notes'] = 'second edit from a stale read';
    putJson("api/v1/invoices/{$id}", $update, ['If-Match' => $etag])
        ->assertStatus(412)->assertJsonPath('code', 'precondition_failed');
    expect(Invoice::find($id)->notes)->toBe('first edit');
});

test('the web UI may omit If-Match but an integration token must send it', function () {
    $payload = ($this->invoicePayload)();
    $id = postJson('api/v1/invoices', $payload)->assertOk()->json('data.id');

    Sanctum::actingAs($this->user, ['*']);
    $token = postJson('api/v1/integration-tokens', ['name' => 'etag', 'abilities' => ['documents:read', 'documents:write', 'documents:issue']])
        ->assertCreated()->json('data.token');
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$token}"]);

    $etag = getJson("api/v1/invoices/{$id}")->assertOk()->headers->get('ETag');

    postJson("api/v1/invoices/{$id}/issue")->assertStatus(428)->assertJsonPath('code', 'precondition_required');
    postJson("api/v1/invoices/{$id}/issue", [], ['If-Match' => '"nope"'])->assertStatus(412);
    postJson("api/v1/invoices/{$id}/issue", [], ['If-Match' => $etag])->assertOk();
});

test('estimates and customers carry ETags too', function () {
    $estimate = postJson('api/v1/estimates', Estimate::factory()->raw([
        'items' => [EstimateItem::factory()->raw()],
        'taxes' => [Tax::factory()->raw()],
    ]))->assertCreated();
    expect($estimate->headers->get('ETag'))->toBeNull();

    $id = $estimate->json('data.id');
    expect(getJson("api/v1/estimates/{$id}")->headers->get('ETag'))->toStartWith('"');

    $customer = Customer::factory()->create(['company_id' => $this->company->id]);
    expect(getJson("api/v1/customers/{$customer->id}")->headers->get('ETag'))->toStartWith('"');
});
