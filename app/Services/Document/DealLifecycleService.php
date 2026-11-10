<?php

namespace App\Services\Document;

use App\Exceptions\DealClosedException;
use App\Models\CompanySetting;
use App\Models\DealLifecycle;
use App\Models\DocumentRevision;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Events\OutboxService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One derived row per quote: how it got from first save to an outcome (spec 07 section 3).
 *
 * The row is always computed from the quote, its revisions and the payments of its invoice, so
 * rebuild() can repair it at any time. The explicit decisions (lost, owner) live on the quote and
 * in its revisions, never only in this table.
 *
 * Outcome rules, first match wins:
 *   won      the quote is ACCEPTED, or its invoice has been issued
 *   lost     the quote was marked lost or is REJECTED
 *   expired  the quote is EXPIRED, or sent/viewed and past its expiry date
 *   open     everything else
 */
class DealLifecycleService
{
    public const DEFAULT_LOST_REASONS = ['price', 'timing', 'competitor', 'no_response', 'other'];

    private const PRICE_KINDS = ['unit_price_changed', 'discount_changed', 'tax_changed', 'quantity_changed', 'line_added', 'line_removed'];

    /**
     * Loss reason codes the company offers (company setting deal_lost_reasons, a JSON list of codes).
     *
     * @return list<string>
     */
    public static function lostReasons(mixed $companyId): array
    {
        $configured = CompanySetting::getSetting('deal_lost_reasons', $companyId);
        $list = is_string($configured) ? json_decode($configured, true) : $configured;

        if (is_array($list)) {
            $codes = array_values(array_filter($list, fn ($code) => is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,29}$/', $code)));

            if ($codes) {
                return $codes;
            }
        }

        return self::DEFAULT_LOST_REASONS;
    }

    public static function ownerId(Estimate $estimate): ?int
    {
        $owner = $estimate->owner_user_id ?? $estimate->creator_id;

        return $owner !== null ? (int) $owner : null;
    }

    /**
     * Recompute the row of one quote. Call inside the transaction that changed the quote, its
     * invoice or a payment. deal.won / deal.lost are written when the outcome changes into won
     * or lost, so a repeated call writes nothing.
     */
    public static function sync(Estimate $estimate, bool $emit = true): ?DealLifecycle
    {
        $fresh = Estimate::query()->with('currency')->find($estimate->getKey());

        if (! $fresh) {
            return null;
        }

        $attributes = self::build($fresh);

        for ($try = 1; ; $try++) {
            try {
                $row = DealLifecycle::firstOrNew(['first_quote_id' => $fresh->id]);
                $previous = $row->exists ? $row->outcome : null;
                $row->fill($attributes)->save();

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 3 || DB::transactionLevel() > 0) {
                    throw $e;
                }
            }
        }

        if ($emit && $previous !== $row->outcome && in_array($row->outcome, ['won', 'lost'], true)) {
            self::emit($row, $fresh);
        }

        return $row;
    }

    public static function syncForInvoice(?int $invoiceId): void
    {
        if (! $invoiceId) {
            return;
        }

        $estimateId = Invoice::query()->whereKey($invoiceId)->value('estimate_id');

        if ($estimateId && ($estimate = Estimate::find($estimateId))) {
            self::sync($estimate);
        }
    }

    /**
     * The row for a quote, built silently when it does not exist yet.
     */
    public static function ensure(Estimate $estimate): DealLifecycle
    {
        return DealLifecycle::where('first_quote_id', $estimate->id)->first() ?? self::sync($estimate, emit: false);
    }

    /**
     * Rebuild every row (or one company's). Idempotent; with emit off it writes no events, which is
     * what the nightly job and the migration backfill use.
     */
    public static function rebuildAll(?int $companyId = null, bool $emit = false): int
    {
        $count = 0;

        Estimate::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->chunkById(200, function ($estimates) use (&$count, $emit) {
                foreach ($estimates as $estimate) {
                    DB::transaction(fn () => self::sync($estimate, $emit));
                    $count++;
                }
            });

        // rows of quotes that no longer exist are dropped
        DealLifecycle::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereNotIn('first_quote_id', Estimate::query()->select('id'))
            ->delete();

        return $count;
    }

