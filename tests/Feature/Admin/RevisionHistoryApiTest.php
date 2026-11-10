<?php

use App\Mail\SendInvoiceMail;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\DocumentRevision;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
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

    $this->payload = fn () => Invoice::factory()->raw([
        'company_id' => $this->company->id,
        'taxes' => [Tax::factory()->raw()],
        'items' => [InvoiceItem::factory()->raw()],
    ]);

    $this->draft = fn (): int => postJson('api/v1/invoices', ($this->payload)())->assertOk()->json('data.id');

    // a draft edited once, so it has created + edited
    $this->editedDraft = function () {
        $id = ($this->draft)();
        $payload = ($this->payload)();
        $payload['notes'] = 'Customer asked for 10 % off';
        putJson("api/v1/invoices/{$id}", $payload)->assertOk();

        return $id;
    };
});

test('invoice revisions list newest first with actor, reason and diff summary', function () {
    $id = ($this->editedDraft)();

    $response = getJson("api/v1/invoices/{$id}/revisions")->assertOk();

    expect($response->json('data.*.reason_code'))->toBe(['edited', 'created'])
        ->and($response->json('data.*.revision_no'))->toBe([2, 1])
        ->and($response->json('data.0.actor_name'))->toBe($this->user->name)
        ->and($response->json('data.0.diff_summary.changes'))->toBeGreaterThan(0)
        ->and($response->json('data.0'))->not->toHaveKey('snapshot')
        ->and($response->json('meta.total'))->toBe(2);
});

test('the list is paged', function () {
    $id = ($this->editedDraft)();

    $page = getJson("api/v1/invoices/{$id}/revisions?limit=1")->assertOk();

    expect($page->json('data'))->toHaveCount(1)
        ->and($page->json('meta.last_page'))->toBe(2);
});

test('estimate and credit note histories are served too', function () {
    $estimate = postJson('api/v1/estimates', Estimate::factory()->raw([
        'items' => [EstimateItem::factory()->raw()],
        'taxes' => [Tax::factory()->raw()],
    ]))->assertCreated()->json('data.id');

    getJson("api/v1/estimates/{$estimate}/revisions")->assertOk()->assertJsonPath('data.0.reason_code', 'created');

    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id, 'status' => Invoice::STATUS_SENT, 'paid_status' => Invoice::STATUS_UNPAID,
        'sub_total' => 10000, 'total' => 10000, 'tax' => 0, 'discount_val' => 0, 'discount' => 0, 'due_amount' => 10000, 'credited_total' => 0,
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'company_id' => $this->company->id, 'name' => 'Cable', 'quantity' => 1, 'price' => 10000, 'total' => 10000, 'discount_type' => 'fixed', 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'base_price' => 0, 'base_total' => 0, 'base_tax' => 0, 'base_discount_val' => 0, 'exchange_rate' => 1]);

    $creditNote = postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'price_correction'])->assertStatus(201)->json('data.id');

    getJson("api/v1/credit-notes/{$creditNote}/revisions")->assertOk()
        ->assertJsonPath('data.0.reason_code', 'issued')
        ->assertJsonPath('data.0.has_pdf', true);
});

test('one revision comes back with its snapshot and stored diff', function () {
    $id = ($this->editedDraft)();
    $edited = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->where('revision_no', 2)->first();

    $show = getJson("api/v1/revisions/{$edited->id}")->assertOk();

    expect($show->json('data.snapshot.notes'))->toBe('Customer asked for 10 % off')
        ->and($show->json('data.diff_from_previous'))->not->toBeEmpty()
        ->and($show->json('data.totals_hash'))->toBe($edited->totals_hash);
});

test('diff against previous reports the header and line changes', function () {
    $id = ($this->draft)();
    $payload = ($this->payload)();
    $payload['notes'] = 'v2';
    $payload['items'][0]['quantity'] = 42;
    putJson("api/v1/invoices/{$id}", $payload)->assertOk();

    $edited = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->where('revision_no', 2)->first();

    $diff = getJson("api/v1/revisions/{$edited->id}/diff")->assertOk();
    $kinds = collect($diff->json('data.changes'))->pluck('kind')->unique()->all();

    expect($kinds)->toContain('header_changed')->toContain('quantity_changed')
        ->and($diff->json('data.summary.changes'))->toBe(count($diff->json('data.changes')))
        ->and($diff->json('data.against_id'))->not->toBeNull();
});

test('diff against an explicit revision of the same document works, another document is refused', function () {
    $id = ($this->editedDraft)();
    $other = ($this->draft)();

    $revisions = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->orderBy('revision_no')->get();
    $foreign = DocumentRevision::where('document_id', $other)->where('document_type', 'invoice')->first();

    $reverse = getJson("api/v1/revisions/{$revisions[0]->id}/diff?against={$revisions[1]->id}")->assertOk();
    $notes = collect($reverse->json('data.changes'))->firstWhere('path', 'notes');
    expect($notes['before'])->toBe('Customer asked for 10 % off');

    getJson("api/v1/revisions/{$revisions[1]->id}/diff?against={$foreign->id}")
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed');
});

test('the first revision has nothing to diff against', function () {
    $id = ($this->draft)();
    $first = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->first();

    getJson("api/v1/revisions/{$first->id}/diff")->assertOk()
        ->assertJsonPath('data.changes', [])
        ->assertJsonPath('data.against_id', null);
});

test('a user outside the company cannot read its history', function () {
    $id = ($this->editedDraft)();
    $revision = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->first();

    Sanctum::actingAs(User::factory()->create(), ['*']);

    getJson("api/v1/invoices/{$id}/revisions")->assertForbidden();
    getJson("api/v1/revisions/{$revision->id}")->assertForbidden();
    getJson("api/v1/revisions/{$revision->id}/diff")->assertForbidden();
    getJson("api/v1/revisions/{$revision->id}/pdf")->assertForbidden();
});

