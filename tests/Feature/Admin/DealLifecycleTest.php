<?php

use App\Models\CompanySetting;
use App\Models\DealLifecycle;
use App\Models\DocumentRevision;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\User;
use App\Services\Document\DealLifecycleService;
use App\Services\Document\QuoteToInvoiceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
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

    // one line, no tax, no discount: the server total is exactly price x quantity
    $this->quote = fn (int $price, int $qty = 1, array $extra = []) => array_merge(Estimate::factory()->raw([
        'items' => [array_merge(EstimateItem::factory()->raw(), [
            'item_id' => null, 'name' => 'Screen mount', 'description' => null, 'discount_type' => 'fixed',
            'price' => $price, 'quantity' => $qty, 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'total' => $price * $qty,
        ])],
        'taxes' => [],
        'discount' => 0,
        'discount_val' => 0,
    ]), $extra);

    $this->create = fn (int $price = 20000) => postJson('api/v1/estimates', ($this->quote)($price))->assertStatus(201)->json('data.id');

    $this->edit = function (int $id, int $price, array $extra = []) {
        $customer = Estimate::find($id)->customer_id;
        putJson("api/v1/estimates/{$id}", ($this->quote)($price, 1, ['customer_id' => $customer] + $extra))->assertOk();
    };

    $this->status = fn (int $id, string $status) => postJson("api/v1/estimates/{$id}/status", ['status' => $status])->assertOk();

    $this->events = fn (string $type) => OutboxEvent::where('type', $type)->orderBy('id')->get();

    $this->reasons = fn (int $id) => DocumentRevision::where('document_type', 'estimate')->where('document_id', $id)
        ->orderBy('revision_no')->pluck('reason_code')->all();

    $this->teammate = function () {
        $user = User::factory()->create();
        $user->companies()->attach($this->company->id);

        return $user;
    };
});

afterEach(fn () => Carbon::setTestNow());

test('a new quote gets an open lifecycle row owned by its creator', function () {
    $id = ($this->create)();

    $row = DealLifecycle::where('first_quote_id', $id)->first();

    expect($row)->not->toBeNull()
        ->and($row->outcome)->toBe('open')
        ->and($row->owner_user_id)->toBe($this->user->id)
        ->and($row->company_id)->toBe($this->company->id)
        ->and($row->first_sent_at)->toBeNull()
        ->and($row->accepted_at)->toBeNull()
        ->and($row->first_total_minor)->toBe(20000)
        ->and($row->revision_count)->toBe(0);

    getJson("api/v1/estimates/{$id}/lifecycle")->assertOk()
        ->assertJsonPath('data.quote_id', $id)
        ->assertJsonPath('data.outcome', 'open')
        ->assertJsonPath('data.owner.id', $this->user->id);

    expect(($this->events)('deal.won'))->toHaveCount(0)->and(($this->events)('deal.lost'))->toHaveCount(0);
});

test('a seeded negotiation produces the known answers', function () {
    Carbon::setTestNow('2026-11-01 09:00:00');
    $id = ($this->create)(20000);

    Carbon::setTestNow('2026-11-02 09:00:00');
    ($this->status)($id, Estimate::STATUS_SENT);

    // notes only: an edit, but no price moved
    Carbon::setTestNow('2026-11-03 09:00:00');
    ($this->edit)($id, 20000, ['notes' => 'Delivery in two weeks']);

    // the customer pushes back: R200 becomes R180
    Carbon::setTestNow('2026-11-04 09:00:00');
    ($this->edit)($id, 18000);

    Carbon::setTestNow('2026-11-05 09:00:00');
    ($this->status)($id, Estimate::STATUS_ACCEPTED);

    $row = DealLifecycle::where('first_quote_id', $id)->first();

    expect(($this->reasons)($id))->toBe(['created', 'sent', 'edited', 'edited', 'accepted'])
        ->and($row->outcome)->toBe('won')
        ->and($row->first_sent_at->toIso8601String())->toBe('2026-11-02T09:00:00+00:00')
        ->and($row->accepted_at->toIso8601String())->toBe('2026-11-05T09:00:00+00:00')
        ->and($row->revision_count)->toBe(2)
        ->and($row->price_change_count)->toBe(1)
        ->and($row->first_total_minor)->toBe(20000)
        ->and($row->final_total_minor)->toBe(18000)
        ->and($row->discount_pct_first_to_final)->toBe(10.0)
        ->and($row->invoice_id)->toBeNull()
        ->and($row->fully_paid_at)->toBeNull();

    $won = ($this->events)('deal.won');
    expect($won)->toHaveCount(1)
        ->and($won[0]->subject_ref)->toBe($id)
        ->and($won[0]->data['total'])->toBe(18000)
        ->and($won[0]->data['paid'])->toBeFalse();
});