    /**
     * Mark a quote lost. Idempotent: the same reason and note again changes nothing.
     */
    public static function markLost(Estimate $estimate, string $reasonCode, ?string $note): DealLifecycle
    {
        if (! in_array($reasonCode, self::lostReasons($estimate->company_id), true)) {
            throw ValidationException::withMessages(['reason_code' => ['Unknown loss reason.']]);
        }

        return DB::transaction(function () use ($estimate, $reasonCode, $note) {
            $locked = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Estimate::STATUS_ACCEPTED || Invoice::where('estimate_id', $locked->id)->whereNotNull('issued_at')->exists()) {
                throw new DealClosedException($locked->estimate_number);
            }

            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $text = $note === null ? $reasonCode : "{$reasonCode}: {$note}";

            $lastLost = DocumentRevision::where('document_type', 'estimate')->where('document_id', $locked->id)
                ->where('reason_code', 'lost')->orderByDesc('revision_no')->first();

            if ($locked->lost_at !== null && $locked->lost_reason === $reasonCode && $lastLost?->note === $text) {
                return self::ensure($locked);
            }

            $locked->forceFill([
                'status' => Estimate::STATUS_REJECTED,
                'lost_reason' => $reasonCode,
                'lost_at' => $locked->lost_at ?? now(),
            ])->save();

            RevisionService::record($locked, 'lost', ['note' => $text]);

            return self::ensure($locked);
        });
    }

    /**
     * Give a quote to another salesperson of the company. Idempotent: the current owner changes nothing.
     */
    public static function assign(Estimate $estimate, int $userId): DealLifecycle
    {
        $user = User::find($userId);

        if (! $user || ! $user->hasCompany((int) $estimate->company_id)) {
            throw ValidationException::withMessages(['user_id' => ['The user does not belong to this company.']]);
        }

        return DB::transaction(function () use ($estimate, $user) {
            $locked = Estimate::query()->whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            $before = self::ownerId($locked);

            if ($before === (int) $user->id) {
                return self::ensure($locked);
            }

            $locked->forceFill(['owner_user_id' => $user->id])->save();

            $from = $before ? User::find($before)?->name : null;
            $revision = RevisionService::record($locked, 'assigned', [
                'note' => 'Owner: '.($from ?? 'nobody').' → '.$user->name,
            ]);

            OutboxService::record('document.revised', 'estimate', $locked, $locked->estimate_number, [
                'revision_id' => $revision?->id,
                'revision_no' => $revision?->revision_no,
                'number' => $locked->estimate_number,
                'reason' => 'assigned',
                'total' => (int) $locked->total,
                'owner_user_id' => (int) $user->id,
                'previous_owner_user_id' => $before,
                'diff_summary' => $revision ? RevisionDiffService::summarise($revision->diff_from_previous ?? []) : null,
            ]);

            return self::ensure($locked);
        });
    }

    private static function build(Estimate $estimate): array
    {
        $revisions = DocumentRevision::where('document_type', 'estimate')->where('document_id', $estimate->id)
            ->orderBy('revision_no')->get(['id', 'revision_no', 'reason_code', 'snapshot', 'created_at']);

        $firstSent = $revisions->firstWhere('reason_code', 'sent');
        $acceptedRevision = $revisions->firstWhere('reason_code', 'accepted');
        $invoice = Invoice::where('estimate_id', $estimate->id)->first();
        $invoiceIssued = $invoice && $invoice->issued_at !== null;

        $acceptedAt = $acceptedRevision?->created_at
            ?? ($estimate->status === Estimate::STATUS_ACCEPTED ? $estimate->updated_at : null);

        [$firstPaidAt, $fullyPaidAt] = $invoice ? self::paymentTimes($invoice) : [null, null];

        [$revisionCount, $priceChangeCount] = self::negotiation($revisions, $firstSent, $acceptedRevision);

        $firstTotal = $firstSent ? ($firstSent->snapshot['total'] ?? null) : ($revisions->first()?->snapshot['total'] ?? null);
        $firstTotal = $firstTotal !== null ? (int) $firstTotal : (int) $estimate->total;
        $finalTotal = $invoiceIssued ? (int) $invoice->total : (int) $estimate->total;

        return [
            'company_id' => $estimate->company_id,
            'customer_id' => $estimate->customer_id,
            'owner_user_id' => self::ownerId($estimate),
            'invoice_id' => $invoice?->id,
            'created_at' => $estimate->created_at,
            'first_sent_at' => $firstSent?->created_at,
            'accepted_at' => $acceptedAt,
            'invoiced_at' => $invoiceIssued ? $invoice->issued_at : null,
            'first_paid_at' => $firstPaidAt,
            'fully_paid_at' => $fullyPaidAt,
            'outcome' => self::outcome($estimate, $invoiceIssued),
            'lost_reason' => $estimate->lost_reason,
            'revision_count' => $revisionCount,
            'price_change_count' => $priceChangeCount,
            'first_total_minor' => $firstTotal,
            'final_total_minor' => $finalTotal,
            'discount_pct_first_to_final' => $firstTotal > 0 ? round(($firstTotal - $finalTotal) / $firstTotal * 100, 4) : null,
            'currency' => $estimate->currency?->code,
            'built_at' => now(),
        ];
    }

    private static function outcome(Estimate $estimate, bool $invoiceIssued): string
    {
        if ($estimate->status === Estimate::STATUS_ACCEPTED || $invoiceIssued) {
            return 'won';
        }

        if ($estimate->lost_at !== null || $estimate->status === Estimate::STATUS_REJECTED) {
            return 'lost';
        }

        $pastExpiry = $estimate->expiry_date
            && in_array($estimate->status, [Estimate::STATUS_SENT, Estimate::STATUS_VIEWED], true)
            && Carbon::parse($estimate->expiry_date)->endOfDay()->isPast();

        if ($estimate->status === Estimate::STATUS_EXPIRED || $pastExpiry) {
            return 'expired';
        }

        return 'open';
    }

    /**
     * Edits made after the first send and before acceptance: how many, and how many moved a price.
     *
     * @return array{0: int, 1: int}
     */
    private static function negotiation($revisions, ?DocumentRevision $firstSent, ?DocumentRevision $accepted): array
    {
        if (! $firstSent) {
            return [0, 0];
        }

        $edits = 0;
        $priceEdits = 0;
        $list = $revisions->values();

        foreach ($list as $index => $revision) {
            $inWindow = $revision->revision_no > $firstSent->revision_no
                && (! $accepted || $revision->revision_no < $accepted->revision_no);

            if (! $inWindow || $revision->reason_code !== 'edited' || $index === 0) {
                continue;
            }

            $edits++;
            $before = $list[$index - 1]->snapshot;
            $after = $revision->snapshot;

            if (($before['total'] ?? null) !== ($after['total'] ?? null) || self::movesPrice($before, $after)) {
                $priceEdits++;
            }
        }

        return [$edits, $priceEdits];
    }

    private static function movesPrice(array $before, array $after): bool
    {
        foreach (RevisionDiffService::diff($before, $after) as $change) {
            if (in_array($change['kind'] ?? null, self::PRICE_KINDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Payment dates are business dates (midnight UTC); fully paid is the date the running total
     * reached the invoice total less what credit notes took off.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private static function paymentTimes(Invoice $invoice): array
    {
        $payments = Payment::where('invoice_id', $invoice->id)->orderBy('payment_date')->orderBy('id')->get(['amount', 'payment_date']);

        if ($payments->isEmpty()) {
            return [null, null];
        }

        $due = (int) $invoice->total - (int) ($invoice->credited_total ?? 0);
        $running = 0;
        $fully = null;

        foreach ($payments as $payment) {
            $running += (int) $payment->amount;

            if ($fully === null && $due > 0 && $running >= $due) {
                $fully = Carbon::parse($payment->payment_date)->startOfDay();
            }
        }

        return [Carbon::parse($payments->first()->payment_date)->startOfDay(), $fully];
    }

    private static function emit(DealLifecycle $row, Estimate $estimate): void
    {
        $invoice = $row->invoice_id ? Invoice::find($row->invoice_id) : null;

        $common = [
            'number' => $estimate->estimate_number,
            'customer_id' => $estimate->customer_id,
            'owner_user_id' => $row->owner_user_id,
            'currency' => $row->currency,
        ];

        if ($row->outcome === 'won') {
            OutboxService::record('deal.won', 'estimate', $estimate, $estimate->estimate_number, $common + [
                'total' => $row->final_total_minor,
                'invoice_id' => $invoice?->id,
                'invoice_number' => $invoice?->invoice_number,
                'accepted_at' => $row->accepted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'paid' => $row->fully_paid_at !== null,
                'fully_paid_at' => $row->fully_paid_at?->format('Y-m-d'),
            ]);

            return;
        }

        OutboxService::record('deal.lost', 'estimate', $estimate, $estimate->estimate_number, $common + [
            'reason_code' => $row->lost_reason,
            'first_total' => $row->first_total_minor,
        ]);
    }
}
