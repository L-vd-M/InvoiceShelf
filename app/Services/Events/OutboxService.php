<?php

namespace App\Services\Events;

use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OutboxService
{
    public const TYPES = [
        'document.issued',
        'document.voided',
        'document.deleted',
        'quote.accepted',
        'payment.recorded',
    ];

    /**
     * Write an event and one pending delivery per subscribed endpoint. Call inside the
     * transaction that changes the document, so the event exists exactly when the change does.
     */
    public static function record(string $type, string $subjectKind, Model $subject, ?string $subjectNumber, array $data): OutboxEvent
    {
        $event = OutboxEvent::create([
            'event_id' => 'msg_'.Str::lower((string) Str::ulid()),
            'company_id' => $subject->company_id,
            'type' => $type,
            'version' => 1,
            'subject_kind' => $subjectKind,
            'subject_id' => $subjectNumber,
            'subject_ref' => $subject->getKey(),
            'data' => $data,
            'occurred_at' => now(),
        ]);

        WebhookEndpoint::where('company_id', $event->company_id)->where('enabled', true)->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->wants($type))
            ->each(fn (WebhookEndpoint $endpoint) => WebhookDelivery::create([
                'outbox_event_id' => $event->id,
                'webhook_endpoint_id' => $endpoint->id,
                'company_id' => $event->company_id,
                'state' => WebhookDelivery::PENDING,
                'window_started_at' => now(),
                'next_attempt_at' => now(),
            ]));

        return $event;
    }
}
