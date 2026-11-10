<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\Events;

use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the `data` object of each outbox event exactly as the contract schemas
 * (tarcza-business-hub tests/contracts/schemas/events) require: money as {amount_minor, currency},
 * customer_ref as a string, no null members.
 */
class EventData
{
    public static function money(Model $document, int $minor, ?Invoice $fallbackCurrencyFrom = null): array
    {
        $code = $document->currency?->code ?? $fallbackCurrencyFrom?->currency?->code;

        if (! $code) {
            $id = CompanySetting::getSetting('currency', $document->company_id);
            $code = $id ? Currency::find($id)?->code : null;
        }

        return ['amount_minor' => $minor, 'currency' => $code ?? 'ZAR'];
    }

    public static function customerRef(?Customer $customer): ?string
    {
        if (! $customer) {
            return null;
        }

        $ref = $customer->external_ref;

        return $ref ? "{$ref['source']}:{$ref['id']}" : (string) $customer->id;
    }

    public static function paymentReference(Model $document): ?string
    {
        return match (true) {
            $document instanceof Estimate => $document->estimate_number,
            $document instanceof Invoice => $document->reference_number ?: $document->invoice_number,
            default => null,
        };
    }

    public static function invoiceIssued(Invoice $invoice, ?string $sha256): array
    {
        return self::clean(self::accounting($invoice) + [
            'number' => $invoice->invoice_number,
            'document_type' => 'invoice',
            'customer_ref' => self::customerRef($invoice->customer),
            'total' => self::money($invoice, (int) $invoice->total),
            'issue_date' => $invoice->invoice_date ? Carbon::parse($invoice->invoice_date)->format('Y-m-d') : now()->format('Y-m-d'),
            'due_date' => $invoice->due_date ? Carbon::parse($invoice->due_date)->format('Y-m-d') : null,
            'payment_reference' => self::paymentReference($invoice),
            'sha256' => $sha256,
            'external_ref' => $invoice->external_ref,
            'issued_at' => $invoice->issued_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'sub_total' => (int) $invoice->sub_total,
            'tax' => (int) $invoice->tax,
            'estimate_id' => $invoice->estimate_id,
        ]);
    }

    public static function creditNoteIssued(CreditNote $creditNote, Invoice $invoice, ?string $sha256 = null): array
    {
        return self::clean(self::accounting($creditNote, $invoice) + [
            'number' => $creditNote->credit_note_number,
            'document_type' => 'credit_note',
            'customer_ref' => self::customerRef($invoice->customer),
            'total' => self::money($invoice, (int) $creditNote->total),
            'issue_date' => $creditNote->credit_note_date ? Carbon::parse($creditNote->credit_note_date)->format('Y-m-d') : now()->format('Y-m-d'),
            'payment_reference' => self::paymentReference($invoice),
            'sha256' => $sha256,
            'invoice_number' => $invoice->invoice_number,
            'reason_code' => $creditNote->reason_code,
            'sub_total' => (int) $creditNote->sub_total,
            'tax' => (int) $creditNote->tax,
        ]);
    }

    public static function invoiceVoided(Invoice $invoice, CreditNote $creditNote): array
    {
        // repeats the original document's content so the ledger can reverse it without a lookup
        return self::clean(self::accounting($invoice) + [
            'number' => $invoice->invoice_number,
            'credit_note_number' => $creditNote->credit_note_number,
            'total' => self::money($invoice, (int) $invoice->total),
            'credited_total' => (int) $invoice->credited_total,
        ]);
    }

    public static function quoteAccepted(Estimate $estimate): array
    {
        return self::clean([
            'number' => $estimate->estimate_number,
            'payment_reference' => self::paymentReference($estimate),
            'total' => self::money($estimate, (int) $estimate->total),
            'customer_ref' => self::customerRef($estimate->customer),
            'external_ref' => $estimate->external_ref,
        ]);
    }

    public static function dealWon(Estimate $estimate): array
    {
        return self::clean([
            'number' => $estimate->estimate_number,
            'total' => self::money($estimate, (int) $estimate->total),
            'customer_ref' => self::customerRef($estimate->customer),
        ]);
    }

    public static function dealLost(Estimate $estimate, ?string $reason): array
    {
        return self::clean([
            'number' => $estimate->estimate_number,
            'reason' => $reason,
            'total' => self::money($estimate, (int) $estimate->total),
        ]);
    }

    public static function paymentRecorded(Payment $payment): array
    {
        return self::clean([
            'invoice_number' => $payment->invoice?->invoice_number ?? '',
            'amount' => self::money($payment, (int) $payment->amount, $payment->invoice),
            'external_ref' => $payment->bank_transaction_id,
            'number' => $payment->payment_number,
            'invoice_id' => $payment->invoice_id,
            'payment_date' => $payment->payment_date ? Carbon::parse($payment->payment_date)->format('Y-m-d') : null,
            'transaction_id' => $payment->transaction_id,
        ]);
    }

    public static function documentDeleted(string $type, ?string $number, string $reasonCode, ?string $reason, array $extra = []): array
    {
        return self::clean([
            'reason_code' => $reasonCode,
            'reason' => $reason !== null && $reason !== '' ? $reason : null,
            'document_type' => $type === 'estimate' ? 'quote' : $type,
            'number' => $number,
        ] + $extra);
    }

