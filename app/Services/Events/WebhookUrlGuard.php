<?php

namespace App\Services\Events;

use App\Support\Net\PrivateNetworkGuard;

class WebhookUrlGuard
{
    /**
     * Reason the URL must not be used as a webhook target, or null when allowed.
     * Hosts in config('invoiceshelf.webhook_allowed_hosts') may be private and plain http.
     */
    public static function blockedReason(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if ($host !== '' && in_array($host, array_map('strtolower', config('invoiceshelf.webhook_allowed_hosts', [])), true)) {
            return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ? null : 'URL scheme must be http or https';
        }

        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            return 'Webhook URL must use https';
        }

        return PrivateNetworkGuard::blockedReason($url) ?? ($host === '' ? 'URL must include a host' : null);
    }
}
