<?php

namespace App\Services\Events;

use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebhookDeliveryService
{
    /** Seconds to wait after the Nth failed attempt; the last value repeats. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600, 43200, 86400];

    /** Give up (dead letter) when the next attempt would fall this long after the first. */
    public const WINDOW_SECONDS = 72 * 3600;

    public static function delay(int $attempts): int
    {
        return self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1] ?? end(self::BACKOFF);
    }

    /**
     * Try one delivery. Returns true when the consumer answered 2xx.
     */
    public function deliver(WebhookDelivery $delivery): bool
    {
        // lease: a second worker cannot take the same delivery while this attempt runs
        $leased = WebhookDelivery::whereKey($delivery->id)
            ->where('state', WebhookDelivery::PENDING)
            ->where('next_attempt_at', '<=', now())
            ->update(['next_attempt_at' => now()->addMinutes(5)]);

        if ($leased === 0) {
            return false;
        }

        $delivery->refresh()->load('event', 'endpoint');
        $event = $delivery->event;
        $endpoint = $delivery->endpoint;

        $status = null;
        $error = null;

        try {
            if ($reason = WebhookUrlGuard::blockedReason($endpoint->url)) {
                throw new \RuntimeException($reason);
            }

            $body = json_encode($event->envelope(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = now()->timestamp;

            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'webhook-id' => $event->event_id,
                    'webhook-timestamp' => (string) $timestamp,
                    'webhook-signature' => WebhookSigner::sign($endpoint->secret, $event->event_id, $timestamp, $body),
                ])
                ->timeout(10)
                ->withoutRedirecting()
                ->post($endpoint->url);

            $status = $response->status();

            if ($response->successful()) {
                $delivery->update([
                    'state' => WebhookDelivery::DELIVERED,
                    'attempts' => $delivery->attempts + 1,
                    'last_status' => $status,
                    'last_error' => null,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                ]);

                return true;
            }

            $error = "HTTP {$status}";
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        $this->recordFailure($delivery, $status, $error);

        return false;
    }

    private function recordFailure(WebhookDelivery $delivery, ?int $status, ?string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $next = now()->addSeconds(self::delay($attempts));
        $started = $delivery->window_started_at ?? $delivery->created_at;
        $dead = $next->diffInSeconds($started, true) > self::WINDOW_SECONDS;

        $delivery->update([
            'state' => $dead ? WebhookDelivery::DEAD : WebhookDelivery::PENDING,
            'attempts' => $attempts,
            'last_status' => $status,
            'last_error' => $error,
            'next_attempt_at' => $dead ? null : $next,
        ]);
    }
}
