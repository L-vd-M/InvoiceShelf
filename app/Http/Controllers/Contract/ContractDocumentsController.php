<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Contract;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ConditionalRequests;
use App\Http\Requests\EstimatesRequest;
use App\Http\Requests\InvoicesRequest;
use App\Http\Requests\PaymentRequest;
use App\Jobs\GenerateInvoicePdfJob;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\UserSetting;
use App\Services\Contract\ContractPresenter;
use App\Services\Document\CreditNoteService;
use App\Services\Document\EstimateService;
use App\Services\Document\InvoiceService;
use App\Services\Document\PaymentService;
use App\Services\Document\SerialNumberService;
use App\Services\Events\EventData;
use App\Support\DocumentTotals;
use App\Support\Pdf\PdfTemplateUtils;
use App\Support\Problem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Integration (contract) representation of the document endpoints, served to scoped tokens by
 * ContractRepresentation. Spec 08, kit openapi/documents.v1.yaml. The business rules live in the
 * same services the web UI uses.
 */
class ContractDocumentsController extends Controller
{
    private const KINDS = ['estimate' => Estimate::class, 'invoice' => Invoice::class, 'credit_note' => CreditNote::class];

    public function __construct(
        private readonly EstimateService $estimates,
        private readonly InvoiceService $invoices,
        private readonly CreditNoteService $creditNotes,
        private readonly PaymentService $payments,
    ) {}

