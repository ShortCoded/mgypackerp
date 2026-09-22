<?php

namespace Modules\HR\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Validation\ValidationException;

final class MapCoordinatesService
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{latitude: string, longitude: string}
     */
    public function coordinates(string $url): array
    {
        $url = trim($url);
        $this->assertAllowedUrl($url);

        $coordinates = $this->parseCoordinates($url);

        if ($coordinates !== null) {
            return $coordinates;
        }

        $resolvedUrl = $this->resolveRedirects($url);
        $coordinates = $this->parseCoordinates($resolvedUrl);

        if ($coordinates === null) {
            throw ValidationException::withMessages([
                'map_url' => __('hr_attendance_settings.validation.coordinates_not_found'),
            ]);
        }

        return $coordinates;
    }

    /**
     * @return array{latitude: string, longitude: string}|null
     */
    private function parseCoordinates(string $url): ?array
    {
        $decoded = rawurldecode($url);
        $patterns = [
            '/\/@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)(?:[,\/]|$)/',
            '/[?&](?:q|query|ll|center)=(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)(?:[&]|$)/i',
            '/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $decoded, $matches) !== 1) {
                continue;
            }

            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];

            if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                continue;
            }

            return [
                'latitude' => number_format($latitude, 7, '.', ''),
                'longitude' => number_format($longitude, 7, '.', ''),
            ];
        }

        return null;
    }

    private function resolveRedirects(string $url): string
    {
        $currentUrl = $url;

        for ($redirect = 0; $redirect < 5; $redirect++) {
            $response = $this->http
                ->connectTimeout(3)
                ->timeout(6)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['User-Agent' => config('app.name').' attendance-location-resolver'])
                ->get($currentUrl);

            if (! $response->redirect()) {
                return $currentUrl;
            }

            $location = trim((string) $response->header('Location'));

            if ($location === '') {
                break;
            }

            $currentUrl = $this->absoluteRedirectUrl($currentUrl, $location);
            $this->assertAllowedUrl($currentUrl);
        }

        return $currentUrl;
    }

    private function absoluteRedirectUrl(string $baseUrl, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');

        return $scheme.'://'.$host.'/'.ltrim($location, '/');
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $isGoogleMapsHost = $host === 'maps.google.com'
            || $host === 'www.google.com'
            || $host === 'google.com'
            || $host === 'maps.google.com.eg'
            || $host === 'www.google.com.eg'
            || $host === 'google.com.eg';
        $isShortMapsHost = $host === 'maps.app.goo.gl' || $host === 'goo.gl';

        if (! in_array($scheme, ['http', 'https'], true)
            || (! $isGoogleMapsHost && ! $isShortMapsHost)
            || ($isGoogleMapsHost && ! str_starts_with($path, '/maps'))) {
            throw ValidationException::withMessages([
                'map_url' => __('hr_attendance_settings.validation.google_maps_url'),
            ]);
        }
    }
}
