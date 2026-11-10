<?php

namespace App\Services\Document;

use App\Models\CreditNote;
use App\Models\DocumentRevision;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Writes the append-only history of quotes, invoices and credit notes (spec 07 section 2).
 * Call record() inside the transaction that changes the document, so the revision exists exactly
 * when the change does.
 */
class RevisionService
{
    public const REASONS = ['created', 'edited', 'sent', 'viewed', 'accepted', 'rejected', 'expired', 'converted', 'issued', 'credited', 'voided'];

    public const PDF_DISK = 'local';

    public const SOURCES = ['ui', 'api', 'customer_portal', 'system'];

    public const TYPES = [
        'estimate' => Estimate::class,
        'invoice' => Invoice::class,
        'credit_note' => CreditNote::class,
    ];

    private const HEADER = [
        'invoice' => [
            'invoice_number' => 'str', 'status' => 'str', 'sequence_number' => 'int', 'reference_number' => 'str',
            'invoice_date' => 'date', 'due_date' => 'date', 'issued_at' => 'ts', 'estimate_id' => 'int',
            'template_name' => 'str', 'exchange_rate' => 'num', 'discount_type' => 'str', 'discount' => 'num',
            'discount_val' => 'int', 'sub_total' => 'int', 'tax' => 'int', 'total' => 'int', 'credited_total' => 'int',
            'tax_per_item' => 'str', 'discount_per_item' => 'str', 'notes' => 'str',
        ],
        'estimate' => [
            'estimate_number' => 'str', 'status' => 'str', 'sequence_number' => 'int', 'reference_number' => 'str',
            'estimate_date' => 'date', 'expiry_date' => 'date', 'template_name' => 'str', 'exchange_rate' => 'num',
            'discount_type' => 'str', 'discount' => 'num', 'discount_val' => 'int', 'sub_total' => 'int', 'tax' => 'int',
            'total' => 'int', 'tax_per_item' => 'str', 'discount_per_item' => 'str', 'notes' => 'str',
        ],
        'credit_note' => [
            'credit_note_number' => 'str', 'status' => 'str', 'invoice_id' => 'int', 'credit_note_date' => 'date',
            'reason_code' => 'str', 'reason' => 'str', 'template_name' => 'str', 'exchange_rate' => 'num',
            'discount_type' => 'str', 'discount' => 'num', 'discount_val' => 'int', 'sub_total' => 'int', 'tax' => 'int',
            'total' => 'int', 'applied_amount' => 'int', 'unapplied_amount' => 'int',
        ],
    ];

    // Line ids are left out on purpose: lines are deleted and recreated on every edit, so ids would make every save look changed.
    private const ITEM = [
        'invoice_item_id' => 'int', 'name' => 'str', 'description' => 'str', 'unit_name' => 'str', 'quantity' => 'num',
        'price' => 'int', 'discount_type' => 'str', 'discount' => 'num', 'discount_val' => 'int', 'tax' => 'int', 'total' => 'int',
    ];

    private const TAX = ['tax_type_id' => 'int', 'name' => 'str', 'percent' => 'num', 'amount' => 'int', 'compound_tax' => 'int'];

