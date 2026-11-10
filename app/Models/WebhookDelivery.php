<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const DEAD = 'dead';

    protected $guarded = ['id'];

    protected $casts = [
        'window_started_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
