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

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();

    $this->mint = function (array $abilities, string $name = 'signage') {
        Sanctum::actingAs($this->user, ['*']);
        $response = postJson('api/v1/integration-tokens', ['name' => $name, 'abilities' => $abilities], ['company' => $this->company->id])->assertCreated();
        app('auth')->forgetGuards();
        $this->app['auth']->forgetGuards();

        return $response->json('data.token');
    };

    $this->asToken = fn (string $token) => $this->withHeaders(['Authorization' => "Bearer {$token}", 'company' => 999999]);
});

test('the token is shown once, stored hashed and bound to the company', function () {
    $plain = ($this->mint)(['documents:read']);

    expect($plain)->toContain('|');
    $row = $this->user->tokens()->first();
    expect($row->token)->not->toBe($plain)->and(strlen($row->token))->toBe(64)
        ->and($row->abilities)->toContain('documents:read', 'company:'.$this->company->id);

    Sanctum::actingAs($this->user, ['*']);
    getJson('api/v1/integration-tokens', ['company' => $this->company->id])
        ->assertOk()->assertJsonPath('data.0.name', 'signage')->assertJsonMissingPath('data.0.token');
});

test('ability matrix: read, write, issue, events, webhooks, analytics', function (string $ability, string $method, string $uri, int $expected) {
    $plain = ($this->mint)([$ability]);
    ($this->asToken)($plain);

    $response = $this->json($method, $uri);

    expect($response->status())->toBe($expected);
})->with([
    'read lists with documents:read' => ['documents:read', 'GET', 'api/v1/invoices', 200],
    'read cannot create' => ['documents:read', 'POST', 'api/v1/customers', 403],
    'write cannot read' => ['documents:write', 'GET', 'api/v1/invoices', 403],
    'write cannot issue' => ['documents:write', 'POST', 'api/v1/invoices/1/issue', 403],
    'issue ability reaches the issue endpoint' => ['documents:issue', 'POST', 'api/v1/invoices/999999/issue', 404],
    'events:read reads the feed' => ['events:read', 'GET', 'api/v1/events', 200],
    'documents:read cannot read the feed' => ['documents:read', 'GET', 'api/v1/events', 403],
    'webhooks:manage lists endpoints' => ['webhooks:manage', 'GET', 'api/v1/webhook-endpoints', 200],
    'analytics:read alone reaches no document route' => ['analytics:read', 'GET', 'api/v1/invoices', 403],
    'scoped token cannot mint tokens' => ['documents:write', 'POST', 'api/v1/integration-tokens', 403],
    'scoped token cannot open settings' => ['documents:read', 'GET', 'api/v1/company-invitations', 403],
]);

test('the company comes from the token, not the header', function () {
    $plain = ($this->mint)(['documents:read']);
    ($this->asToken)($plain);

    $this->getJson('api/v1/invoices')->assertOk();
    $this->getJson('api/v1/current-company')->assertOk()->assertJsonPath('data.id', $this->company->id);
});

test('a revoked or expired token is refused', function () {
    $plain = ($this->mint)(['documents:read']);
    $id = $this->user->tokens()->first()->id;

    Sanctum::actingAs($this->user, ['*']);
    $this->deleteJson("api/v1/integration-tokens/{$id}", [], ['company' => $this->company->id])->assertOk();
    app('auth')->forgetGuards();
    ($this->asToken)($plain);

    $this->getJson('api/v1/invoices')->assertUnauthorized();
});

test('invalid abilities are rejected at creation', function () {
    Sanctum::actingAs($this->user, ['*']);

    postJson('api/v1/integration-tokens', ['name' => 'x', 'abilities' => ['*']], ['company' => $this->company->id])->assertStatus(422);
    postJson('api/v1/integration-tokens', ['name' => 'x', 'abilities' => ['documents:root']], ['company' => $this->company->id])->assertStatus(422);
});

test('403 is problem json with a stable code', function () {
    $plain = ($this->mint)(['documents:read']);
    ($this->asToken)($plain);

    $this->postJson('api/v1/customers', [])->assertForbidden()
        ->assertJsonPath('code', 'insufficient_ability');
});

test('external_ref: create, find and uniqueness on estimates, invoices and customers', function () {
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeaders(['company' => $this->company->id]);

    $estimate = Estimate::factory()->raw([
        'estimate_number' => 'EST-900001',
        'external_ref' => ['source' => 'signage', 'id' => 'Q-123'],
        'items' => [EstimateItem::factory()->raw()],
        'taxes' => [Tax::factory()->raw()],
    ]);
    postJson('api/v1/estimates', $estimate)->assertCreated()
        ->assertJsonPath('data.external_ref.source', 'signage')
        ->assertJsonPath('data.external_ref.id', 'Q-123')
        ->assertJsonPath('data.payment_reference', 'EST-900001');

    getJson('api/v1/estimates?external_ref=signage:Q-123')->assertOk()->assertJsonCount(1, 'data');
    getJson('api/v1/estimates?external_ref=signage:nope')->assertOk()->assertJsonCount(0, 'data');

    $dup = Estimate::factory()->raw([
        'estimate_number' => 'EST-900002',
        'external_ref' => ['source' => 'signage', 'id' => 'Q-123'],
        'items' => [EstimateItem::factory()->raw()],
        'taxes' => [Tax::factory()->raw()],
    ]);
    postJson('api/v1/estimates', $dup)->assertStatus(422)->assertJsonValidationErrors(['external_ref.id']);

    $sameIdOtherSource = $dup;
    $sameIdOtherSource['external_ref']['source'] = 'crm';
    postJson('api/v1/estimates', $sameIdOtherSource)->assertCreated();

    $invoice = Invoice::factory()->raw([
        'external_ref' => ['source' => 'signage', 'id' => 'INV-9'],
        'taxes' => [Tax::factory()->raw()],
        'items' => [InvoiceItem::factory()->raw()],
    ]);
    $id = postJson('api/v1/invoices', $invoice)->assertOk()->assertJsonPath('data.external_ref.id', 'INV-9')->json('data.id');
    getJson('api/v1/invoices?external_ref=signage:INV-9')->assertOk()->assertJsonPath('data.0.id', $id);

    $customer = Customer::factory()->raw(['external_ref' => ['source' => 'signage', 'id' => 'C-1'], 'email' => 'ext1@example.test']);
    postJson('api/v1/customers', $customer)->assertOk()->assertJsonPath('data.external_ref.id', 'C-1');
    getJson('api/v1/customers?external_ref=signage:C-1')->assertOk()->assertJsonCount(1, 'data');
});

test('payment_reference on an issued invoice falls back to the invoice number', function () {
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeaders(['company' => $this->company->id]);

    $payload = Invoice::factory()->raw([
        'reference_number' => null,
        'taxes' => [Tax::factory()->raw()],
        'items' => [InvoiceItem::factory()->raw()],
    ]);
    $id = postJson('api/v1/invoices', $payload)->assertOk()->assertJsonPath('data.payment_reference', null)->json('data.id');

    $issued = postJson("api/v1/invoices/{$id}/issue")->assertOk();
    $issued->assertJsonPath('data.payment_reference', $issued->json('data.invoice_number'));
});

test('updated_since filters lists', function () {
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeaders(['company' => $this->company->id]);

    getJson('api/v1/invoices?updated_since='.urlencode(now()->addDay()->toIso8601String()))->assertOk()->assertJsonCount(0, 'data');
    getJson('api/v1/invoices?updated_since='.urlencode(now()->subYear()->toIso8601String()))->assertOk();
});
