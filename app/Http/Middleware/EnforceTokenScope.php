<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tokens carry abilities (documents:read|write|issue, events:read, webhooks:manage,
 * analytics:read) and are bound to one company through a `company:<id>` ability. Session and
 * full-access (`*`) tokens are unaffected. A scoped token is denied everything not listed here.
 */
class EnforceTokenScope
{
    public const ABILITIES = [
        'documents:read',
        'documents:write',
        'documents:issue',
        'events:read',
        'webhooks:manage',
        'analytics:read',
    ];

    private const DOCUMENT_PATHS = [
        'invoices', 'estimates', 'credit-notes', 'payments', 'customers', 'items', 'units',
        'tax-types', 'payment-methods', 'next-number', 'number-placeholders', 'current-company',
        'invoice-templates', 'estimate-templates', 'exports', 'audit-log', 'auth',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->can('*')) {
            return $next($request);
        }

        foreach ((array) $token->abilities as $ability) {
            if (str_starts_with($ability, 'company:')) {
                $request->headers->set('company', substr($ability, 8));
            }
        }

        $required = $this->requiredAbility($request);

        if ($required === null || ! $token->can($required)) {
            return Problem::response(403, 'insufficient_ability', 'Token lacks the required ability', $required ? "This token needs the {$required} ability." : 'This endpoint cannot be used with an integration token.');
        }

        return $next($request);
    }

    private function requiredAbility(Request $request): ?string
    {
        $segments = explode('/', trim(preg_replace('#^api/v1/#', '', $request->path()), '/'));
        $first = $segments[0] ?? '';
        $last = end($segments);
        $read = in_array($request->method(), ['GET', 'HEAD'], true);

        return match (true) {
            $first === 'events' => $read ? 'events:read' : null,
            in_array($first, ['webhook-endpoints', 'webhook-deliveries'], true) => 'webhooks:manage',
            $first === 'analytics' => $read ? 'analytics:read' : null,
            ! in_array($first, self::DOCUMENT_PATHS, true) => null,
            $read => 'documents:read',
            $last === 'issue' => 'documents:issue',
            default => 'documents:write',
        };
    }
}
