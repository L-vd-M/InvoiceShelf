<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Support;

use Illuminate\Http\JsonResponse;

/** RFC 9457 problem+json with a stable machine-readable `code`. */
class Problem
{
    public static function response(int $status, string $code, string $title, ?string $detail = null, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(array_filter([
            'type' => 'https://docs.tarcza-electronics.com/problems/'.$code,
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
        ], fn ($v) => $v !== null) + $extra, $status, ['Content-Type' => 'application/problem+json'] + $headers);
    }
}
