<?php

namespace App\Services\Document;

use App\Facades\Hashids;
use App\Facades\Pdf;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditNoteService
{
    public const DEFAULT_NUMBER_FORMAT = '{{SERIES:CN}}{{DELIMITER:-}}{{SEQUENCE:6}}';

    /**
     * Issue a credit note against an invoice. $lines is [invoice_item_id => quantity] or null for
     * everything still creditable. Number, record, invoice balance and credited total change in
     * one transaction.
     */
    public function create(Invoice $invoice, array $data, ?array $lines, ?int $userId = null): CreditNote
    {
        return DB::transaction(function () use ($invoice, $data, $lines, $userId) {
            // lock the invoice row so two credit notes cannot both spend the same balance
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === Invoice::STATUS_DRAFT) {
                throw ValidationException::withMessages(['invoice' => ['A draft invoice is edited or deleted, not credited.']]);
            }

            [$lineRows, $subTotal] = $this->priceLines($invoice, $lines);

            $ratio = $invoice->sub_total > 0 ? $subTotal / $invoice->sub_total : 0;
            $total = (int) round($invoice->total * $ratio);
            $tax = (int) round($invoice->tax * $ratio);
            $discount = (int) round(($invoice->discount_val ?? 0) * $ratio);

            if ($total <= 0) {
                throw ValidationException::withMessages(['items' => ['Nothing left to credit on this invoice.']]);
            }

            if ($total > $invoice->total - $invoice->credited_total) {
                throw ValidationException::withMessages(['items' => ['The credit exceeds what remains of the invoice total.']]);
            }

            $applied = min($total, max(0, (int) $invoice->due_amount));

            $creditNote = CreditNote::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'creator_id' => $userId ?? auth()->id(),
                'credit_note_number' => 'PENDING',
                'credit_note_date' => $data['credit_note_date'] ?? now()->toDateString(),
                'status' => CreditNote::STATUS_ISSUED,
                'reason_code' => $data['reason_code'],
                'reason' => $data['reason_code'] === 'other' ? trim($data['reason']) : (CreditNote::REASONS[$data['reason_code']]),
                'discount_type' => $invoice->discount_type,
                'discount' => $invoice->discount,
                'discount_val' => $discount,
                'sub_total' => $subTotal,
                'tax' => $tax,
                'total' => $total,
                'applied_amount' => $applied,
                'unapplied_amount' => $total - $applied,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $invoice->exchange_rate,
                'template_name' => $invoice->template_name,
            ]);

            $this->assignNumber($creditNote);

            foreach ($lineRows as $row) {
                CreditNoteItem::create($row + ['credit_note_id' => $creditNote->id, 'company_id' => $invoice->company_id]);
            }

            if ($applied > 0) {
                $before = $invoice->status;
                $invoice->subtractInvoicePayment($applied);

                // the balance logic derives "previous status" from sent/viewed flags; an issued invoice must never fall back to DRAFT
                if ($invoice->status === Invoice::STATUS_DRAFT) {
                    $invoice->status = $before;
                }
            }
            $invoice->credited_total += $total;
            $invoice->save();

            return $creditNote->load('items', 'invoice', 'customer');
        });
    }

    /**
     * What can still be credited on an invoice, for the credit note form.
     */
    public function creditable(Invoice $invoice): array
    {
        $items = InvoiceItem::where('invoice_id', $invoice->id)->orderBy('id')->get();
        $already = CreditNoteItem::whereIn('invoice_item_id', $items->pluck('id'))
            ->selectRaw('invoice_item_id, SUM(quantity) as qty')->groupBy('invoice_item_id')
            ->pluck('qty', 'invoice_item_id');

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => (int) $invoice->total,
            'credited_total' => (int) $invoice->credited_total,
            'remaining' => max(0, (int) $invoice->total - (int) $invoice->credited_total),
            'due_amount' => (int) $invoice->due_amount,
            'can_credit' => $invoice->status !== Invoice::STATUS_DRAFT && (int) $invoice->total > (int) $invoice->credited_total,
            'items' => $items->map(fn ($item) => [
                'invoice_item_id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'price' => (int) $item->price,
                'total' => (int) $item->total,
                'credited_quantity' => (float) ($already[$item->id] ?? 0),
                'remaining_quantity' => max(0, (float) $item->quantity - (float) ($already[$item->id] ?? 0)),
            ])->values()->all(),
        ];
    }

    private function assignNumber(CreditNote $creditNote): void
    {
        if (! CompanySetting::getSetting('creditnote_number_format', $creditNote->company_id)) {
            CompanySetting::setSettings(['creditnote_number_format' => self::DEFAULT_NUMBER_FORMAT], $creditNote->company_id);
        }

        $serial = (new SerialNumberService)
            ->setModel($creditNote)
            ->setCompany($creditNote->company_id)
            ->setCustomer($creditNote->customer_id)
            ->reserveNextNumbers();

        $creditNote->sequence_number = $serial->nextSequenceNumber;
        $creditNote->customer_sequence_number = $serial->nextCustomerSequenceNumber;
        $creditNote->credit_note_number = $serial->getNextNumber();
        $creditNote->unique_hash = Hashids::connection(Invoice::class)->encode($creditNote->id + 1000000);
        $creditNote->save();
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: int} rows and credited sub total
     */
    private function priceLines(Invoice $invoice, ?array $lines): array
    {
        $items = InvoiceItem::where('invoice_id', $invoice->id)->get()->keyBy('id');
        $already = CreditNoteItem::whereIn('invoice_item_id', $items->keys())
            ->selectRaw('invoice_item_id, SUM(quantity) as qty')->groupBy('invoice_item_id')
            ->pluck('qty', 'invoice_item_id');

        if ($lines === null) {
            $lines = [];
            foreach ($items as $id => $item) {
                $left = (float) $item->quantity - (float) ($already[$id] ?? 0);
                if ($left > 0) {
                    $lines[$id] = $left;
                }
            }
        }

        $rows = [];
        $subTotal = 0;

        foreach ($lines as $itemId => $quantity) {
            $item = $items->get($itemId);

            if (! $item) {
                throw ValidationException::withMessages(['items' => ["Line $itemId is not on this invoice."]]);
            }

            $left = (float) $item->quantity - (float) ($already[$itemId] ?? 0);

            if ($quantity > $left + 0.00001) {
                throw ValidationException::withMessages(['items' => ["Line {$item->name}: only $left left to credit."]]);
            }

            $r = $item->quantity > 0 ? $quantity / $item->quantity : 0;
            $lineTotal = (int) round($item->total * $r);
            $subTotal += $lineTotal;

            $rows[] = [
                'invoice_item_id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'unit_name' => $item->unit_name,
                'quantity' => $quantity,
                'price' => $item->price,
                'discount_val' => (int) round(($item->discount_val ?? 0) * $r),
                'tax' => (int) round(($item->tax ?? 0) * $r),
                'total' => $lineTotal,
            ];
        }

        return [$rows, $subTotal];
    }

    public function getPdfData(CreditNote $creditNote)
    {
        $company = Company::find($creditNote->company_id);
        App::setLocale(CompanySetting::getSetting('language', $company->id) ?? 'en');

        view()->share([
            'creditNote' => $creditNote->load('items', 'invoice', 'customer'),
            'company' => $company,
            'logo' => $company->logo_path ?? null,
            'bankDetails' => CompanySetting::getSetting('bank_details', $company->id) ?? '',
        ]);

        return Pdf::loadView('app.pdf.credit-note.credit-note');
    }
}
