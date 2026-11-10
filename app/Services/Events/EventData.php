<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\Events;

use App\Models\CompanySetting;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
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
        return self::clean([
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
        return self::clean([
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
        return self::clean([
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

    /** JSON Schema `string` and `object` members must not be null: drop null members recursively. */
    private static function clean(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $out;
    }
}