    /**
     * @param  array{note?: ?string, source?: string, actor_user_id?: ?int, skip_if_unchanged?: bool, pdf?: ?string, created_at?: mixed}  $options
     * @return ?DocumentRevision null when skip_if_unchanged is set and nothing business-relevant changed
     */
    public static function record(Model $document, string $reason, array $options = []): ?DocumentRevision
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown revision reason [{$reason}].");
        }

        // inside a transaction a retry cannot help (the lock already serialises writers); outside, two writers may race for the same number
        $attempts = DB::transactionLevel() > 0 ? 1 : 3;

        for ($try = 1; ; $try++) {
            try {
                return self::write($document, $reason, $options);
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= $attempts) {
                    throw $e;
                }
            }
        }
    }

    private static function write(Model $document, string $reason, array $options): ?DocumentRevision
    {
        $type = self::typeOf($document);

        // the row lock serialises writers of the same document, so revision_no has no gaps or duplicates
        $row = $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

        $previous = DocumentRevision::where('document_type', $type)->where('document_id', $row->getKey())
            ->orderByDesc('revision_no')->first();

        $snapshot = self::snapshot($row, $type);
        $hash = self::hash($snapshot);

        if (($options['skip_if_unchanged'] ?? false) && $previous && $previous->totals_hash === $hash) {
            return null;
        }

        $source = $options['source'] ?? self::detectSource();
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown revision source [{$source}].");
        }

        if (array_key_exists('actor_user_id', $options)) {
            $actorId = $options['actor_user_id'];
            $actorRole = null;
        } else {
            $user = auth()->user();
            $actorId = $user?->getAuthIdentifier();
            $actorRole = $user instanceof User ? self::actorRole($user) : null;
        }

        $revisionNo = ($previous?->revision_no ?? 0) + 1;
        $pdf = $options['pdf'] ?? null;

        if ($pdf !== null) {
            Storage::disk(self::PDF_DISK)->put(self::pdfPathFor($row->company_id, $type, $row->getKey(), $revisionNo), $pdf);
        }

        $attributes = [
            'company_id' => $row->company_id,
            'document_type' => $type,
            'document_id' => $row->getKey(),
            'revision_no' => $revisionNo,
            'reason_code' => $reason,
            'note' => $options['note'] ?? null,
            'status' => $row->status,
            'snapshot' => $snapshot,
            'totals_hash' => $hash,
            'diff_from_previous' => $previous ? self::diff($previous->snapshot, $snapshot) : null,
            'pdf_sha256' => $pdf !== null ? hash('sha256', $pdf) : null,
            'actor_user_id' => $actorId,
            'actor_role' => $actorRole,
            'source' => $source,
        ];

        if (isset($options['created_at'])) {
            $attributes['created_at'] = $options['created_at'];
        }

        return DocumentRevision::create($attributes);
    }

    /**
     * Give every document that has no history yet its first 'created' revision. Idempotent.
     * The snapshot is the state at backfill time, not at the original creation.
     */
    public static function backfill(): int
    {
        $count = 0;

        foreach (self::TYPES as $type => $class) {
            $class::query()
                ->whereNotIn('id', DocumentRevision::where('document_type', $type)->select('document_id'))
                ->orderBy('id')
                ->chunkById(200, function ($documents) use (&$count) {
                    foreach ($documents as $document) {
                        DB::transaction(fn () => self::record($document, 'created', [
                            'note' => 'Backfilled: snapshot is the state when history tracking started.',
                            'source' => 'system',
                            'actor_user_id' => $document->creator_id ?? null,
                            'created_at' => $document->created_at,
                        ]));
                        $count++;
                    }
                });
        }

        return $count;
    }

    public static function snapshot(Model $document, string $type): array
    {
        $document->loadMissing(['customer', 'currency']);

        $snapshot = self::pick($document, self::HEADER[$type]) + [
            'currency' => $document->currency?->code,
            'customer' => $document->customer ? ['id' => (int) $document->customer->id, 'name' => $document->customer->name] : null,
        ];

        if ($type === 'credit_note') {
            $items = $document->items()->orderBy('id')->get();
        } else {
            $items = $document->items()->orderBy('id')->with('taxes')->get();
            $snapshot['taxes'] = $document->taxes()->orderBy('id')->get()->map(fn ($tax) => self::pick($tax, self::TAX))->all();
        }

        $snapshot['items'] = $items->map(function ($item) {
            $line = self::pick($item, self::ITEM);
            if ($item->relationLoaded('taxes')) {
                $line['taxes'] = $item->taxes->sortBy('id')->map(fn ($tax) => self::pick($tax, self::TAX))->values()->all();
            }

            return $line;
        })->all();

        return $snapshot;
    }

    /**
     * SHA-256 of the canonical JSON (object keys sorted) of a snapshot.
     */
    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonical($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function pdfPathFor(int $companyId, string $type, int $documentId, int $revisionNo): string
    {
        return "revisions/{$companyId}/{$type}/{$documentId}/{$revisionNo}.pdf";
    }

    /**
     * The exact PDF stored with an issued or sent revision, or null when none was kept.
     */
    public static function storedPdf(DocumentRevision $revision): ?string
    {
        if ($revision->pdf_sha256 === null) {
            return null;
        }

        $path = self::pdfPathFor($revision->company_id, $revision->document_type, $revision->document_id, $revision->revision_no);

        return Storage::disk(self::PDF_DISK)->exists($path) ? Storage::disk(self::PDF_DISK)->get($path) : null;
    }

    /**
     * Structured changes between two snapshots (see RevisionDiffService).
     */
    public static function diff(array $before, array $after): array
    {
        return RevisionDiffService::diff($before, $after);
    }

    private static function typeOf(Model $document): string
    {
        $type = array_search($document::class, self::TYPES, true);

        if ($type === false) {
            throw new InvalidArgumentException('Revisions are only kept for quotes, invoices and credit notes.');
        }

        return $type;
    }

    private static function detectSource(): string
    {
        if (request()->bearerToken()) {
            return 'api';
        }

        return auth()->check() ? 'ui' : 'system';
    }

    private static function actorRole(User $user): string
    {
        if ($user->isOwner()) {
            return 'owner';
        }

        if ($user->isSuperAdmin()) {
            return 'super admin';
        }

        return (string) ($user->getRoles()->first() ?? 'user');
    }

    private static function pick(Model $model, array $spec): array
    {
        $attributes = $model->getAttributes();
        $out = [];

        foreach ($spec as $key => $kind) {
            if (array_key_exists($key, $attributes)) {
                $out[$key] = self::normalise($model->getAttribute($key), $kind);
            }
        }

        return $out;
    }

    private static function normalise(mixed $value, string $kind): int|string|null
    {
        if ($value === null) {
            return null;
        }

        return match ($kind) {
            'int' => (int) $value,
            // fixed decimal text: float output differs between PHP and JSON column round-trips
            'num' => is_numeric($value) ? rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') : (string) $value,
            'date' => Carbon::parse($value)->format('Y-m-d'),
            'ts' => Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z'),
            default => (string) $value,
        };
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }

        ksort($value);

        return array_map(self::canonical(...), $value);
    }
}