    public function list(Request $request, string $kind): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'integer', 'min:0'],
            'external_ref' => ['sometimes', 'string', 'regex:/^[a-z0-9_-]+:.+$/'],
            'updated_since' => ['sometimes', 'date'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }

        $class = self::KINDS[$kind];
        $table = (new $class)->getTable();
        $limit = (int) $request->query('limit', 50);

        $query = $class::query()->where("{$table}.company_id", (int) $request->header('company'))
            ->where("{$table}.id", '>', (int) $request->query('cursor', 0))
            ->orderBy("{$table}.id");

        if ($request->filled('external_ref') && $kind !== 'credit_note') {
            $query->whereExternalRef($request->query('external_ref'));
        }
        if ($request->filled('updated_since')) {
            $query->where("{$table}.updated_at", '>=', now()->parse($request->query('updated_since')));
        }

        $rows = $query->limit($limit + 1)->get();
        $page = $rows->take($limit);

        return response()->json([
            'data' => $page->map(fn (Model $m) => ContractPresenter::document($m))->values(),
            'next_cursor' => $rows->count() > $limit ? (string) $page->last()->id : null,
        ]);
    }

    public function show(Request $request, Model $document): JsonResponse
    {
        return $this->respond($document);
    }

    public function create(Request $request, string $kind): JsonResponse
    {
        $company = (int) $request->header('company');
        $class = self::KINDS[$kind];

        $v = Validator::make($request->all(), [
            'customer_ref' => ['required', 'string', 'min:1', 'max:250'],
            'total' => ['required', 'array'],
            'total.amount_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'total.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'supplied_number' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9-]{3,40}$/'],
            'external_ref' => ['sometimes', 'nullable', 'array:source,id'],
            'external_ref.source' => ['required_with:external_ref', 'string', 'max:50', 'regex:/^[a-z0-9_.-]+$/'],
            'external_ref.id' => ['required_with:external_ref', 'string', 'max:191'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.name' => ['required', 'string', 'max:250'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0'],
            'lines.*.kind' => ['nullable', 'in:goods,service,subscription,fee,discount,shipping,other'],
            'lines.*.stream' => ['nullable', 'string', 'max:60'],
            'lines.*.part_ref' => ['nullable', 'string', 'max:120'],
            'customer' => ['sometimes', 'array'],
            'customer.name' => ['required_with:customer', 'string', 'max:250'],
            'customer.email' => ['nullable', 'email', 'max:250'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $input = $v->validated();

        $supplied = $input['supplied_number'] ?? null;
        if ($supplied !== null && ! $this->token($request)?->can('documents:shadow')) {
            return Problem::response(403, 'insufficient_ability', 'Token lacks documents:shadow', 'supplied_number needs the documents:shadow ability.');
        }

        $ext = $input['external_ref'] ?? null;
        if ($ext) {
            $existing = $class::query()->where('company_id', $company)->whereExternalRef($ext['source'].':'.$ext['id'])->first();
            if ($existing) {
                return $this->respond($existing, 200);
            }
        }

        if ($supplied !== null && $this->numberTaken($company, $supplied)) {
            return Problem::response(409, 'number_conflict', 'Number already used or reserved', "{$supplied} is already in use in this company.");
        }

        $customer = $this->resolveCustomer($company, $input, $request);
        if (! $customer) {
            return Problem::response(422, 'validation_failed', 'Unknown customer_ref', 'No customer matches customer_ref; send `customer` {name, email} with a source:id reference to create it.', ['errors' => ['customer_ref' => ['unknown']]]);
        }

        $currency = Currency::find($customer->currency_id)?->code;
        if ($currency !== $input['total']['currency']) {
            return Problem::response(422, 'validation_failed', 'Currency mismatch', "The customer is billed in {$currency}.", ['errors' => ['total.currency' => ["must be {$currency}"]]]);
        }

        $items = $this->items($input);
        $computed = DocumentTotals::compute($items, [], 0, 'NO', false);
        if ($computed['total'] !== (int) $input['total']['amount_minor']) {
            return Problem::response(422, 'validation_failed', 'Total does not match the lines', "Lines add up to {$computed['total']}.", ['errors' => ['total.amount_minor' => ['does not match lines']]]);
        }

        try {
            $document = $kind === 'estimate'
                ? $this->createEstimate($request, $company, $customer, $items, $input, $supplied, $ext)
                : $this->createInvoice($request, $company, $customer, $items, $input, $supplied, $ext);
        } catch (UniqueConstraintViolationException) {
            return Problem::response(409, 'number_conflict', 'Number or external_ref already used', 'A concurrent request created this document first; retry.');
        }

        return $this->respond($document, 201);
    }

    public function accept(Request $request, Estimate $estimate): JsonResponse
    {
        if (in_array($estimate->status, [Estimate::STATUS_REJECTED, Estimate::STATUS_EXPIRED], true)) {
            return Problem::response(409, 'document_immutable', 'Quote can no longer be accepted', "Quote {$estimate->estimate_number} is ".strtolower($estimate->status).'.');
        }

        if ($estimate->status !== Estimate::STATUS_ACCEPTED) {
            $this->estimates->changeStatus($estimate, Estimate::STATUS_ACCEPTED);
        }

        return $this->respond($estimate->refresh());
    }

    public function issueInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoices->issue($invoice);
        GenerateInvoicePdfJob::dispatch($invoice, true);

        return $this->respond($invoice->refresh());
    }

    public function createCreditNote(Request $request, Invoice $invoice): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'total_minor' => ['sometimes', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:500'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }

        if (! $invoice->isIssued()) {
            return Problem::response(409, 'document_immutable', 'Credit notes need an issued invoice', 'Draft invoices are edited or deleted, not credited.');
        }

        $remaining = (int) $invoice->total - (int) $invoice->credited_total;
        $amount = (int) $request->input('total_minor', $remaining);

        if ($amount > $remaining) {
            return Problem::response(422, 'validation_failed', 'Credit exceeds the invoice', "At most {$remaining} can still be credited.", ['errors' => ['total_minor' => ['exceeds remaining']]]);
        }

        // a full credit of an untouched invoice keeps the invoice lines; anything else is credited by amount
        $byAmount = (int) $invoice->credited_total > 0 || $amount !== (int) $invoice->total;

        try {
            $creditNote = $this->creditNotes->create($invoice, [
                'reason_code' => 'other',
                'reason' => $request->input('reason') ?: 'Credit note issued through the API',
            ], null, null, $byAmount ? $amount : null);
        } catch (ValidationException $e) {
            return $this->invalid($e->validator);
        }

        return $this->respond($creditNote, 201);
    }

    public function issueCreditNote(Request $request, CreditNote $creditNote): JsonResponse
    {
        return $this->respond($creditNote);
    }

    public function createPayment(Request $request): JsonResponse
    {
        $company = (int) $request->header('company');

        $v = Validator::make($request->all(), [
            'invoice_id' => ['required', 'string'],
            'amount' => ['required', 'array'],
            'amount.amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'amount.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'external_ref' => ['required', 'string', 'min:1', 'max:191'],
        ]);
        if ($v->fails()) {
            return $this->invalid($v);
        }
        $input = $v->validated();

        $invoice = Invoice::where('company_id', $company)->whereKey((int) $input['invoice_id'])->first();
        if (! $invoice) {
            return Problem::response(404, 'not_found', 'No such invoice');
        }

        $amount = (int) $input['amount']['amount_minor'];
        $existing = Payment::where('company_id', $company)->where('bank_transaction_id', $input['external_ref'])->first();
        if ($existing) {
            if ((int) $existing->invoice_id !== $invoice->id || (int) $existing->amount !== $amount) {
                return Problem::response(409, 'already_exists', 'Bank transaction already booked', 'This external_ref is recorded with a different invoice or amount.');
            }

            return response()->json($this->paymentView($existing), 200);
        }

        $invoice->loadMissing('currency');
        if ($invoice->currency?->code !== $input['amount']['currency']) {
            return Problem::response(422, 'validation_failed', 'Currency mismatch', "The invoice is in {$invoice->currency?->code}.", ['errors' => ['amount.currency' => ['mismatch']]]);
        }
        if ($amount > (int) $invoice->due_amount) {
            return Problem::response(422, 'validation_failed', 'Payment exceeds what is due', "The invoice has {$invoice->due_amount} due.", ['errors' => ['amount.amount_minor' => ['exceeds due amount']]]);
        }

        $number = (new SerialNumberService)->setModel(new Payment)->setCompany($company)->setCustomer($invoice->customer_id)->getNextNumber();
        $methodId = PaymentMethod::where('company_id', $company)->where(fn ($q) => $q->where('name', 'like', '%EFT%')->orWhere('name', 'like', '%Bank%'))->value('id');

        $form = PaymentRequest::create('/api/v1/payments', 'POST', [
            'payment_date' => now()->format('Y-m-d'),
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'exchange_rate' => 1,
            'payment_number' => $number,
            'payment_method_id' => $methodId,
            'bank_transaction_id' => $input['external_ref'],
            'notes' => 'Recorded through the API from bank transaction '.$input['external_ref'],
        ]);
        $this->prepare($form, $request, $company);

        try {
            $payment = $this->payments->create($form);
        } catch (UniqueConstraintViolationException) {
            $payment = Payment::where('company_id', $company)->where('bank_transaction_id', $input['external_ref'])->firstOrFail();

            return response()->json($this->paymentView($payment), 200);
        }

        return response()->json($this->paymentView($payment), 201);
    }

    // ------------------------------------------------------------------ helpers

    private function createEstimate(Request $request, int $company, Customer $customer, array $items, array $input, ?string $supplied, ?array $ext): Estimate
    {
        $number = $supplied ?? (new SerialNumberService)->setModel(new Estimate)->setCompany($company)->setCustomer($customer->id)->getNextNumber();

        // accounting hints are invoice-line columns; quotes carry none
        $items = array_map(fn (array $item) => array_diff_key($item, array_flip(['kind', 'stream', 'part_ref'])), $items);

        $payload = $this->commonPayload($request, $customer, $items, 'estimate') + [
            'estimate_date' => now()->format('Y-m-d'),
            'expiry_date' => $input['expiry_date'] ?? now()->addDays(14)->format('Y-m-d'),
            'estimate_number' => $number,
            'supplied_number' => $supplied,
            'reference_number' => $input['reference_number'] ?? null,
            'notes' => $input['notes'] ?? null,
            'external_ref' => $ext,
        ];

        $form = EstimatesRequest::create('/api/v1/estimates', 'POST', array_filter($payload, fn ($v) => $v !== null));
        $this->prepare($form, $request, $company);

        return $this->estimates->create($form)->refresh();
    }

    private function createInvoice(Request $request, int $company, Customer $customer, array $items, array $input, ?string $supplied, ?array $ext): Invoice
    {
        $payload = $this->commonPayload($request, $customer, $items, 'invoice') + [
            'invoice_date' => now()->format('Y-m-d'),
            'due_date' => $input['due_date'] ?? now()->addDays(14)->format('Y-m-d'),
            'invoice_number' => Invoice::draftReference(),
            'reference_number' => $input['reference_number'] ?? null,
            'notes' => $input['notes'] ?? null,
            'external_ref' => $ext,
        ];

        $form = InvoicesRequest::create('/api/v1/invoices', 'POST', array_filter($payload, fn ($v) => $v !== null));
        $this->prepare($form, $request, $company);

        $invoice = $this->invoices->create($form);

        if ($supplied !== null) {
            $invoice->forceFill(['supplied_number' => $supplied])->save();
        }

        return $invoice->refresh();
    }

    /** Fields the native form requests insist on; totals are recomputed server-side from the items. */
    private function commonPayload(Request $request, Customer $customer, array $items, string $type): array
    {
        $subTotal = DocumentTotals::compute($items, [], 0, 'NO', false)['total'];
        $default = UserSetting::where('user_id', $request->user()->id)->where('key', "default_{$type}_template")->value('value');
        $known = array_column(PdfTemplateUtils::getFormattedTemplates($type), 'name');

        return [
            'customer_id' => $customer->id,
            'currency_id' => $customer->currency_id,
            'exchange_rate' => 1,
            'template_name' => in_array($default, $known, true) ? $default : "{$type}1",
            'discount' => 0,
            'discount_type' => 'fixed',
            'discount_val' => 0,
            'sub_total' => $subTotal,
            'total' => $subTotal,
            'tax' => 0,
            'taxes' => [],
            'items' => $items,
        ];
    }

    private function prepare(object $form, Request $request, int $company): void
    {
        $form->headers->set('company', (string) $company);
        $form->setUserResolver(fn () => $request->user());
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();
    }

    private function items(array $input): array
    {
        $lines = $input['lines'] ?? [[
            'name' => 'Services',
            'description' => null,
            'quantity' => 1,
            'unit_price_minor' => (int) $input['total']['amount_minor'],
        ]];

        return array_map(fn (array $line) => [
            'name' => $line['name'],
            'description' => $line['description'] ?? null,
            'quantity' => $line['quantity'],
            'price' => (int) $line['unit_price_minor'],
            'discount' => 0,
            'discount_type' => 'fixed',
            'discount_val' => 0,
            'tax' => 0,
            'total' => (int) round($line['quantity'] * $line['unit_price_minor']),
            'unit_name' => null,
            'taxes' => [],
            'kind' => $line['kind'] ?? null,
            'stream' => $line['stream'] ?? null,
            'part_ref' => $line['part_ref'] ?? null,
        ], $lines);
    }

    private function resolveCustomer(int $company, array $input, Request $request): ?Customer
    {
        $ref = $input['customer_ref'];

        if (str_contains($ref, ':')) {
            $customer = Customer::where('company_id', $company)->whereExternalRef($ref)->first();
            if ($customer) {
                return $customer;
            }
            if (isset($input['customer']['name'])) {
                [$source, $id] = explode(':', $ref, 2);
                $currencyId = (int) CompanySetting::getSetting('currency', $company);

                return Customer::create([
                    'name' => $input['customer']['name'],
                    'email' => $input['customer']['email'] ?? null,
                    'currency_id' => $currencyId ?: Currency::where('code', 'ZAR')->value('id'),
                    'company_id' => $company,
                    'creator_id' => $request->user()->id,
                    'external_ref_source' => $source,
                    'external_ref_id' => $id,
                ]);
            }

            return null;
        }

        return ctype_digit($ref) ? Customer::where('company_id', $company)->find((int) $ref) : null;
    }

    private function numberTaken(int $company, string $number): bool
    {
        return Estimate::where('company_id', $company)->where('estimate_number', $number)->exists()
            || Invoice::where('company_id', $company)->where(fn ($q) => $q->where('invoice_number', $number)->orWhere('supplied_number', $number))->exists();
    }

    private function respond(Model $document, int $status = 200): JsonResponse
    {
        $document->refresh();

        return response()->json(ContractPresenter::document($document), $status, [
            'ETag' => ConditionalRequests::etag($document),
        ]);
    }

    private function paymentView(Payment $payment): array
    {
        $payment->loadMissing('invoice.currency', 'currency');

        return [
            'id' => (string) $payment->id,
            'invoice_id' => (string) $payment->invoice_id,
            'amount' => EventData::money($payment, (int) $payment->amount, $payment->invoice),
            'external_ref' => (string) $payment->bank_transaction_id,
        ];
    }

    private function invalid(\Illuminate\Validation\Validator $validator): JsonResponse
    {
        return Problem::response(422, 'validation_failed', 'Validation failed', implode(' ', $validator->errors()->all()), ['errors' => $validator->errors()->toArray()]);
    }

    private function token(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }
}
