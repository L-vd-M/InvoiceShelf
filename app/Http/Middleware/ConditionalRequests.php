<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Problem;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optimistic concurrency on single documents: every response about one document carries its
 * `ETag`; a change request with a stale `If-Match` is refused with 412. Integration (scoped)
 * tokens must send `If-Match` (428 otherwise); the web UI may omit it.
 */
class ConditionalRequests
{
    private const MODELS = [Invoice::class, Estimate::class, CreditNote::class, Customer::class, Payment::class];

    public function handle(Request $request, Closure $next): Response
    {
        $model = $this->boundDocument($request);

        if (! $model) {
            return $next($request);
        }

        $changes = in_array($request->method(), ['PUT', 'PATCH', 'POST', 'DELETE'], true);

        if ($changes) {
            $ifMatch = $request->header('If-Match');

            if ($ifMatch === null && $this->isScopedToken($request)) {
                return Problem::response(428, 'precondition_required', 'If-Match required', 'Send the ETag from your last GET of this document in If-Match.');
            }

            if ($ifMatch !== null && $ifMatch !== '*' && ! $this->matches($ifMatch, self::etag($model))) {
                return Problem::response(412, 'precondition_failed', 'Document changed', 'The document was modified since you read it. GET it again and retry.', [], ['ETag' => self::etag($model)]);
            }
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && $request->method() !== 'DELETE') {
            $fresh = $model->fresh();

            if ($fresh) {
                $etag = self::etag($fresh);
                $response->headers->set('ETag', $etag);

                if ($request->isMethod('GET') && $this->matches((string) $request->header('If-None-Match'), $etag)) {
                    return response('', 304, ['ETag' => $etag]);
                }
            }
        }

        return $response;
    }

    /** Hash of the stored row; any save (items and totals touch the parent row) changes it. */
    public static function etag(Model $model): string
    {
        return '"'.substr(sha1($model::class.'|'.$model->getKey().'|'.json_encode($model->getAttributes())), 0, 32).'"';
    }

    private function boundDocument(Request $request): ?Model
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            foreach (self::MODELS as $class) {
                if ($parameter instanceof $class) {
                    return $parameter;
                }
            }
        }

        return null;
    }

    private function matches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            if (trim(preg_replace('#^W/#', '', trim($candidate))) === $etag) {
                return true;
            }
        }

        return false;
    }

    private function isScopedToken(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken && ! $token->can('*');
    }
}