test('edits before the first send are not negotiation', function () {
    $id = ($this->create)(20000);
    ($this->edit)($id, 25000);
    ($this->status)($id, Estimate::STATUS_SENT);

    $row = DealLifecycle::where('first_quote_id', $id)->first();

    expect($row->revision_count)->toBe(0)
        ->and($row->price_change_count)->toBe(0)
        ->and($row->first_total_minor)->toBe(25000);
});

test('deal.won is written once however often the status is set', function () {
    $id = ($this->create)();
    ($this->status)($id, Estimate::STATUS_ACCEPTED);
    ($this->status)($id, Estimate::STATUS_ACCEPTED);

    Artisan::call('deals:rebuild');

    expect(($this->events)('deal.won'))->toHaveCount(1);
});

test('an issued invoice and its payments fill the money timestamps', function () {
    $id = ($this->create)(30000);
    ($this->status)($id, Estimate::STATUS_ACCEPTED);
    $estimate = Estimate::find($id);

    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id, 'customer_id' => $estimate->customer_id, 'estimate_id' => $id,
        'status' => Invoice::STATUS_SENT, 'paid_status' => Invoice::STATUS_UNPAID,
        'issued_at' => '2026-11-04 10:00:00', 'total' => 30000, 'sub_total' => 30000, 'tax' => 0, 'due_amount' => 30000, 'credited_total' => 0,
    ]);

    $pay = fn (int $amount, string $date) => Payment::factory()->create([
        'company_id' => $this->company->id, 'customer_id' => $estimate->customer_id, 'invoice_id' => $invoice->id,
        'amount' => $amount, 'payment_date' => $date,
    ]);

    $pay(10000, '2026-11-05');

    $row = DealLifecycle::where('first_quote_id', $id)->first();
    expect($row->invoice_id)->toBe($invoice->id)
        ->and($row->invoiced_at->toIso8601String())->toBe('2026-11-04T10:00:00+00:00')
        ->and($row->first_paid_at->toDateString())->toBe('2026-11-05')
        ->and($row->fully_paid_at)->toBeNull()
        ->and($row->final_total_minor)->toBe(30000);

    $second = $pay(20000, '2026-11-09');

    $row->refresh();
    expect($row->first_paid_at->toDateString())->toBe('2026-11-05')
        ->and($row->fully_paid_at->toDateString())->toBe('2026-11-09');

    // deleting the payment that completed it undoes fully_paid_at
    $second->delete();
    expect($row->refresh()->fully_paid_at)->toBeNull();
});

test('a paid quote issued through the quote flow is won, invoiced and fully paid', function () {
    $id = ($this->create)(30000);
    ($this->status)($id, Estimate::STATUS_ACCEPTED);

    $result = app(QuoteToInvoiceService::class)->issueInvoiceForPaidQuote(
        Estimate::find($id), ['payment_date' => '2026-11-10', 'amount' => 30000], $this->user->id, false,
    );

    $row = DealLifecycle::where('first_quote_id', $id)->first();

    expect($row->outcome)->toBe('won')
        ->and($row->invoice_id)->toBe($result['invoice']->id)
        ->and($row->invoiced_at)->not->toBeNull()
        ->and($row->fully_paid_at->toDateString())->toBe('2026-11-10')
        ->and(($this->events)('deal.won'))->toHaveCount(1);
});