test('history of a deleted draft is still readable by the company', function () {
    $id = ($this->draft)();
    $revision = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->first();

    postJson('api/v1/invoices/delete', ['ids' => [$id], 'reason_code' => 'wrong_customer'])->assertOk();
    expect(Invoice::find($id))->toBeNull();

    getJson("api/v1/revisions/{$revision->id}")->assertOk()->assertJsonPath('data.reason_code', 'created');
});

test('issuing stores the exact PDF, the hash matches and the file is served with a digest', function () {
    $id = ($this->draft)();
    postJson("api/v1/invoices/{$id}/issue")->assertOk();

    $issued = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->where('reason_code', 'issued')->first();
    expect($issued->pdf_sha256)->toMatch('/^[0-9a-f]{64}$/');

    $response = getJson("api/v1/revisions/{$issued->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $bytes = $response->getContent();

    expect($bytes)->toStartWith('%PDF')
        ->and(hash('sha256', $bytes))->toBe($issued->pdf_sha256)
        ->and($response->headers->get('Digest'))->toBe('sha-256='.base64_encode(hex2bin($issued->pdf_sha256)));

    // rows are append-only, so the hash on the revision cannot drift from the stored file
    expect(DocumentRevision::find($issued->id)->pdf_sha256)->toBe($issued->pdf_sha256);
});

test('a revision without a stored PDF answers 404 problem json', function () {
    $id = ($this->draft)();
    $created = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->first();

    getJson("api/v1/revisions/{$created->id}/pdf")->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'pdf_not_stored');
});

test('sending keeps the PDF that was attached, and each resend writes its own sent revision', function () {
    Mail::fake();
    CompanySetting::setSettings(['invoice_email_attachment' => 'YES'], $this->company->id);
    $id = ($this->draft)();
    $send = ['subject' => 'Invoice', 'body' => 'Hello', 'from' => 'a@b.test', 'to' => 'c@d.test'];

    postJson("api/v1/invoices/{$id}/send", $send)->assertOk();
    postJson("api/v1/invoices/{$id}/send", $send)->assertOk();

    $sent = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->where('reason_code', 'sent')->orderBy('revision_no')->get();
    expect($sent)->toHaveCount(2);

    foreach ($sent as $revision) {
        $bytes = getJson("api/v1/revisions/{$revision->id}/pdf")->assertOk()->getContent();
        expect(hash('sha256', $bytes))->toBe($revision->pdf_sha256);
    }

    Mail::assertSent(SendInvoiceMail::class, function (SendInvoiceMail $mail) use ($sent) {
        $mail->build();
        $attached = collect($mail->rawAttachments)->pluck('data')->map(fn ($d) => hash('sha256', $d));

        return $attached->contains($sent[0]->pdf_sha256);
    });
});

test('sending keeps the rendered PDF even when attachments are off', function () {
    Mail::fake();
    $id = ($this->draft)();

    postJson("api/v1/invoices/{$id}/send", ['subject' => 's', 'body' => 'b', 'from' => 'a@b.test', 'to' => 'c@d.test'])->assertOk();

    $sent = DocumentRevision::where('document_id', $id)->where('document_type', 'invoice')->where('reason_code', 'sent')->first();
    expect(hash('sha256', getJson("api/v1/revisions/{$sent->id}/pdf")->assertOk()->getContent()))->toBe($sent->pdf_sha256);
});

test('sending a quote keeps its PDF too', function () {
    Mail::fake();
    $id = postJson('api/v1/estimates', Estimate::factory()->raw([
        'items' => [EstimateItem::factory()->raw()],
        'taxes' => [Tax::factory()->raw()],
    ]))->assertCreated()->json('data.id');

    postJson("api/v1/estimates/{$id}/send", ['subject' => 'Quote', 'body' => 'Hello', 'from' => 'a@b.test', 'to' => 'c@d.test'])->assertOk();

    $sent = DocumentRevision::where('document_id', $id)->where('document_type', 'estimate')->where('reason_code', 'sent')->first();

    expect($sent->pdf_sha256)->not->toBeNull()
        ->and(hash('sha256', getJson("api/v1/revisions/{$sent->id}/pdf")->assertOk()->getContent()))->toBe($sent->pdf_sha256);
});

test('the credit note PDF stored at issue matches its hash', function () {
    $invoice = Invoice::factory()->create([
        'company_id' => $this->company->id, 'status' => Invoice::STATUS_SENT, 'paid_status' => Invoice::STATUS_UNPAID,
        'sub_total' => 10000, 'total' => 10000, 'tax' => 0, 'discount_val' => 0, 'discount' => 0, 'due_amount' => 10000, 'credited_total' => 0,
    ]);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'company_id' => $this->company->id, 'name' => 'Cable', 'quantity' => 1, 'price' => 10000, 'total' => 10000, 'discount_type' => 'fixed', 'discount' => 0, 'discount_val' => 0, 'tax' => 0, 'base_price' => 0, 'base_total' => 0, 'base_tax' => 0, 'base_discount_val' => 0, 'exchange_rate' => 1]);

    $id = postJson("api/v1/invoices/{$invoice->id}/credit-notes", ['reason_code' => 'price_correction'])->assertStatus(201)->json('data.id');
    $issued = DocumentRevision::where('document_type', 'credit_note')->where('document_id', $id)->where('reason_code', 'issued')->first();

    expect(hash('sha256', getJson("api/v1/revisions/{$issued->id}/pdf")->assertOk()->getContent()))->toBe($issued->pdf_sha256)
        ->and(CreditNote::find($id))->not->toBeNull();
});
