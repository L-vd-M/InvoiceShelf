<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services\Contract;

use App\Models\CreditNote;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Services\Events\EventData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * The integration representation of a document (spec 08, openapi `Document`): stable field names,
 * money as {amount_minor, currency}, status in draft|issued|accepted|voided. Extra members are additive.
 */
class ContractPresenter
{
    public static function document(Model $document): array
    {
        $document->loadMissing('customer', 'currency');

        $base = match (true) {
            $document instanceof Estimate => self::estimate($document),
            $document instanceof Invoice => self::invoice($document),
            $document instanceof CreditNote => self::creditNote($document),
        };

        $base = array_filter($base, fn ($v, $k) => $v !== null || in_array($k, ['number', 'external_ref'], true), ARRAY_FILTER_USE_BOTH);

        return $base + ['updated_at' => Carbon::parse($document->updated_at)->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    private static function estimate(Estimate $estimate): array
    {
        return [
            'id' => (string) $estimate->id,
            'number' => $estimate->estimate_number,
            'status' => match ($estimate->status) {
                Estimate::STATUS_ACCEPTED => 'accepted',
                Estimate::STATUS_REJECTED, Estimate::STATUS_EXPIRED => 'voided',
                Estimate::STATUS_SENT, Estimate::STATUS_VIEWED => 'issued',
                default => 'draft',
            },
            'customer_ref' => EventData::customerRef($estimate->customer),
            'total' => EventData::money($estimate, (int) $estimate->total),
            'payment_reference' => EventData::paymentReference($estimate),
            'external_ref' => $estimate->external_ref,
            'native_status' => $estimate->status,
            'invoice_id' => $estimate->invoice?->id ? (string) $estimate->invoice->id : null,
        ];
    }

    private static function invoice(Invoice $invoice): array
    {
        $issued = $invoice->isIssued();

        return [
            'id' => (string) $invoice->id,
            'number' => $issued ? $invoice->invoice_number : null,
            'status' => match (true) {
                ! $issued => 'draft',
                (int) $invoice->credited_total >= (int) $invoice->total && (int) $invoice->total > 0 => 'voided',
                default => 'issued',
            },
            'customer_ref' => EventData::customerRef($invoice->customer),
            'total' => EventData::money($invoice, (int) $invoice->total),
            'payment_reference' => EventData::paymentReference($invoice),
            'external_ref' => $invoice->external_ref,
            'paid_status' => $invoice->paid_status,
            'due_amount' => EventData::money($invoice, (int) $invoice->due_amount),
            'issue_date' => $invoice->invoice_date ? Carbon::parse($invoice->invoice_date)->format('Y-m-d') : null,
            'due_date' => $invoice->due_date ? Carbon::parse($invoice->due_date)->format('Y-m-d') : null,
        ];
    }

    private static function creditNote(CreditNote $creditNote): array
    {
        $creditNote->loadMissing('invoice.customer', 'invoice.currency');
        $invoice = $creditNote->invoice;

        return [
            'id' => (string) $creditNote->id,
            'number' => $creditNote->credit_note_number,
            'status' => 'issued',
            'customer_ref' => EventData::customerRef($invoice->customer),
            'total' => EventData::money($invoice, (int) $creditNote->total),
            'payment_reference' => EventData::paymentReference($invoice),
            'external_ref' => null,
            'invoice_id' => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ];
    }
}