    /**
     * Contracts 1.3: lines, totals, customer, tenant_vat and (foreign currency) fx. Account-neutral; every
     * amount in the document currency; each line net + tax = gross; the lines add up to `total`.
     */
    public static function accounting(Invoice|CreditNote $document, ?Invoice $invoice = null): array
    {
        $invoice ??= $document instanceof Invoice ? $document : $document->invoice;
        $invoice->loadMissing('customer.billingAddress.country', 'currency', 'taxes');
        $currency = self::money($invoice, 0)['currency'];
        $m = fn (int $minor) => ['amount_minor' => $minor, 'currency' => $currency];

        $isCreditNote = $document instanceof CreditNote;
        $rows = $isCreditNote ? $document->items()->get() : $document->items()->get();
        $kinds = $isCreditNote ? InvoiceItem::whereIn('id', $rows->pluck('invoice_item_id')->filter())->get()->keyBy('id') : collect();

        $registered = (bool) config('invoiceshelf.tenant_vat_registered') || (int) $document->tax > 0;
        $taxBase = max(1, (int) $document->sub_total);
        $rate = $registered ? (int) round(((float) ($invoice->taxes->first()?->percent ?? 0)) * 100) : 0;
        $taxLeft = (int) $document->tax;

        $lines = [];
        $sumGross = 0;

        foreach ($rows as $i => $row) {
            $source = $isCreditNote ? $kinds->get($row->invoice_item_id) : $row;
            $net = (int) $row->total;
            $tax = $registered ? ($i === $rows->count() - 1 ? $taxLeft : (int) round((int) $document->tax * $net / $taxBase)) : 0;
            $taxLeft -= $tax;

            $lines[] = array_filter([
                'kind' => $source?->kind ?: 'other',
                'description' => $row->name ?: 'Item',
                'quantity' => rtrim(rtrim(number_format((float) $row->quantity, 4, '.', ''), '0'), '.') ?: '0',
                'net' => $m($net),
                'tax_code' => $registered ? ($rate > 0 ? 'STANDARD' : 'ZERO') : 'NONE',
                'tax_rate_bps' => $registered ? $rate : 0,
                'tax' => $m($tax),
                'gross' => $m($net + $tax),
                'stream' => $source?->stream,
                'part_ref' => $source?->part_ref,
            ], fn ($v) => $v !== null);
            $sumGross += $net + $tax;
        }

        $discount = $isCreditNote ? 0 : (int) round((float) ($document->discount_val ?? 0));
        if ($discount > 0 && $document->discount_per_item !== 'YES') {
            $lines[] = ['kind' => 'discount', 'description' => 'Discount', 'quantity' => '1', 'net' => $m(-$discount), 'tax_code' => $registered ? 'STANDARD' : 'NONE',
                'tax_rate_bps' => $registered ? $rate : 0, 'tax' => $m(0), 'gross' => $m(-$discount)];
            $sumGross -= $discount;
        }

        // whatever the arithmetic of the document leaves over (tax-inclusive prices, rounding) is stated, never hidden
        $rest = (int) $document->total - $sumGross;
        if ($rest !== 0) {
            $lines[] = ['kind' => 'other', 'description' => 'Rounding', 'quantity' => '1', 'net' => $m($rest), 'tax_code' => $registered ? 'ZERO' : 'NONE',
                'tax_rate_bps' => 0, 'tax' => $m(0), 'gross' => $m($rest)];
        }

        $net = array_sum(array_map(fn ($l) => $l['net']['amount_minor'], $lines));
        $tax = array_sum(array_map(fn ($l) => $l['tax']['amount_minor'], $lines));

        $customer = $invoice->customer;
        $data = [
            'lines' => $lines,
            'totals' => ['net' => $m($net), 'tax' => $m($tax), 'gross' => $m($net + $tax)],
            'customer' => [
                'ref' => self::customerRef($customer),
                'name' => $customer?->company_name ?: ($customer?->name ?: 'Customer'),
                'country' => strtoupper($customer?->billingAddress?->country?->code ?? 'ZA'),
                'currency' => $currency,
            ],
            'tenant_vat' => ['registered' => $registered, 'effective_from' => $registered ? config('invoiceshelf.tenant_vat_effective_from') : null],
        ];

        if ($currency !== 'ZAR') {
            $companyCurrency = ($id = CompanySetting::getSetting('currency', $invoice->company_id)) ? Currency::find($id)?->code : null;
            $known = $companyCurrency === 'ZAR';
            $fxRate = $known ? (float) ($invoice->exchange_rate ?: 1) : 1.0;
            $data['fx'] = [
                'rate' => rtrim(rtrim(number_format($fxRate, 8, '.', ''), '0'), '.') ?: '1',
                'rate_source' => $known ? 'document exchange rate' : 'company currency is not ZAR',
                'rate_date' => Carbon::parse($invoice->invoice_date ?? now())->format('Y-m-d'),
                'zar' => ['amount_minor' => (int) round($data['totals']['gross']['amount_minor'] * $fxRate), 'currency' => 'ZAR'],
            ];
        }

        return $data;
    }

    /** JSON Schema `string` and `object` members must not be null: drop null members recursively. */
    private static function clean(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if ($value === null && $key !== 'effective_from') {
                continue;
            }
            $out[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $out;
    }
}