test('marking a quote lost records the reason, closes it and writes deal.lost once', function () {
    $id = ($this->create)();
    ($this->status)($id, Estimate::STATUS_SENT);

    postJson("api/v1/estimates/{$id}/lost", ['reason_code' => 'price', 'note' => 'wants 10% off'])->assertOk()
        ->assertJsonPath('data.outcome', 'lost')
        ->assertJsonPath('data.lost_reason', 'price');

    $estimate = Estimate::find($id);
    $revision = DocumentRevision::where('document_type', 'estimate')->where('document_id', $id)->orderByDesc('revision_no')->first();

    expect($estimate->status)->toBe(Estimate::STATUS_REJECTED)
        ->and($estimate->lost_reason)->toBe('price')
        ->and($estimate->lost_at)->not->toBeNull()
        ->and($revision->reason_code)->toBe('lost')
        ->and($revision->note)->toBe('price: wants 10% off')
        ->and($revision->snapshot['lost_reason'])->toBe('price')
        ->and($revision->snapshot['status'])->toBe('REJECTED')
        ->and($revision->hashMatches())->toBeTrue();

    $lost = ($this->events)('deal.lost');
    expect($lost)->toHaveCount(1)
        ->and($lost[0]->data['reason_code'])->toBe('price')
        ->and($lost[0]->data['first_total'])->toBe(20000);

    // same call again: nothing new
    $count = DocumentRevision::count();
    postJson("api/v1/estimates/{$id}/lost", ['reason_code' => 'price', 'note' => 'wants 10% off'])->assertOk();
    expect(DocumentRevision::count())->toBe($count)->and(($this->events)('deal.lost'))->toHaveCount(1);

    // a corrected reason is a new revision but not a second loss
    postJson("api/v1/estimates/{$id}/lost", ['reason_code' => 'competitor'])->assertOk()->assertJsonPath('data.lost_reason', 'competitor');
    expect(($this->reasons)($id))->toBe(['created', 'sent', 'lost', 'lost'])
        ->and(($this->events)('deal.lost'))->toHaveCount(1);
});

test('a won deal cannot be marked lost and an unknown reason is refused', function () {
    $id = ($this->create)();
    postJson("api/v1/estimates/{$id}/lost", ['reason_code' => 'because'])->assertStatus(422);

    ($this->status)($id, Estimate::STATUS_ACCEPTED);

    postJson("api/v1/estimates/{$id}/lost", ['reason_code' => 'price'])->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'deal_closed');

    expect(Estimate::find($id)->lost_at)->toBeNull()
        ->and(DealLifecycle::where('first_quote_id', $id)->value('outcome'))->toBe('won');
});

test('lost reasons default to the five codes and the company can replace them', function () {
    getJson('api/v1/deal-lost-reasons')->assertOk()
        ->assertJsonPath('data', ['price', 'timing', 'competitor', 'no_response', 'other']);

    CompanySetting::setSettings(['deal_lost_reasons' => json_encode(['too_slow', 'Bad Code', 'budget'])], $this->company->id);

    getJson('api/v1/deal-lost-reasons')->assertOk()->assertJsonPath('data', ['too_slow', 'budget']);

    $id = ($this->create)();
    postJson("api/v1/estimates/{$id}/lost", ['reason_code' => 'price'])->assertStatus(422);
    postJson("api/v1/estimates/{$id}/lost", ['reason_code' => 'budget'])->assertOk()->assertJsonPath('data.lost_reason', 'budget');
});

