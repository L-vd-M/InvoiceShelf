<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;

$normalise = fn (string $uri) => preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim(preg_replace('#^api/v1#', '', $uri), '/'));

test('the document is public, OpenAPI 3.1 and cacheable', function () {
    $response = getJson('api/v1/openapi.json')->assertOk();

    expect($response->json('openapi'))->toStartWith('3.1')
        ->and($response->json('info.title'))->toBe('TARCZA Documents API')
        ->and($response->json('servers.0.url'))->toEndWith('/api/v1')
        ->and($response->headers->get('ETag'))->toStartWith('"');

    getJson('api/v1/openapi.json', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
});

test('it is a superset of the contract kit: every kit operation is described with the same operationId', function () {
    $kit = json_decode(file_get_contents(base_path('tests/Fixtures/contract/documents.v1.json')), true);
    $spec = getJson('api/v1/openapi.json')->json();

    foreach ($kit['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            expect($spec['paths'][$path][$method]['operationId'] ?? null)->toBe($operation['operationId'], "{$method} {$path}");
        }
    }
});

test('every described operation is a registered route and has a unique operationId', function () use ($normalise) {
    $spec = getJson('api/v1/openapi.json')->json();

    $registered = [];
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/')) {
            foreach ($route->methods() as $method) {
                $registered[strtolower($method).' '.$normalise($route->uri())] = true;
            }
        }
    }

    $ids = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            expect($registered)->toHaveKey(strtolower($method).' '.$normalise('api/v1'.$path), "{$method} {$path} is documented but not routed");
            $ids[] = $operation['operationId'];
        }
    }
    expect($ids)->toHaveCount(count(array_unique($ids)));
});

test('the contract operations the application implements are all documented', function () use ($normalise) {
    $spec = getJson('api/v1/openapi.json')->json();
    $documented = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[strtolower($method).' '.$normalise('api/v1'.$path)] = true;
        }
    }

    foreach (['post estimates/{}/accept', 'post invoices/{}/issue', 'get invoices/{}/pdf', 'post invoices/{}/credit-notes', 'post credit-notes/{}/issue', 'post payments',
        'get events', 'post webhook-endpoints', 'get webhook-deliveries', 'post webhook-deliveries/{}/retry', 'get audit-log', 'get exports/documents', 'post integration-tokens'] as $needle) {
        [$method, $path] = explode(' ', $needle);
        expect($documented)->toHaveKey($method.' /'.$path, $needle);
    }
});
