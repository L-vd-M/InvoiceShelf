<?php

namespace App\Services\Address;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Address suggestions from Photon (OpenStreetMap data). Called from this server so the
 * user's browser never contacts the provider. To use another provider, change fetch() only.
 */
class AddressSuggestionService
{
    public const MIN_QUERY_CHARS = 4;

    private const MAX_RESULTS = 5;

    private const CACHE_SECONDS = 3600;

    /**
     * @return array<int, array<string, string|float>>
     *
     * @throws SuggestionsUnavailableException
     */
    public function suggest(string $query, ?string $countryCode = null): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

        if (mb_strlen($text) < self::MIN_QUERY_CHARS) {
            return [];
        }

        $country = $countryCode ? strtoupper($countryCode) : null;
        $key = 'address-suggest:'.md5(mb_strtolower($text).'|'.$country);

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($text, $country) {
            $suggestions = [];

            foreach ($this->fetch($text) as $feature) {
                $suggestion = $this->fromFeature($feature);

                if ($suggestion && (! $country || $suggestion['country_code'] === $country)) {
                    $suggestions[] = $suggestion;
                }

                if (count($suggestions) === self::MAX_RESULTS) {
                    break;
                }
            }

            return $suggestions;
        });
    }

    /**
     * @return array<int, mixed>
     */
    private function fetch(string $text): array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => config('services.photon.user_agent')])
                ->get(config('services.photon.url'), ['q' => $text, 'limit' => 10, 'lang' => 'en'])
                ->throw();
        } catch (\Throwable $e) {
            throw new SuggestionsUnavailableException($e->getMessage(), 0, $e);
        }

        $features = $response->json('features');

        return is_array($features) ? $features : [];
    }

    /**
     * @return array<string, string|float>|null
     */
    private function fromFeature(mixed $feature): ?array
    {
        if (! is_array($feature)) {
            return null;
        }

        $props = $feature['properties'] ?? [];
        $coords = $feature['geometry']['coordinates'] ?? [];

        if (! is_array($props) || count($coords) < 2) {
            return null;
        }

        $street = $props['street'] ?? (($props['type'] ?? null) === 'street' ? ($props['name'] ?? '') : '');
        $town = $props['city'] ?? $props['town'] ?? $props['village'] ?? $props['suburb'] ?? $props['district'] ?? $props['county'] ?? '';

        $number = $this->clean($props['housenumber'] ?? null, 12);
        $street = $this->clean($street, 255);
        $line = trim($number.' '.$street);
        $town = $this->clean($town, 255);
        $state = $this->clean($props['state'] ?? null, 255);
        $zip = $this->clean($props['postcode'] ?? null, 12);

        $label = implode(', ', array_filter([$line, $town, $state, $zip]));

        if ($label === '') {
            $label = $this->clean($props['name'] ?? null, 255);
        }

        if ($label === '') {
            return null;
        }

        return [
            'label' => $label,
            'address_street_1' => $line,
            'city' => $town,
            'state' => $state,
            'zip' => $zip,
            'country_code' => strtoupper((string) ($props['countrycode'] ?? '')),
            'lat' => (float) $coords[1],
            'lng' => (float) $coords[0],
        ];
    }

    private function clean(mixed $value, int $limit): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = preg_replace('/[^\P{C}]+/u', '', (string) $value) ?? '';

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, $limit);
    }
}
