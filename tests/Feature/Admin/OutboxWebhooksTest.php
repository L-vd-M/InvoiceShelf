<?php

use App\Jobs\DeliverWebhookJob;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Tax;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Document\InvoiceService;
use App\Services\Events\WebhookDeliveryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->user = User::find(1);
    $this->company = $this->user->companies()->first();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);

    $this->draft = fn (): int => postJson('api/v1/invoices', Invoice::factory()->raw([
        'company_id' => $this->company->id,
        'taxes' => [Tax::factory()->raw()],
        'items' => [InvoiceItem::factory()->raw()],
    ]))->assertOk()->json('data.id');

    $this->endpoint = fn (array $over = []) => WebhookEndpoint::create($over + [
        'company_id' => $this->company->id,
        'url' => 'https://hooks.example.com/in',
        'secret' => 'whsec_'.base64_encode('0123456789abcdef0123456789abcdef'),
        'event_types' => null,
        'enabled' => true,
    ]);

    $this->events = fn (?string $type = null) => OutboxEvent::when($type, fn ($q) => $q->where('type', $type))->orderBy('id')->get();

    $this->makeInvoice = function () {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->company->id,
            'status' => Invoice::STATUS_SENT,
            'issued_at' => now(),
            'paid_status' => Invoice::STATUS_UNPAID,
            'sub_total' => 30000, 'total' => 30000, 'tax' => 0, 'discount_val' => 0, 'discount' => 0,
            'due_amount' => 30000, 'credited_total' => 0,
        ]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'company_id' => $this->company->id, 'name' => 'Mount', 'quantity' => 2, 'price' => 10000, 'total' => 20000, 'discount_type' => 'fixed', 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'base_price' => 0, 'base_total' => 0, 'base_tax' => 0, 'base_discount_val' => 0, 'exchange_rate' => 1]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'company_id' => $this->company->id, 'name' => 'Cable', 'quantity' => 1, 'price' => 10000, 'total' => 10000, 'discount_type' => 'fixed', 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'base_price' => 0, 'base_total' => 0, 'base_tax' => 0, 'base_discount_val' => 0, 'exchange_rate' => 1]);

        return $invoice;
    };
});

// ---------------------------------------------------------------- outbox rows

