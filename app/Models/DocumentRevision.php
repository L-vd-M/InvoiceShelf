<?php

namespace App\Models;

use App\Services\Document\RevisionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only snapshot of a quote, invoice or credit note, written in the same transaction as
 * the change it records (spec 07 section 2).
 */
class DocumentRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot' => 'array',
        'diff_from_previous' => 'array',
        'revision_no' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Document revisions are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Recompute the hash of the stored snapshot; false means the row was altered after it was written.
     */
    public function hashMatches(): bool
    {
        return hash_equals($this->totals_hash, RevisionService::hash($this->snapshot));
    }
}
