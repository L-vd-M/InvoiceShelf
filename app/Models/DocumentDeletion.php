<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of every invoice, estimate or payment that was deleted and why.
 * Written in the same transaction as the delete; never updated or removed by the app.
 */
class DocumentDeletion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public static function record(Model $document, string $type, string $reason, ?int $userId = null): self
    {
        return static::create([
            'company_id' => $document->company_id,
            'document_type' => $type,
            'document_id' => $document->id,
            'document_number' => $document->{$type.'_number'} ?? null,
            'sequence_number' => $document->sequence_number,
            'customer_id' => $document->customer_id,
            'total' => $document->total ?? $document->amount ?? null,
            'status_at_deletion' => $document->status ?? null,
            'paid_status_at_deletion' => $document->paid_status ?? null,
            'reason' => $reason,
            'deleted_by' => $userId ?? auth()->id(),
        ]);
    }
}
