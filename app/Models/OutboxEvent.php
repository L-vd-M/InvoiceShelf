<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only business event, written in the same transaction as the change it describes.
 */
class OutboxEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'data' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Outbox events are append-only.'));
        static::deleting(fn () => throw new LogicException('Outbox events are append-only.'));
    }

    /**
     * The Standard Webhooks body (spec 00 section 3.2).
     */
    public function envelope(): array
    {
        return [
            'type' => $this->type,
            'version' => $this->version,
            'tenant' => config('invoiceshelf.event_tenant'),
            'occurred_at' => $this->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'source' => 'documents',
            'subject' => [
                'kind' => $this->subject_kind,
                'id' => $this->subject_id,
                'ref' => (string) $this->subject_ref,
            ],
            'data' => $this->data,
        ];
    }
}