test('reassigning a quote writes a revision and a document.revised event, once', function () {
    $id = ($this->create)();
    $mate = ($this->teammate)();

    postJson("api/v1/estimates/{$id}/assign", ['user_id' => $mate->id])->assertOk()
        ->assertJsonPath('data.owner.id', $mate->id);

    $revision = DocumentRevision::where('document_type', 'estimate')->where('document_id', $id)->orderByDesc('revision_no')->first();
    expect($revision->reason_code)->toBe('assigned')
        ->and($revision->snapshot['owner_user_id'])->toBe($mate->id)
        ->and($revision->note)->toContain($mate->name)
        ->and(DealLifecycle::where('first_quote_id', $id)->value('owner_user_id'))->toBe($mate->id);

    $events = ($this->events)('document.revised');
    expect($events)->toHaveCount(1)
        ->and($events[0]->data['owner_user_id'])->toBe($mate->id)
        ->and($events[0]->data['previous_owner_user_id'])->toBe($this->user->id)
        ->and($events[0]->data['reason'])->toBe('assigned');

    // same owner again: nothing new
    $count = DocumentRevision::count();
    postJson("api/v1/estimates/{$id}/assign", ['user_id' => $mate->id])->assertOk();
    expect(DocumentRevision::count())->toBe($count)->and(($this->events)('document.revised'))->toHaveCount(1);

    // back to the creator is a real change
    postJson("api/v1/estimates/{$id}/assign", ['user_id' => $this->user->id])->assertOk()->assertJsonPath('data.owner.id', $this->user->id);
    expect(($this->reasons)($id))->toBe(['created', 'assigned', 'assigned']);
});

test('a quote cannot be assigned to a user outside the company', function () {
    $id = ($this->create)();
    $stranger = User::factory()->create();

    postJson("api/v1/estimates/{$id}/assign", ['user_id' => $stranger->id])->assertStatus(422);
    postJson("api/v1/estimates/{$id}/assign", ['user_id' => 999999])->assertStatus(422);

    expect(Estimate::find($id)->owner_user_id)->toBeNull();
});

test('a user outside the company can neither read nor change a deal', function () {
    $id = ($this->create)();

    Sanctum::actingAs(User::factory()->create(), ['*']);

    getJson("api/v1/estimates/{$id}/lifecycle")->assertForbidden();
    postJson("api/v1/estimates/{$id}/lost", ['reason_code' => 'price'])->assertForbidden();
    postJson("api/v1/estimates/{$id}/assign", ['user_id' => $this->user->id])->assertForbidden();

    expect(Estimate::find($id)->lost_at)->toBeNull();
});

test('a quote without a deal decision keeps a snapshot without the new fields and saving twice stays one revision', function () {
    $id = ($this->create)();
    ($this->edit)($id, 20000);

    $first = DocumentRevision::where('document_type', 'estimate')->where('document_id', $id)->orderBy('revision_no')->first();
    expect($first->snapshot)->not->toHaveKeys(['owner_user_id', 'lost_reason', 'lost_at']);

    $count = DocumentRevision::count();
    $customer = Estimate::find($id)->customer_id;
    $payload = ($this->quote)(20000, 1, ['customer_id' => $customer]);
    putJson("api/v1/estimates/{$id}", $payload)->assertOk();
    putJson("api/v1/estimates/{$id}", $payload)->assertOk();
    expect(DocumentRevision::count())->toBeLessThanOrEqual($count + 1);
});

test('rebuilding is idempotent, writes no events and repairs a damaged row', function () {
    $won = ($this->create)();
    ($this->status)($won, Estimate::STATUS_ACCEPTED);
    $lost = ($this->create)(15000);
    postJson("api/v1/estimates/{$lost}/lost", ['reason_code' => 'timing'])->assertOk();
    $open = ($this->create)(12000);

    $snapshot = fn () => DealLifecycle::orderBy('first_quote_id')->get()->map(fn ($row) => collect($row->getAttributes())->except(['id', 'built_at'])->all())->all();

    // the demo seeder wrote quotes without the hooks, so the first run adds their rows
    Artisan::call('deals:rebuild');
    $before = $snapshot();
    $events = OutboxEvent::count();

    Artisan::call('deals:rebuild');

    expect($snapshot())->toBe($before)->and(OutboxEvent::count())->toBe($events);

    DealLifecycle::where('first_quote_id', $won)->update(['outcome' => 'open', 'final_total_minor' => 1]);
    DealLifecycle::where('first_quote_id', $open)->delete();
    DealLifecycle::create(['first_quote_id' => 987654, 'company_id' => $this->company->id, 'outcome' => 'open']);

    Artisan::call('deals:rebuild', ['--company' => $this->company->id]);

    expect($snapshot())->toBe($before)
        ->and(DealLifecycle::where('first_quote_id', 987654)->exists())->toBeFalse()
        ->and(OutboxEvent::count())->toBe($events);
});