test('issuing writes exactly one document.issued event, a second issue writes none', function () {
    $id = ($this->draft)();
    expect(($this->events)())->toHaveCount(0);

    postJson("api/v1/invoices/{$id}/issue")->assertOk();
    postJson("api/v1/invoices/{$id}/issue")->assertOk();

    $events = ($this->events)('document.issued');
    expect($events)->toHaveCount(1);

    $invoice = Invoice::find($id);
    $envelope = $events[0]->envelope();
    expect($envelope['type'])->toBe('document.issued')
        ->and($envelope['version'])->toBe(1)
        ->and($envelope['source'])->toBe('documents')
        ->and($envelope['tenant'])->toBe('tarcza')
        ->and($envelope['subject'])->toBe(['kind' => 'invoice', 'id' => $invoice->invoice_number, 'ref' => (string) $id])
        ->and($envelope['data']['number'])->toBe($invoice->invoice_number)
        ->and($envelope['data']['total'])->toBe((int) $invoice->total)
        ->and($events[0]->event_id)->toStartWith('msg_')
        ->and($envelope['occurred_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/');
});

test('a rolled-back change leaves no event and no delivery', function () {
    ($this->endpoint)();
    $invoice = Invoice::find(($this->draft)());

    try {
        DB::transaction(function () use ($invoice) {
            app(InvoiceService::class)->issue($invoice);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxEvent::count())->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0)
        ->and(Invoice::find($invoice->id)->issued_at)->toBeNull();
});

test('deleting a draft writes document.deleted; a refused delete of an issued invoice writes nothing', function () {
    $draft = ($this->draft)();
    $issued = ($this->draft)();
    postJson("api/v1/invoices/{$issued}/issue")->assertOk();
    $before = OutboxEvent::count();

    postJson('api/v1/invoices/delete', ['ids' => [$issued], 'reason_code' => 'duplicate'])->assertStatus(409);
    expect(OutboxEvent::count())->toBe($before);

    postJson('api/v1/invoices/delete', ['ids' => [$draft], 'reason_code' => 'created_in_error'])->assertOk();

    $deleted = ($this->events)('document.deleted');
    expect($deleted)->toHaveCount(1)
        ->and($deleted[0]->data['reason_code'])->toBe('created_in_error')
        ->and($deleted[0]->subject_kind)->toBe('invoice')
        ->and($deleted[0]->subject_ref)->toBe($draft);
});

test('recording a payment writes payment.recorded', function () {
    $invoice = ($this->makeInvoice)();

    $payload = Payment::factory()->raw([
        'company_id' => $this->company->id,
        'invoice_id' => $invoice->id,
        'customer_id' => $invoice->customer_id,
        'payment_number' => 'PAY-000777',
        'amount' => 10000,
        'exchange_rate' => 1,
    ]);
    postJson('api/v1/payments', $payload)->assertOk();

    $events = ($this->events)('payment.recorded');
    expect($events)->toHaveCount(1)
        ->and($events[0]->data['amount'])->toBe(10000)
        ->and($events[0]->data['invoice_number'])->toBe($invoice->invoice_number)
        ->and($events[0]->subject_kind)->toBe('payment');
});

test('a full credit note writes issued and voided, a partial one only issued', function () {
    $full = ($this->makeInvoice)();
    postJson("api/v1/invoices/{$full->id}/credit-notes", ['reason_code' => 'billing_error'])->assertCreated();

    expect(($this->events)('document.issued'))->toHaveCount(1)
        ->and(($this->events)('document.issued')[0]->subject_kind)->toBe('credit_note')
        ->and(($this->events)('document.voided'))->toHaveCount(1)
        ->and(($this->events)('document.voided')[0]->subject_ref)->toBe($full->id);

    $partial = ($this->makeInvoice)();
    $line = InvoiceItem::where('invoice_id', $partial->id)->orderBy('id')->first();
    postJson("api/v1/invoices/{$partial->id}/credit-notes", [
        'reason_code' => 'goodwill',
        'items' => [['invoice_item_id' => $line->id, 'quantity' => 1]],
    ])->assertCreated();

    expect(($this->events)('document.issued'))->toHaveCount(2)
        ->and(($this->events)('document.voided'))->toHaveCount(1);
});

test('accepting a quote writes quote.accepted once', function () {
    $estimate = Estimate::factory()->create(['company_id' => $this->company->id, 'status' => Estimate::STATUS_SENT]);

    postJson("api/v1/estimates/{$estimate->id}/status", ['status' => Estimate::STATUS_ACCEPTED])->assertOk();
    postJson("api/v1/estimates/{$estimate->id}/status", ['status' => Estimate::STATUS_ACCEPTED])->assertOk();

    $events = ($this->events)('quote.accepted');
    expect($events)->toHaveCount(1)
        ->and($events[0]->subject_kind)->toBe('estimate')
        ->and($events[0]->subject_id)->toBe($estimate->estimate_number);

    postJson("api/v1/estimates/{$estimate->id}/status", ['status' => Estimate::STATUS_REJECTED])->assertOk();
    expect(($this->events)('quote.accepted'))->toHaveCount(1);
});

test('outbox events are append-only', function () {
    postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();
    $event = OutboxEvent::first();

    expect(fn () => $event->update(['type' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

test('deliveries are created only for enabled endpoints that subscribe to the type', function () {
    $all = ($this->endpoint)();
    $issuedOnly = ($this->endpoint)(['event_types' => ['document.issued']]);
    $paymentOnly = ($this->endpoint)(['event_types' => ['payment.recorded']]);
    $off = ($this->endpoint)(['enabled' => false]);

    postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();

    $ids = WebhookDelivery::pluck('webhook_endpoint_id')->all();
    expect($ids)->toContain($all->id, $issuedOnly->id)
        ->and($ids)->not->toContain($paymentOnly->id, $off->id);
});

// ---------------------------------------------------------------- signing and delivery

test('the signature matches an independent Standard Webhooks computation', function () {
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
    $endpoint = ($this->endpoint)();
    postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();

    $delivery = WebhookDelivery::first();
    expect(app(WebhookDeliveryService::class)->deliver($delivery))->toBeTrue();

    Http::assertSent(function ($request) use ($endpoint) {
        $id = $request->header('webhook-id')[0];
        $ts = $request->header('webhook-timestamp')[0];
        $sig = $request->header('webhook-signature')[0];

        // reference algorithm from the Standard Webhooks spec, written out here on purpose
        $key = base64_decode(substr('whsec_'.base64_encode('0123456789abcdef0123456789abcdef'), 6));
        $expected = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.".$request->body(), $key, true));

        return $request->url() === $endpoint->url
            && str_starts_with($id, 'msg_')
            && abs((int) $ts - time()) < 60
            && hash_equals($expected, $sig)
            && json_decode($request->body(), true)['type'] === 'document.issued';
    });

    $delivery->refresh();
    expect($delivery->state)->toBe('delivered')->and($delivery->attempts)->toBe(1)->and($delivery->delivered_at)->not->toBeNull();
});

test('backoff schedule grows to a day and repeats', function () {
    expect(array_map(fn ($n) => WebhookDeliveryService::delay($n), [1, 2, 3, 4, 5, 6, 7, 8, 12]))
        ->toBe([60, 300, 1800, 7200, 21600, 43200, 86400, 86400, 86400]);
});

test('failures back off, then dead-letter after 72 hours, and retry puts the delivery back', function () {
    Carbon::setTestNow('2026-10-16 08:00:00');
    $answer = 500;
    Http::fake(['hooks.example.com/*' => function () use (&$answer) {
        return Http::response('nope', $answer);
    }]);
    ($this->endpoint)();
    postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();
    $delivery = WebhookDelivery::first();
    $service = app(WebhookDeliveryService::class);

    $service->deliver($delivery);
    $delivery->refresh();
    expect($delivery->state)->toBe('pending')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status)->toBe(500)
        ->and($delivery->next_attempt_at->toDateTimeString())->toBe('2026-10-16 08:01:00');

    // not yet due: nothing happens
    expect($service->deliver($delivery))->toBeFalse();
    expect($delivery->refresh()->attempts)->toBe(1);

    // walk through the schedule until the 72 h window is used up
    $guard = 0;
    while ($delivery->refresh()->state === 'pending' && $guard++ < 30) {
        Carbon::setTestNow($delivery->next_attempt_at->copy()->addSecond());
        $service->deliver($delivery);
    }

    $delivery->refresh();
    expect($delivery->state)->toBe('dead')
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->attempts)->toBeLessThan(12)
        ->and(now()->diffInHours(Carbon::parse('2026-10-16 08:00:00'), true))->toBeLessThanOrEqual(72);

    // dead letter list and retry
    $list = getJson('api/v1/webhook-deliveries?state=failed')->assertOk();
    expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.state'))->toBe('failed');

    $answer = 204;
    postJson("api/v1/webhook-deliveries/{$delivery->id}/retry")->assertOk()->assertJsonPath('data.state', 'pending');
    $service->deliver($delivery->refresh());
    expect($delivery->refresh()->state)->toBe('delivered');

    postJson("api/v1/webhook-deliveries/{$delivery->id}/retry")->assertStatus(409);
    Carbon::setTestNow();
});

test('the scheduler command queues only due pending deliveries', function () {
    Queue::fake();
    ($this->endpoint)();
    postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();
    postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();
    WebhookDelivery::orderByDesc('id')->first()->update(['next_attempt_at' => now()->addHour()]);

    Artisan::call('webhooks:deliver');

    Queue::assertPushed(DeliverWebhookJob::class, 1);
});

test('replaying a delivery is idempotent for a consumer that dedupes on webhook-id', function () {
    $stored = [];
    $calls = 0;
    Http::fake(['hooks.example.com/*' => function ($request) use (&$stored, &$calls) {
        $calls++;
        $stored[$request->header('webhook-id')[0]] = $request->body();

        // the first answer is lost after the consumer stored the event
        return Http::response('', $calls === 1 ? 502 : 200);
    }]);
    ($this->endpoint)();
    postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();
    $delivery = WebhookDelivery::first();
    $service = app(WebhookDeliveryService::class);

    $service->deliver($delivery);
    Carbon::setTestNow(now()->addMinutes(2));
    $service->deliver($delivery->refresh());

    expect($calls)->toBe(2)
        ->and($stored)->toHaveCount(1)
        ->and(array_key_first($stored))->toBe(OutboxEvent::first()->event_id)
        ->and($delivery->refresh()->state)->toBe('delivered');
    Carbon::setTestNow();
});

// ---------------------------------------------------------------- SSRF

test('endpoint urls that target private or plain-http hosts are refused', function (string $url) {
    postJson('api/v1/webhook-endpoints', ['url' => $url])->assertStatus(422)->assertJsonValidationErrors('url');
    expect(WebhookEndpoint::count())->toBe(0);
})->with([
    'loopback' => 'https://127.0.0.1/hook',
    'rfc1918' => 'https://10.0.0.5/hook',
    'lan' => 'https://192.168.0.30/hook',
    'metadata' => 'https://169.254.169.254/latest',
    'ipv6 loopback' => 'https://[::1]/hook',
    'plain http' => 'http://hooks.example.com/hook',
    'ftp' => 'ftp://hooks.example.com/hook',
]);

test('an allowlisted LAN host may be used over http', function () {
    config(['invoiceshelf.webhook_allowed_hosts' => ['192.168.0.30']]);

    postJson('api/v1/webhook-endpoints', ['url' => 'http://192.168.0.30:8000/events'])->assertCreated();
    postJson('api/v1/webhook-endpoints', ['url' => 'http://192.168.0.31/events'])->assertStatus(422);
});

test('delivery refuses a target that became private after it was saved', function () {
    Http::fake();
    $endpoint = ($this->endpoint)(['url' => 'https://10.1.2.3/hook']);
    postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();
    $delivery = WebhookDelivery::first();

    app(WebhookDeliveryService::class)->deliver($delivery);

    Http::assertNothingSent();
    expect($delivery->refresh()->state)->toBe('pending')->and($delivery->last_error)->toContain('private');
});

// ---------------------------------------------------------------- management API

test('the secret is shown once on create and on rotate, never listed', function () {
    $created = postJson('api/v1/webhook-endpoints', ['url' => 'https://hooks.example.com/in', 'event_types' => ['document.issued']])->assertCreated();
    $secret = $created->json('data.secret');
    expect($secret)->toStartWith('whsec_');

    $list = getJson('api/v1/webhook-endpoints')->assertOk();
    expect(json_encode($list->json()))->not->toContain($secret)->and($list->json('data.0'))->not->toHaveKey('secret');

    $id = $created->json('data.id');
    $rotated = postJson("api/v1/webhook-endpoints/{$id}/rotate")->assertOk()->json('data.secret');
    expect($rotated)->not->toBe($secret)->and(WebhookEndpoint::find($id)->secret)->toBe($rotated);

    // stored encrypted
    expect(DB::table('webhook_endpoints')->where('id', $id)->value('secret'))->not->toBe($rotated);

    patchJson("api/v1/webhook-endpoints/{$id}", ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    postJson('api/v1/webhook-endpoints', ['url' => 'https://hooks.example.com/in', 'event_types' => ['bogus.type']])->assertStatus(422);
    deleteJson("api/v1/webhook-endpoints/{$id}")->assertOk();
    expect(WebhookEndpoint::count())->toBe(0);
});

test('management needs the owner and the right token ability', function () {
    Sanctum::actingAs($this->user, ['events:read']);
    getJson('api/v1/webhook-endpoints')->assertForbidden();
    postJson('api/v1/webhook-endpoints', ['url' => 'https://hooks.example.com/in'])->assertForbidden();
    getJson('api/v1/events')->assertOk();

    Sanctum::actingAs($this->user, ['webhooks:manage']);
    getJson('api/v1/webhook-endpoints')->assertOk();
    getJson('api/v1/events')->assertForbidden();
});

test('another company cannot see or touch an endpoint', function () {
    $endpoint = ($this->endpoint)(['company_id' => $this->company->id + 999]);

    patchJson("api/v1/webhook-endpoints/{$endpoint->id}", ['enabled' => false])->assertNotFound();
    postJson("api/v1/webhook-endpoints/{$endpoint->id}/rotate")->assertNotFound();
    expect(getJson('api/v1/webhook-endpoints')->json('data'))->toBeEmpty();
});

// ---------------------------------------------------------------- pull feed

test('the feed pages by cursor, filters by type and only shows this company', function () {
    foreach (range(1, 3) as $i) {
        postJson('api/v1/invoices/'.($this->draft)().'/issue')->assertOk();
    }
    $draft = ($this->draft)();
    postJson('api/v1/invoices/delete', ['ids' => [$draft], 'reason_code' => 'duplicate'])->assertOk();
    OutboxEvent::create(['event_id' => 'msg_other', 'company_id' => $this->company->id + 999, 'type' => 'document.issued', 'version' => 1, 'subject_kind' => 'invoice', 'data' => [], 'occurred_at' => now()]);

    $page1 = getJson('api/v1/events?limit=2')->assertOk();
    expect($page1->json('data'))->toHaveCount(2)->and($page1->json('has_more'))->toBeTrue();

    $page2 = getJson('api/v1/events?limit=2&after='.$page1->json('next_cursor'))->assertOk();
    expect($page2->json('data'))->toHaveCount(2)->and($page2->json('has_more'))->toBeFalse();

    $ids = array_merge(array_column($page1->json('data'), 'id'), array_column($page2->json('data'), 'id'));
    expect($ids)->toHaveCount(4)->and(array_unique($ids))->toHaveCount(4)->and($ids)->not->toContain('msg_other');

    $empty = getJson('api/v1/events?after='.$page2->json('next_cursor'))->assertOk();
    expect($empty->json('data'))->toBeEmpty()->and($empty->json('next_cursor'))->toBe($page2->json('next_cursor'));

    $deletedOnly = getJson('api/v1/events?types=document.deleted')->assertOk();
    expect($deletedOnly->json('data'))->toHaveCount(1)->and($deletedOnly->json('data.0.type'))->toBe('document.deleted');
});

// ---------------------------------------------------------------- migration

test('the migration can go down and up again', function () {
    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_16_090000_create_outbox_and_webhook_tables.php', '--force' => true]);
    expect(Schema::hasTable('outbox_events'))->toBeFalse();

    Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_16_090000_create_outbox_and_webhook_tables.php', '--force' => true]);
    expect(Schema::hasTable('webhook_deliveries'))->toBeTrue();
});
