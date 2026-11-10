<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OutboxEvent;
use App\Models\Tax;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
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

    $this->issued = function (): int {
        $id = postJson('api/v1/invoices', Invoice::factory()->raw([
            'taxes' => [Tax::factory()->raw()],
            'items' => [InvoiceItem::factory()->raw(['price' => 10000, 'quantity' => 1, 'discount' => 0, 'discount_val' => 0, 'tax' => 0])],
        ]))->assertOk()->json('data.id');
        postJson("api/v1/invoices/{$id}/issue")->assertOk();

        return $id;
    };
});

test('health needs no login and reports database, queue, outbox and disk', function () {
    app('auth')->forgetGuards();
    $this->flushHeaders();

    $response = getJson('api/v1/health')->assertOk()->assertJsonPath('status', 'ok')
        ->assertJsonPath('database.ok', true)->assertJsonPath('outbox.dead_letters', 0);
    expect($response->json('disk.free_percent'))->toBeNumeric()->and($response->json('queue.driver'))->toBeString();
});

test('health degrades with a dead letter or a delivery stuck for 15 minutes', function () {
    $endpoint = WebhookEndpoint::create(['company_id' => $this->company->id, 'url' => 'https://hooks.example.com/in', 'secret' => 'whsec_x', 'enabled' => true, 'event_types' => []]);
    ($this->issued)();
    $delivery = WebhookDelivery::first();
    expect($delivery)->not->toBeNull();

    $delivery->update(['next_attempt_at' => now()->subMinutes(30)]);
    getJson('api/v1/health')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('outbox.ok', false);

    $delivery->update(['state' => WebhookDelivery::DEAD]);
    getJson('api/v1/health')->assertJsonPath('status', 'degraded')->assertJsonPath('outbox.dead_letters', 1);
});

test('version reports version, git sha and migration head', function () {
    app('auth')->forgetGuards();
    $this->flushHeaders();

    getJson('api/v1/version')->assertOk()->assertJsonStructure(['version', 'git_sha', 'migration_head']);
});

test('audit log lists deletions with reason, revisions and token creations, filtered by type and date', function () {
    $draft = postJson('api/v1/invoices', Invoice::factory()->raw(['taxes' => [Tax::factory()->raw()], 'items' => [InvoiceItem::factory()->raw()]]))->assertOk()->json('data.id');
    postJson('api/v1/invoices/delete', ['ids' => [$draft], 'reason_code' => 'duplicate'])->assertOk();
    postJson('api/v1/integration-tokens', ['name' => 'signage', 'abilities' => ['documents:read']])->assertCreated();

    $all = getJson('api/v1/audit-log')->assertOk();
    $types = collect($all->json('data'))->pluck('type')->unique()->sort()->values()->all();
    expect($types)->toBe(['deletion', 'revision', 'token']);

    $deletion = getJson('api/v1/audit-log?type=deletion')->assertOk()->json('data.0');
    expect($deletion['detail']['reason_code'])->toBe('duplicate')->and($deletion['subject']['kind'])->toBe('invoice');

    $token = getJson('api/v1/audit-log?type=token')->assertOk()->json('data');
    expect($token)->toHaveCount(1)->and($token[0]['subject']['id'])->toBe('signage')->and(json_encode($token))->not->toContain('company:');

    getJson('api/v1/audit-log?from='.now()->addDay()->format('Y-m-d'))->assertOk()->assertJsonCount(0, 'data');
    getJson('api/v1/audit-log?type=nope')->assertStatus(422);
    getJson('api/v1/audit-log?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('next_cursor', '1');
});

test('export: json with totals, hashes and a verifiable signed manifest; csv with digest', function () {
    $id = ($this->issued)();
    postJson("api/v1/invoices/{$id}/credit-notes", ['reason_code' => 'billing_error'])->assertCreated();
    $today = '2100-01-01';

    $json = getJson("api/v1/exports/documents?from=1900-01-01&to={$today}")->assertOk();
    expect($json->json('count'))->toBe(2)->and($json->json('documents.0.pdf_sha256'))->toMatch('/^[0-9a-f]{64}$/');
    $currency = $json->json('documents.0.currency');
    $totals = $json->json("totals.{$currency}");
    expect($totals['invoices'])->toBe(1)->and($totals['credit_notes'])->toBe(1)->and($totals['net_minor'])->toBe(0);

    $manifest = $json->json('manifest');
    $signature = $manifest['signature'];
    unset($manifest['signature']);
    $key = hash_hmac('sha256', 'documents-export', (string) config('app.key'), true);
    expect($signature)->toBe('v1,'.base64_encode(hash_hmac('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES), $key, true)));

    $csv = $this->get("api/v1/exports/documents?from=1900-01-01&to={$today}&format=csv")->assertOk();
    expect($csv->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($csv->headers->get('Digest'))->toBe('sha-256='.base64_encode(hash('sha256', $csv->getContent(), true)))
        ->and($csv->headers->get('X-Total-Count'))->toBe('2')
        ->and(substr_count(trim($csv->getContent()), "\n"))->toBe(2);

    getJson('api/v1/exports/documents?from=2026-02-01&to=2026-01-01')->assertStatus(422);
    expect(OutboxEvent::count())->toBeGreaterThan(0);
});

test('a scoped read token may read the audit log and export, a write-only token may not', function () {
    app('auth')->forgetGuards();
    $read = $this->user->createToken('integration:r', ['documents:read', 'company:'.$this->company->id])->plainTextToken;
    $write = $this->user->createToken('integration:w', ['documents:write', 'company:'.$this->company->id])->plainTextToken;
    app('auth')->forgetGuards();
    $this->flushHeaders();

    $this->withHeaders(['Authorization' => "Bearer {$read}"])->getJson('api/v1/audit-log')->assertOk();
    app('auth')->forgetGuards();
    $this->withHeaders(['Authorization' => "Bearer {$write}"])->getJson('api/v1/audit-log')->assertStatus(403);
});
