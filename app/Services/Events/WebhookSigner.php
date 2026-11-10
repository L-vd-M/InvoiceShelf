<?php

namespace App\Services\Events;

/**
 * Standard Webhooks signing: HMAC-SHA256 over "{id}.{timestamp}.{body}", key is the base64 part of "whsec_...".
 */
class WebhookSigner
{
    public static function sign(string $secret, string $messageId, int $timestamp, string $body): string
    {
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true) ?: $secret;

        return 'v1,'.base64_encode(hash_hmac('sha256', "{$messageId}.{$timestamp}.{$body}", $key, true));
    }
}
