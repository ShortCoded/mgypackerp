<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\AuthLog;
use Modules\Core\Services\OperatingContextService;
use Throwable;

class AuthLogService
{
    /**
     * @var list<string>
     */
    private const SensitiveKeys = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        '_token',
        'csrf_token',
        'remember_token',
        'reset_token',
        'authorization',
        'cookie',
        'cookies',
        'session',
        'session_id',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'private_key',
    ];

    public function __construct(
        private readonly OperatingContextService $operatingContext,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(Request $request, string $event, string $status, array $context = []): ?AuthLog
    {
        try {
            $user = $context['user'] ?? null;
            $login = $context['login'] ?? $context['identifier'] ?? $request->input('login') ?? $request->input('email');
            $identifier = $context['identifier'] ?? $login;
            $clientContext = $this->clientContext($request);
            $clientLocation = $this->clientLocation($request);
            $deviceContext = $this->deviceContext($request, $clientContext);
            $networkContext = $this->networkContext($request);
            $locationContext = $this->locationContext($clientLocation);
            $operatingContext = $this->operatingContextColumns($request);

            return AuthLog::create([
                'user_id' => $user instanceof User ? $user->id : ($context['user_id'] ?? null),
                'login' => $this->stringOrNull($login),
                'identifier' => $this->stringOrNull($identifier),
                'email' => $this->stringOrNull($context['email'] ?? null) ?? $this->emailFrom($identifier),
                'phone' => $this->stringOrNull($context['phone'] ?? null) ?? $this->phoneFrom($identifier),
                'username' => $this->stringOrNull($context['username'] ?? null) ?? $this->usernameFrom($identifier),
                'event' => $event,
                'status' => $status,
                'remember_me' => $context['remember_me'] ?? ($request->has('remember') ? $request->boolean('remember') : null),
                'session_fingerprint' => $this->sessionFingerprint($request),
                'request_id' => $this->requestId($request),
                'ip_address' => $request->ip(),
                'client_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'guard' => $context['guard'] ?? 'web',
                'url' => $request->fullUrl(),
                'path' => $request->path(),
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'referrer' => $this->stringOrNull($request->headers->get('referer')),
                'accept_language' => $this->stringOrNull($request->headers->get('accept-language')),
                'locale' => app()->getLocale(),
                'timezone' => $this->stringOrNull(Arr::get($clientContext, 'timezone')) ?? config('app.timezone'),
                'browser_name' => Arr::get($deviceContext, 'browser.name'),
                'browser_version' => Arr::get($deviceContext, 'browser.version'),
                'os_name' => Arr::get($deviceContext, 'os.name'),
                'os_version' => Arr::get($deviceContext, 'os.version'),
                'device_type' => Arr::get($deviceContext, 'device.type'),
                'platform' => $this->stringOrNull(Arr::get($clientContext, 'platform')) ?? Arr::get($deviceContext, 'os.name'),
                'is_mobile' => Arr::get($deviceContext, 'device.is_mobile'),
                'is_tablet' => Arr::get($deviceContext, 'device.is_tablet'),
                'is_desktop' => Arr::get($deviceContext, 'device.is_desktop'),
                'is_bot' => Arr::get($deviceContext, 'device.is_bot'),
                'latitude' => $this->latitudeFrom($clientLocation),
                'longitude' => $this->longitudeFrom($clientLocation),
                'location_accuracy' => $this->accuracyFrom($clientLocation),
                'geo_source' => $this->stringOrNull(Arr::get($clientLocation, 'source')),
                'payload_summary' => $this->payloadSummary($request),
                'failure_reason' => $context['failure_reason'] ?? null,
                'context' => $this->context($context, $request),
                'client_context' => $clientContext ?: null,
                'device_context' => $deviceContext ?: null,
                'location_context' => $locationContext ?: null,
                'network_context' => $networkContext ?: null,
                'logged_in_at' => $context['logged_in_at'] ?? null,
                'logged_out_at' => $context['logged_out_at'] ?? null,
            ] + $operatingContext);
        } catch (Throwable $exception) {
            Log::warning('Auth log write failed.', [
                'event' => $event,
                'status' => $status,
                'exception' => $exception->getMessage(),
            ]);

            report($exception);

            return null;
        }
    }

    public function sessionFingerprint(Request $request): ?string
    {
        try {
            if (! $request->hasSession()) {
                return null;
            }

            $sessionId = $request->session()->getId();
        } catch (Throwable) {
            return null;
        }

        $sessionId = $this->stringOrNull($sessionId);

        if ($sessionId === null) {
            return null;
        }

        return hash_hmac('sha256', $sessionId, $this->hmacKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function deviceSummary(Request $request): array
    {
        return $this->parseUserAgent((string) $request->userAgent());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function safeArray(array $data): array
    {
        return $this->safeData($data);
    }

    /**
     * @return array{fields: list<string>}
     */
    protected function payloadSummary(Request $request): array
    {
        return [
            'fields' => collect(array_keys($request->except(self::SensitiveKeys)))
                ->reject(fn (string|int $field): bool => is_string($field) && $this->isSensitiveKey($field))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    protected function context(array $context, Request $request): ?array
    {
        $reserved = [
            'user',
            'user_id',
            'login',
            'identifier',
            'email',
            'phone',
            'username',
            'guard',
            'remember_me',
            'failure_reason',
            'logged_in_at',
            'logged_out_at',
        ];

        $context = array_diff_key($context, array_flip($reserved));
        $context = array_merge($context, [
            'server' => [
                'locale' => app()->getLocale(),
                'route_name' => $request->route()?->getName(),
                'path' => $request->path(),
            ],
        ]);
        $context = $this->safeData($context);

        return $context === [] ? null : $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function operatingContextColumns(Request $request): array
    {
        if (! Schema::hasTable('auth_logs') || ! Schema::hasColumn('auth_logs', 'branch_id')) {
            return [];
        }

        return $this->operatingContext->snapshot($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function clientContext(Request $request): array
    {
        return $this->requestArray($request, 'client_context');
    }

    /**
     * @return array<string, mixed>
     */
    protected function clientLocation(Request $request): array
    {
        return $this->requestArray($request, 'client_location');
    }

    /**
     * @return array<string, mixed>
     */
    protected function requestArray(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($value)) {
            return [];
        }

        return $this->safeData($value);
    }

    /**
     * @param  array<string, mixed>  $clientContext
     * @return array<string, mixed>
     */
    protected function deviceContext(Request $request, array $clientContext): array
    {
        $summary = $this->parseUserAgent((string) $request->userAgent());

        if ($clientContext !== []) {
            $summary['client'] = array_filter([
                'platform' => $this->stringOrNull($clientContext['platform'] ?? null),
                'vendor' => $this->stringOrNull($clientContext['vendor'] ?? null),
                'hardware_concurrency' => $this->numericOrNull($clientContext['hardware_concurrency'] ?? null),
                'device_memory' => $this->numericOrNull($clientContext['device_memory'] ?? null),
                'screen' => is_array($clientContext['screen'] ?? null) ? $clientContext['screen'] : null,
                'window' => is_array($clientContext['window'] ?? null) ? $clientContext['window'] : null,
            ], fn (mixed $value): bool => $value !== null && $value !== []);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    protected function networkContext(Request $request): array
    {
        return array_filter([
            'client_ip' => $request->ip(),
            'ip_address' => $request->ip(),
            'secure' => $request->isSecure(),
            'ajax' => $request->ajax(),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $clientLocation
     * @return array<string, mixed>
     */
    protected function locationContext(array $clientLocation): array
    {
        if ($clientLocation === []) {
            return [];
        }

        return array_filter([
            'source' => $this->stringOrNull($clientLocation['source'] ?? null),
            'latitude' => $this->latitudeFrom($clientLocation),
            'longitude' => $this->longitudeFrom($clientLocation),
            'accuracy' => $this->accuracyFrom($clientLocation),
            'altitude' => $this->numericOrNull($clientLocation['altitude'] ?? null),
            'altitude_accuracy' => $this->numericOrNull($clientLocation['altitude_accuracy'] ?? null),
            'heading' => $this->numericOrNull($clientLocation['heading'] ?? null),
            'speed' => $this->numericOrNull($clientLocation['speed'] ?? null),
            'timestamp' => $this->numericOrNull($clientLocation['timestamp'] ?? null),
            'captured_at' => $this->stringOrNull($clientLocation['captured_at'] ?? null),
            'attempted_at' => $this->stringOrNull($clientLocation['attempted_at'] ?? null),
            'permission_state' => $this->stringOrNull($clientLocation['permission_state'] ?? null),
            'cache_expires_at' => $this->stringOrNull($clientLocation['cache_expires_at'] ?? null),
            'cached' => $this->boolOrNull($clientLocation['cached'] ?? null),
            'expired' => $this->boolOrNull($clientLocation['expired'] ?? null),
            'skipped' => $this->boolOrNull($clientLocation['skipped'] ?? null),
            'denied' => $this->boolOrNull($clientLocation['denied'] ?? null),
            'unavailable' => $this->boolOrNull($clientLocation['unavailable'] ?? null),
            'timeout' => $this->boolOrNull($clientLocation['timeout'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseUserAgent(string $userAgent): array
    {
        $lower = strtolower($userAgent);
        $browser = $this->browserFromUserAgent($userAgent, $lower);
        $os = $this->osFromUserAgent($userAgent, $lower);
        $isBot = preg_match('/bot|crawler|spider|slurp|curl|wget|headless/i', $userAgent) === 1;
        $isTablet = preg_match('/ipad|tablet|kindle|playbook|silk/i', $userAgent) === 1;
        $isMobile = ! $isTablet && preg_match('/mobile|iphone|ipod|android|blackberry|iemobile|opera mini/i', $userAgent) === 1;
        $deviceType = $isBot ? 'bot' : ($isTablet ? 'tablet' : ($isMobile ? 'mobile' : 'desktop'));

        return [
            'browser' => $browser,
            'os' => $os,
            'device' => [
                'type' => $deviceType,
                'is_mobile' => $isMobile,
                'is_tablet' => $isTablet,
                'is_desktop' => ! $isMobile && ! $isTablet && ! $isBot,
                'is_bot' => $isBot,
            ],
        ];
    }

    /**
     * @return array{name: string|null, version: string|null}
     */
    protected function browserFromUserAgent(string $userAgent, string $lower): array
    {
        $patterns = [
            'Microsoft Edge' => '/Edg\/([0-9.]+)/',
            'Opera' => '/OPR\/([0-9.]+)/',
            'Chrome' => '/Chrome\/([0-9.]+)/',
            'Firefox' => '/Firefox\/([0-9.]+)/',
            'Safari' => '/Version\/([0-9.]+).*Safari/',
            'Internet Explorer' => '/(?:MSIE |rv:)([0-9.]+)/',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return [
                    'name' => $name,
                    'version' => $this->stringOrNull($matches[1] ?? null),
                ];
            }
        }

        if (str_contains($lower, 'bot') || str_contains($lower, 'crawler')) {
            return [
                'name' => 'Bot',
                'version' => null,
            ];
        }

        return [
            'name' => null,
            'version' => null,
        ];
    }

    /**
     * @return array{name: string|null, version: string|null}
     */
    protected function osFromUserAgent(string $userAgent, string $lower): array
    {
        if (preg_match('/Windows NT ([0-9.]+)/', $userAgent, $matches) === 1) {
            return ['name' => 'Windows', 'version' => $matches[1]];
        }

        if (preg_match('/Android ([0-9.]+)/', $userAgent, $matches) === 1) {
            return ['name' => 'Android', 'version' => $matches[1]];
        }

        if (preg_match('/(?:iPhone|iPad).*OS ([0-9_]+)/', $userAgent, $matches) === 1) {
            return ['name' => 'iOS', 'version' => str_replace('_', '.', $matches[1])];
        }

        if (preg_match('/Mac OS X ([0-9_]+)/', $userAgent, $matches) === 1) {
            return ['name' => 'macOS', 'version' => str_replace('_', '.', $matches[1])];
        }

        if (str_contains($lower, 'linux')) {
            return ['name' => 'Linux', 'version' => null];
        }

        return ['name' => null, 'version' => null];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function safeData(array $data, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }

        return collect($data)
            ->reject(fn (mixed $value, string|int $key): bool => is_string($key) && $this->isSensitiveKey($key))
            ->map(function (mixed $value) use ($depth): mixed {
                if ($value instanceof Model) {
                    return null;
                }

                if (is_array($value)) {
                    return $this->safeData($value, $depth + 1);
                }

                if (is_bool($value) || is_numeric($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    return mb_substr($value, 0, 1000);
                }

                return null;
            })
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();
    }

    protected function requestId(Request $request): ?string
    {
        return $this->stringOrNull(
            $request->headers->get('X-Request-Id')
                ?? $request->headers->get('X-Correlation-Id')
                ?? $request->attributes->get('request_id')
        );
    }

    protected function latitudeFrom(array $location): ?float
    {
        $latitude = $this->numericOrNull($location['latitude'] ?? null);

        return $latitude !== null && $latitude >= -90 && $latitude <= 90 ? $latitude : null;
    }

    protected function longitudeFrom(array $location): ?float
    {
        $longitude = $this->numericOrNull($location['longitude'] ?? null);

        return $longitude !== null && $longitude >= -180 && $longitude <= 180 ? $longitude : null;
    }

    protected function accuracyFrom(array $location): ?float
    {
        $accuracy = $this->numericOrNull($location['accuracy'] ?? null);

        return $accuracy !== null && $accuracy >= 0 ? $accuracy : null;
    }

    protected function numericOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    protected function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    protected function emailFrom(mixed $identifier): ?string
    {
        $identifier = $this->stringOrNull($identifier);

        return $identifier !== null && filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? $identifier
            : null;
    }

    protected function phoneFrom(mixed $identifier): ?string
    {
        $identifier = $this->stringOrNull($identifier);

        return $identifier !== null && preg_match('/^\+?[0-9\s\-\(\)]{5,}$/', $identifier) === 1
            ? $identifier
            : null;
    }

    protected function usernameFrom(mixed $identifier): ?string
    {
        $identifier = $this->stringOrNull($identifier);

        if ($identifier === null || $this->emailFrom($identifier) !== null || $this->phoneFrom($identifier) !== null) {
            return null;
        }

        return $identifier;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }

    protected function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, self::SensitiveKeys, true)
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'cookie')
            || str_contains($normalized, 'authorization')
            || str_contains($normalized, 'secret');
    }

    protected function hmacKey(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key !== '' ? $key : config('app.name', 'laravel');
    }
}
