<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Documents API description (OpenAPI 3.1): a superset of the contract kit's documents.v1.yaml.
 * Built by tools/build_openapi.py in the wrapper repository; contains no secrets, so it is public.
 */
class OpenApiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $path = resource_path('openapi/documents.v1.json');
        $etag = '"'.substr(sha1_file($path), 0, 32).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        $spec = json_decode(file_get_contents($path), true);
        $spec['servers'] = [['url' => url('/api/v1')]];

        return response()->json($spec, 200, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300']);
    }
}
