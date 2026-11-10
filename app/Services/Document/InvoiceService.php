<?php

namespace App\Services\Document;

use App;
use App\Exceptions\DocumentImmutableException;
use App\Facades\Hashids;
use App\Facades\Pdf;
use App\Mail\SendInvoiceMail;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CustomField;
use App\Models\DocumentDeletion;
use App\Models\Estimate;
use App\Models\ExchangeRateLog;
use App\Models\Invoice;
use App\Services\Events\OutboxService;
use App\Services\Mail\CompanyMailConfigService;
use App\Support\Pdf\PdfTemplateUtils;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly DocumentItemService $documentItemService,
    ) {}

    public function create(Request $request): Invoice
    {
        return DB::transaction(fn () => $this->createInTransaction($request));
    }

    private function createInTransaction(Request $request): Invoice
    {
        $data = $request->getInvoicePayload();

        if ($request->has('invoiceSend')) {
            $data['status'] = Invoice::STATUS_SENT;
        }

        // A draft has no legal number: it carries a draft reference and takes the next gap-free
        // number from the counter only when it is issued, so deleting a draft never leaves a gap.
        $data['invoice_number'] = Invoice::draftReference();
        $data['sequence_number'] = null;
        $data['customer_sequence_number'] = null;

        $invoice = Invoice::create($data);

        $invoice->unique_hash = Hashids::connection(Invoice::class)->encode($invoice->id);
        $invoice->save();

        $this->documentItemService->createItems($invoice, $request->items);

        $companyCurrency = CompanySetting::getSetting('currency', $request->header('company'));

        if ((string) $data['currency_id'] !== $companyCurrency) {
            ExchangeRateLog::addExchangeRateLog($invoice);
        }

        if ($request->has('taxes') && (! empty($request->taxes))) {
            $this->documentItemService->createTaxes($invoice, $request->taxes);
        }

        if ($request->customFields) {
            $invoice->addCustomFields($request->customFields);
        }

        RevisionService::record($invoice, 'created');

        if ($data['status'] === Invoice::STATUS_SENT) {
            $this->issue($invoice);
        }

        return Invoice::with([
            'items',
            'items.fields',
            'items.fields.customField',
            'customer',
            'taxes',
        ])->find($invoice->id);
    }

    /**
     * Take the next gap-free number (drafts have none yet), stamp issued_at and freeze the document.
     * Idempotent: an issued invoice is returned unchanged.
     */
    public function issue(Invoice $invoice): Invoice
    {
        $issued = DB::transaction(function () use ($invoice) {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->isIssued()) {
                return $locked;
            }

            if ($locked->sequence_number === null) {
                $serial = (new SerialNumberService)
                    ->setModel($locked)
                    ->setCompany($locked->company_id)
                    ->setCustomer($locked->customer_id)
                    ->reserveNextNumbers();

                $locked->sequence_number = $serial->nextSequenceNumber;
                $locked->customer_sequence_number = $serial->nextCustomerSequenceNumber;
                $locked->invoice_number = $serial->getNextNumber();
            }

            if ($locked->status === Invoice::STATUS_DRAFT) {
                $locked->status = Invoice::STATUS_SENT;
                $locked->sent = true;
            }

            $locked->issued_at = now();
            $locked->save();

            OutboxService::record('document.issued', 'invoice', $locked, $locked->invoice_number, [
                'document_type' => 'invoice',
                'number' => $locked->invoice_number,
                'customer_id' => $locked->customer_id,
                'issued_at' => $locked->issued_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'invoice_date' => $locked->invoice_date ? Carbon::parse($locked->invoice_date)->format('Y-m-d') : null,
                'due_date' => $locked->due_date ? Carbon::parse($locked->due_date)->format('Y-m-d') : null,
                'sub_total' => (int) $locked->sub_total,
                'tax' => (int) $locked->tax,
                'total' => (int) $locked->total,
                'currency' => $locked->currency?->code,
                'estimate_id' => $locked->estimate_id ?? null,
            ]);

            RevisionService::record($locked, 'issued');

            return $locked;
        });

        return $invoice->setRawAttributes($issued->getAttributes(), true);
    }

    /**
     * @throws ValidationException
     */
    public function update(Invoice $invoice, Request $request): Invoice
    {
        return DB::transaction(fn () => $this->updateInTransaction($invoice, $request));
    }

    private function updateInTransaction(Invoice $invoice, Request $request): Invoice
    {
        if ($invoice->isIssued()) {
            throw new DocumentImmutableException('edited', $invoice->invoice_number);
        }

        $data = $request->getInvoicePayload();
        $oldTotal = $invoice->total;

        $totalPaidAmount = $invoice->total - $invoice->due_amount;

        if ($totalPaidAmount > 0 && $invoice->customer_id !== $request->customer_id) {
            throw ValidationException::withMessages([
                'customer_id' => ['customer_cannot_be_changed_after_payment_is_added'],
            ]);
        }

        if ($data['total'] >= 0 && $data['total'] < $totalPaidAmount) {
            throw ValidationException::withMessages([
                'total' => ['total_invoice_amount_must_be_more_than_paid_amount'],
            ]);
        }

        if ($oldTotal != $data['total']) {
            $oldTotal = (int) round($data['total']) - (int) $oldTotal;
        } else {
            $oldTotal = 0;
        }

        $data['due_amount'] = ($invoice->due_amount + $oldTotal);
        $data['base_due_amount'] = $data['due_amount'] * $data['exchange_rate'];

        // An unissued draft keeps its draft reference; only a legacy numbered draft may take a typed number.
        if ($invoice->sequence_number === null) {
            $data['invoice_number'] = $invoice->invoice_number;
        }
        unset($data['sequence_number'], $data['customer_sequence_number']);

        $invoice->update($data);

        $statusData = $invoice->getInvoiceStatusByAmount($data['due_amount']);
        if (! empty($statusData)) {
            $invoice->update($statusData);
        }

        $companyCurrency = CompanySetting::getSetting('currency', $request->header('company'));

        if ((string) $data['currency_id'] !== $companyCurrency) {
            ExchangeRateLog::addExchangeRateLog($invoice);
        }

        $invoice->items->map(function ($item) {
            $fields = $item->fields()->get();

            $fields->map(function ($field) {
                $field->delete();
            });
        });

        $invoice->items()->delete();
        $invoice->taxes()->delete();

        $this->documentItemService->createItems($invoice, $request->items);

        if ($request->has('taxes') && (! empty($request->taxes))) {
            $this->documentItemService->createTaxes($invoice, $request->taxes);
        }

        if ($request->customFields) {
            $invoice->updateCustomFields($request->customFields);
        }

        RevisionService::record($invoice, 'edited', ['skip_if_unchanged' => true]);

        return Invoice::with([
            'items',
            'items.fields',
            'items.fields.customField',
            'customer',
            'taxes',
        ])->find($invoice->id);
    }

    public function delete(Collection $ids, string $reasonCode, string $reason): bool
    {
        return DB::transaction(function () use ($ids, $reasonCode, $reason) {
            foreach ($ids as $id) {
                $invoice = Invoice::find($id);
                if ($invoice?->isIssued()) {
                    throw new DocumentImmutableException('deleted', $invoice->invoice_number);
                }
            }

            foreach ($ids as $id) {
                $this->deleteOne(Invoice::find($id), $reasonCode, $reason);
            }

            return true;
        });
    }

    private function deleteOne(Invoice $invoice, string $reasonCode, string $reason): void
    {
        DocumentDeletion::record($invoice, 'invoice', $reasonCode, $reason);

        if ($invoice->transactions()->exists()) {
            $invoice->transactions()->delete();
        }

        $invoice->delete();
    }

    public function sendInvoiceData(Invoice $invoice, array $data): array
    {
        $data['invoice'] = $invoice->toArray();
        $data['customer'] = $invoice->customer->toArray();
        $data['company'] = Company::find($invoice->company_id);
        $data['subject'] = $invoice->getEmailString($data['subject']);
        $data['body'] = $invoice->getEmailString($data['body']);
        $data['attach']['data'] = ($invoice->getEmailAttachmentSetting()) ? $this->getPdfData($invoice) : null;

        return $data;
    }

    public function preview(Invoice $invoice, array $data): array
    {
        $data = $this->sendInvoiceData($invoice, $data);

        return [
            'type' => 'preview',
            'view' => new SendInvoiceMail($data),
        ];
    }

    public function send(Invoice $invoice, array $data): array
    {
        $this->issue($invoice);

        $data = $this->sendInvoiceData($invoice, $data);

        CompanyMailConfigService::apply($invoice->company_id);

        $mail = \Mail::to($data['to']);
        if (! empty($data['cc'])) {
            $mail->cc($data['cc']);
        }
        if (! empty($data['bcc'])) {
            $mail->bcc($data['bcc']);
        }
        $mail->send(new SendInvoiceMail($data));

        if ($invoice->status == Invoice::STATUS_DRAFT) {
            $invoice->status = Invoice::STATUS_SENT;
            $invoice->sent = true;
            $invoice->save();
        }

        RevisionService::record($invoice, 'sent');

        return [
            'success' => true,
            'type' => 'send',
        ];
    }

    public function getPdfData(Invoice $invoice)
    {
        $taxes = collect();

        if ($invoice->tax_per_item === 'YES') {
            foreach ($invoice->items as $item) {
                foreach ($item->taxes as $tax) {
                    $found = $taxes->filter(function ($item) use ($tax) {
                        return $item->tax_type_id == $tax->tax_type_id;
                    })->first();

                    if ($found) {
                        $found->amount += $tax->amount;
                    } else {
                        $taxes->push($tax);
                    }
                }
            }
        }

        $invoiceTemplate = Invoice::find($invoice->id)->template_name;

        $company = Company::find($invoice->company_id);
        $locale = CompanySetting::getSetting('language', $company->id);
        $customFields = CustomField::where('model_type', 'Item')->get();

        App::setLocale($locale);

        $logo = $company->logo_path;
        $logoHeight = CompanySetting::getSetting('logo_height', $company->id) ?? '50';
        $bankDetails = CompanySetting::getSetting('bank_details', $company->id) ?? '';
        $pageMargin = CompanySetting::getSetting('pdf_page_margin', $company->id) ?? '40';

        // Reserved top-margin height for the fixed-position header band (bank-details
        // template): Dompdf's header/footer trick needs this decided before layout, so
        // it can't size itself to actual rendered content automatically. Base covers the
        // logo/company row + the Bill To/Ship To/Invoice boxes row at their shortest;
        // add the same fixed increments the layout needed each time an optional line
        // (VAT, customer tax number) was turned on, so simple invoices without those
        // lines don't reserve space they don't use.
        $headerHeight = 400;
        if ($invoice->company->vat_id) {
            $headerHeight += 20;
        }
        if ($invoice->customer->vat_id || $invoice->customer->tax_id) {
            $headerHeight += 20;
        }

        view()->share([
            'invoice' => $invoice,
            'customFields' => $customFields,
            'company_address' => $invoice->getCompanyAddress(),
            'shipping_address' => $invoice->getCustomerShippingAddress(),
            'billing_address' => $invoice->getCustomerBillingAddress(),
            'notes' => $invoice->getNotes(),
            'logo' => $logo ?? null,
            'logoHeight' => $logoHeight,
            'bankDetails' => $bankDetails,
            'pageMargin' => $pageMargin,
            'headerHeight' => $headerHeight,
            'taxes' => $taxes,
        ]);

        $template = PdfTemplateUtils::findFormattedTemplate('invoice', $invoiceTemplate, '');
        $templatePath = $template['custom'] ? sprintf('pdf_templates::invoice.%s', $invoiceTemplate) : sprintf('app.pdf.invoice.%s', $invoiceTemplate);

        if (request()->has('preview')) {
            return view($templatePath);
        }

        $pdf = Pdf::loadView($templatePath);

        // Page numbers via Dompdf's canvas API (trusted PHP calling the
        // canvas directly), not the <script type="text/php"> HTML approach
        // -- that requires isPhpEnabled, which would let arbitrary PHP run
        // from any HTML fed to Dompdf, including user-editable fields like
        // invoice notes/addresses that already pass through this template
        // unescaped. render() must run before getCanvas()/page_text(): the
        // canvas can be swapped out mid-render() if paper size doesn't
        // match the default, so registering page_text() beforehand risks
        // it landing on a canvas instance that gets discarded.
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $fontMetrics = $pdf->getDomPDF()->getFontMetrics();
        $font = $fontMetrics->getFont('DejaVu Sans');
        $pageNumberText = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $textWidth = $fontMetrics->getTextWidth('Page 99 of 99', $font, 8);
        $canvas->page_text(
            $canvas->get_width() - ($pageMargin ?? 40) - $textWidth,
            $canvas->get_height() - 20,
            $pageNumberText,
            $font,
            8,
            [0.35, 0.35, 0.35]
        );

        return $pdf;
    }

    public function clone(Invoice $invoice): Invoice
    {
        return DB::transaction(fn () => $this->cloneInTransaction($invoice));
    }

    private function cloneInTransaction(Invoice $invoice): Invoice
    {
        $date = Carbon::now();

        $dueDate = null;
        $dueDateEnabled = CompanySetting::getSetting(
            'invoice_set_due_date_automatically',
            $invoice->company_id
        );

        if ($dueDateEnabled === 'YES') {
            $dueDateDays = intval(CompanySetting::getSetting(
                'invoice_due_date_days',
                $invoice->company_id
            ));
            $dueDate = Carbon::now()->addDays($dueDateDays)->format('Y-m-d');
        }

        $exchangeRate = $invoice->exchange_rate;

        $newInvoice = Invoice::create([
            'invoice_date' => $date->format('Y-m-d'),
            'due_date' => $dueDate,
            'invoice_number' => Invoice::draftReference(),
            'reference_number' => $invoice->reference_number,
            'customer_id' => $invoice->customer_id,
            'company_id' => $invoice->company_id,
            'template_name' => $invoice->template_name,
            'status' => Invoice::STATUS_DRAFT,
            'paid_status' => Invoice::STATUS_UNPAID,
            'sub_total' => $invoice->sub_total,
            'discount' => $invoice->discount,
            'discount_type' => $invoice->discount_type,
            'discount_val' => $invoice->discount_val,
            'total' => $invoice->total,
            'due_amount' => $invoice->total,
            'tax_per_item' => $invoice->tax_per_item,
            'discount_per_item' => $invoice->discount_per_item,
            'tax' => $invoice->tax,
            'notes' => $invoice->notes,
            'exchange_rate' => $exchangeRate,
            'base_total' => $invoice->total * $exchangeRate,
            'base_discount_val' => $invoice->discount_val * $exchangeRate,
            'base_sub_total' => $invoice->sub_total * $exchangeRate,
            'base_tax' => $invoice->tax * $exchangeRate,
            'base_due_amount' => $invoice->total * $exchangeRate,
            'currency_id' => $invoice->currency_id,
            'sales_tax_type' => $invoice->sales_tax_type,
            'sales_tax_address_type' => $invoice->sales_tax_address_type,
        ]);

        $newInvoice->unique_hash = Hashids::connection(Invoice::class)->encode($newInvoice->id);
        $newInvoice->save();

        $invoice->load('items.taxes');
        $this->documentItemService->createItems($newInvoice, $invoice->items->toArray());

        if ($invoice->taxes) {
            $this->documentItemService->createTaxes($newInvoice, $invoice->taxes->toArray());
        }

        if ($invoice->fields()->exists()) {
            $customFields = [];

            foreach ($invoice->fields as $data) {
                $customFields[] = [
                    'id' => $data->custom_field_id,
                    'value' => $data->defaultAnswer,
                ];
            }

            $newInvoice->addCustomFields($customFields);
        }

        RevisionService::record($newInvoice, 'created');

        return $newInvoice;
    }

    public function convertToEstimate(Invoice $invoice): Estimate
    {
        $invoice->load(['items', 'items.taxes', 'customer', 'taxes']);

        $serial = (new SerialNumberService)
            ->setModel(new Estimate)
            ->setCompany($invoice->company_id)
            ->setCustomer($invoice->customer_id)
            ->reserveNextNumbers();

        $exchangeRate = $invoice->exchange_rate;

        $estimate = Estimate::create([
            'creator_id' => $invoice->creator_id,
            'estimate_date' => Carbon::now()->format('Y-m-d'),
            'expiry_date' => Carbon::now()->addDays(30)->format('Y-m-d'),
            'estimate_number' => $serial->getNextNumber(),
            'sequence_number' => $serial->nextSequenceNumber,
            'customer_sequence_number' => $serial->nextCustomerSequenceNumber,
            'reference_number' => $serial->getNextNumber(),
            'customer_id' => $invoice->customer_id,
            'company_id' => $invoice->company_id,
            'template_name' => $invoice->getEstimateTemplateName(),
            'status' => Estimate::STATUS_DRAFT,
            'sub_total' => $invoice->sub_total,
            'discount' => $invoice->discount,
            'discount_type' => $invoice->discount_type,
            'discount_val' => $invoice->discount_val,
            'total' => $invoice->total,
            'tax_per_item' => $invoice->tax_per_item,
            'discount_per_item' => $invoice->discount_per_item,
            'tax' => $invoice->tax,
            'notes' => $invoice->notes,
            'exchange_rate' => $exchangeRate,
            'base_discount_val' => $invoice->discount_val * $exchangeRate,
            'base_sub_total' => $invoice->sub_total * $exchangeRate,
            'base_total' => $invoice->total * $exchangeRate,
            'base_tax' => $invoice->tax * $exchangeRate,
            'currency_id' => $invoice->currency_id,
            'sales_tax_type' => $invoice->sales_tax_type,
            'sales_tax_address_type' => $invoice->sales_tax_address_type,
        ]);

        $estimate->unique_hash = Hashids::connection(Estimate::class)->encode($estimate->id);
        $estimate->save();

        $this->documentItemService->createItems($estimate, $invoice->items->toArray());

        if ($invoice->taxes) {
            $this->documentItemService->createTaxes($estimate, $invoice->taxes->toArray());
        }

        if ($invoice->fields()->exists()) {
            $customFields = [];

            foreach ($invoice->fields as $data) {
                $customFields[] = [
                    'id' => $data->custom_field_id,
                    'value' => $data->defaultAnswer,
                ];
            }

            $estimate->addCustomFields($customFields);
        }

        RevisionService::record($estimate, 'created');

        return $estimate;
    }

    public function changeStatus(Invoice $invoice, string $status): void
    {
        if ($status == Invoice::STATUS_SENT) {
            $this->issue($invoice);
        } elseif ($status == Invoice::STATUS_COMPLETED) {
            $this->issue($invoice);
            $invoice->status = Invoice::STATUS_COMPLETED;
            $invoice->paid_status = Invoice::STATUS_PAID;
            $invoice->due_amount = 0;
            $invoice->save();
        }
    }
}
