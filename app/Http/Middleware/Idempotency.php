<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\Problem;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` on POST/PUT/PATCH/DELETE: the same key and body within 24 h returns the
 * first response (header `Idempotent-Replayed: true`); the same key with a different body or
 * target is refused with 422 `idempotency_conflict`. 5xx and non-JSON responses are not stored,
 * so the client may retry.
 */
class Idempotency
{
    private const TTL_HOURS = 24;

    /** An in-flight request older than this is treated as crashed and may be retried. */
    private const STALE_LOCK_SECONDS = 120;

    private const REPLAYED_HEADERS = ['Content-Type', 'Location', 'ETag'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) {
            return Problem::response(422, 'idempotency_key_invalid', 'Invalid Idempotency-Key', 'Use 8 to 80 characters: letters, digits, dash, underscore (a ULID is ideal).');
        }

        $company = (int) $request->header('company');
        $hash = $this->fingerprint($request);

        IdempotencyKey::where('company_id', $company)->where('key', $key)->where('expires_at', '<', now())->delete();
        IdempotencyKey::where('company_id', $company)->where('key', $key)->whereNull('response_status')
            ->where('locked_at', '<', now()->subSeconds(self::STALE_LOCK_SECONDS))->delete();

        try {
            $record = IdempotencyKey::create([
                'company_id' => $company,
                'key' => $key,
                'method' => $request->method(),
                'path' => $request->path(),
                'request_hash' => $hash,
                'locked_at' => now(),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($company, $key, $request, $hash);
        }

        $response = $next($request);

        $json = str_contains((string) $response->headers->get('Content-Type'), 'json');
        if ($response->getStatusCode() >= 500 || ! $json) {
            $record->delete();

            return $response;
        }

        $record->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'response_headers' => collect(self::REPLAYED_HEADERS)
                ->mapWithKeys(fn ($h) => [$h => $response->headers->get($h)])->filter()->all(),
            'locked_at' => null,
        ]);

        return $response;
    }

    private function existing(int $company, string $key, Request $request, string $hash): Response
    {
        $record = IdempotencyKey::where('company_id', $company)->where('key', $key)->first();

        if (! $record) {
            return Problem::response(409, 'idempotency_in_progress', 'Request in progress', 'Retry shortly.', [], ['Retry-After' => '1']);
        }

        if ($record->request_hash !== $hash || $record->method !== $request->method() || $record->path !== $request->path()) {
            return Problem::response(422, 'idempotency_conflict', 'Idempotency-Key reused with a different request', 'This key was already used for another method, path or body within 24 hours.');
        }

        if ($record->response_status === null) {
            return Problem::response(409, 'idempotency_in_progress', 'Request in progress', 'The first request with this key has not finished. Retry shortly.', [], ['Retry-After' => '1']);
        }

        return response($record->response_body, $record->response_status, ($record->response_headers ?? []) + ['Idempotent-Replayed' => 'true']);
    }

    private function fingerprint(Request $request): string
    {
        $body = $request->getContent();
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $body = json_encode($this->sorted($decoded));
        }

        return hash('sha256', $request->method()."\n".$request->path()."\n".$body);
    }

    private function sorted(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->sorted($v);
            }
        }

        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }
}