test('rebuild gives every existing quote a row, including ones made before the table', function () {
    $estimate = Estimate::factory()->create(['company_id' => $this->company->id, 'status' => Estimate::STATUS_ACCEPTED]);
    DealLifecycle::query()->delete();

    expect(DealLifecycleService::rebuildAll())->toBeGreaterThanOrEqual(1)
        ->and(DealLifecycle::where('first_quote_id', $estimate->id)->value('outcome'))->toBe('won')
        ->and(OutboxEvent::where('type', 'deal.won')->count())->toBe(0);
});

test('outcome rules: customer rejection is lost, expiry is expired, open stays open', function () {
    $rejected = ($this->create)();
    ($this->status)($rejected, Estimate::STATUS_REJECTED);

    $expiredByCommand = ($this->create)();
    ($this->status)($expiredByCommand, Estimate::STATUS_SENT);
    Estimate::whereKey($expiredByCommand)->update(['expiry_date' => '2020-01-01']);
    Artisan::call('check:estimates:status');

    $pastExpiry = ($this->create)();
    ($this->status)($pastExpiry, Estimate::STATUS_VIEWED);
    Estimate::whereKey($pastExpiry)->update(['expiry_date' => '2020-01-01']);
    Artisan::call('deals:rebuild');

    $draftOpen = ($this->create)();
    $sentOpen = ($this->create)();
    ($this->status)($sentOpen, Estimate::STATUS_SENT);
    Estimate::whereKey($sentOpen)->update(['expiry_date' => now()->addDays(10)->toDateString()]);
    Artisan::call('deals:rebuild');

    $outcome = fn (int $id) => DealLifecycle::where('first_quote_id', $id)->value('outcome');

    expect($outcome($rejected))->toBe('lost')
        ->and(DealLifecycle::where('first_quote_id', $rejected)->value('lost_reason'))->toBeNull()
        ->and($outcome($expiredByCommand))->toBe('expired')
        ->and($outcome($pastExpiry))->toBe('expired')
        ->and($outcome($draftOpen))->toBe('open')
        ->and($outcome($sentOpen))->toBe('open')
        ->and(($this->events)('deal.lost'))->toHaveCount(1);
});

test('the deal tables and columns exist and the migration rolls back', function () {
    expect(Schema::hasTable('deal_lifecycles'))->toBeTrue()
        ->and(Schema::hasColumns('estimates', ['owner_user_id', 'lost_reason', 'lost_at']))->toBeTrue()
        ->and(Schema::hasColumn('users', 'sales_team'))->toBeTrue();

    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);

    expect(Schema::hasTable('deal_lifecycles'))->toBeFalse()
        ->and(Schema::hasColumn('estimates', 'lost_reason'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'sales_team'))->toBeFalse();

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('deal_lifecycles'))->toBeTrue();
});

test('the owners list holds the members of the company only', function () {
    $mate = ($this->teammate)();
    User::factory()->create();

    $ids = collect(getJson('api/v1/deal-owners')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($this->user->id, $mate->id)
        ->and($ids)->toHaveCount($this->company->users()->count());

    Sanctum::actingAs(User::factory()->create(), ['*']);
    getJson('api/v1/deal-owners')->assertForbidden();
});
