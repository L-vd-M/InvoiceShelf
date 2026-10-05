<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
    Cache::flush();

    $user = User::find(1);

    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);

    Sanctum::actingAs($user, ['*']);
});

function photonFeature(array $props, array $coords = [18.42, -33.92]): array
{
    return ['geometry' => ['coordinates' => $coords], 'properties' => $props];
}

test('suggest maps Photon features to address parts', function () {
    Http::fake(['*' => Http::response(['features' => [
        photonFeature([
            'housenumber' => '12',
            'street' => 'Long Street',
            'city' => 'Cape Town',
            'state' => 'Western Cape',
            'postcode' => '8001',
            'countrycode' => 'ZA',
        ]),
    ]])]);

    getJson('/api/v1/address/suggest?q=12 Long Street')
        ->assertOk()
        ->assertJsonPath('suggestions.0.label', '12 Long Street, Cape Town, Western Cape, 8001')
        ->assertJsonPath('suggestions.0.address_street_1', '12 Long Street')
        ->assertJsonPath('suggestions.0.city', 'Cape Town')
        ->assertJsonPath('suggestions.0.state', 'Western Cape')
        ->assertJsonPath('suggestions.0.zip', '8001')
        ->assertJsonPath('suggestions.0.country_code', 'ZA')
        ->assertJsonPath('suggestions.0.lat', -33.92)
        ->assertJsonPath('suggestions.0.lng', 18.42);
});

test('suggest filters by country and caps results at five', function () {
    $features = [photonFeature(['name' => 'Elsewhere Road', 'street' => 'Elsewhere Road', 'countrycode' => 'NA'])];
    for ($i = 1; $i <= 8; $i++) {
        $features[] = photonFeature(['street' => "Road $i", 'city' => 'Town', 'countrycode' => 'ZA']);
    }
    Http::fake(['*' => Http::response(['features' => $features])]);

    $response = getJson('/api/v1/address/suggest?q=Road&cc=za')->assertOk();

    expect($response->json('suggestions'))->toHaveCount(5)
        ->and(collect($response->json('suggestions'))->pluck('country_code')->unique()->all())->toBe(['ZA']);
});

test('suggest skips the provider for queries under four characters', function () {
    Http::fake();

    getJson('/api/v1/address/suggest?q=abc')
        ->assertOk()
        ->assertJson(['suggestions' => []]);

    Http::assertNothingSent();
});

test('suggest caches repeated queries', function () {
    Http::fake(['*' => Http::response(['features' => [photonFeature(['street' => 'Long Street', 'city' => 'Cape Town', 'countrycode' => 'ZA'])]])]);

    getJson('/api/v1/address/suggest?q=Long Street')->assertOk();
    getJson('/api/v1/address/suggest?q=long  street')->assertOk();

    Http::assertSentCount(1);
});

test('suggest reports unavailable when the provider fails', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    getJson('/api/v1/address/suggest?q=Long Street')
        ->assertOk()
        ->assertJson(['suggestions' => [], 'error' => 'unavailable']);
});

test('suggest validates input', function () {
    getJson('/api/v1/address/suggest')->assertUnprocessable();
    getJson('/api/v1/address/suggest?q=Long Street&cc=ZAF')->assertUnprocessable();
});
