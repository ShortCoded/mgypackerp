<?php

namespace Modules\Auth\Http\Requests;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\UserAccountStatusService;
use Modules\Auth\Services\UserPresenceService;

class LoginRequest extends FormRequest
{
    private ?string $accountThrottleKey = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'login' => __('auth.login_identifier'),
            'password' => __('auth.password'),
            'remember' => __('auth.remember_me'),
        ];
    }

    public function authenticate(
        AuthLogService $authLogService,
        UserAccountStatusService $accounts,
        UserPresenceService $presence
    ): User {
        $identifier = $this->string('login')->trim()->toString();
        $this->ensureIsNotRateLimited($authLogService);

        $resolution = $accounts->resolveForLogin($identifier);
        $user = $resolution->user;
        $this->accountThrottleKey = $user instanceof User
            ? $this->accountThrottleKey($user)
            : null;

        $this->ensureIsNotRateLimited($authLogService);
        $inactiveReason = $accounts->inactiveReason($user);

        if ($inactiveReason !== null && $inactiveReason !== 'missing_account') {
            $this->hitRateLimits();

            $authLogService->log($this, $this->failedLoginEvent($inactiveReason), 'failed', [
                'user' => $user,
                'login' => $identifier,
                'remember_me' => $this->boolean('remember'),
                'failure_reason' => $inactiveReason,
            ]);

            throw ValidationException::withMessages([
                'login' => __('auth.messages.invalid_credentials'),
            ]);
        }

        if (! $user instanceof User || ! Hash::check($this->string('password')->toString(), $user->password)) {
            $this->hitRateLimits();

            $authLogService->log($this, 'login_failed_invalid_credentials', 'failed', [
                'user' => $user,
                'login' => $identifier,
                'remember_me' => $this->boolean('remember'),
                'failure_reason' => $resolution->ambiguous ? 'ambiguous_identifier' : 'invalid_credentials',
            ]);

            throw ValidationException::withMessages([
                'login' => __('auth.messages.invalid_credentials'),
            ]);
        }

        $activeSessionsCount = $presence->anotherFreshActiveSessionCountForRequest($user, $this);

        if ($activeSessionsCount > 0) {
            $authLogService->log($this, 'login_blocked_already_online', 'blocked', [
                'user' => $user,
                'login' => $identifier,
                'remember_me' => $this->boolean('remember'),
                'failure_reason' => 'already_online',
                'active_sessions_count' => $activeSessionsCount,
            ]);

            throw ValidationException::withMessages([
                'login' => __('auth.messages.already_logged_in'),
            ]);
        }

        return $user;
    }

    public function clearRateLimit(): void
    {
        foreach (array_keys($this->rateLimitKeys()) as $key) {
            RateLimiter::clear($key);
        }
    }

    public function ensureIsNotRateLimited(AuthLogService $authLogService): void
    {
        foreach ($this->rateLimitKeys() as $key => $maxAttempts) {
            if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                continue;
            }

            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($key);
            $identifier = $this->string('login')->trim()->toString();

            $authLogService->log($this, 'login_throttled', 'blocked', [
                'login' => $identifier,
                'remember_me' => $this->boolean('remember'),
                'failure_reason' => 'too_many_attempts',
                'seconds_remaining' => $seconds,
            ]);

            throw ValidationException::withMessages([
                'login' => __('auth.too_many_attempts', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ])->status(429);
        }
    }

    public function throttleKey(): string
    {
        return $this->accountThrottleKey ?? $this->identifierThrottleKey();
    }

    private function hitRateLimits(): void
    {
        foreach (array_keys($this->rateLimitKeys()) as $key) {
            RateLimiter::hit($key);
        }
    }

    /**
     * @return array<string, int>
     */
    private function rateLimitKeys(): array
    {
        $keys = [
            $this->identifierThrottleKey() => 5,
            $this->ipThrottleKey() => 20,
        ];

        if (is_string($this->accountThrottleKey)) {
            $keys[$this->accountThrottleKey] = 5;
        }

        return $keys;
    }

    private function identifierThrottleKey(): string
    {
        $identifier = Str::transliterate(Str::lower($this->string('login')->trim()->toString()));

        return 'login|identifier:'.hash('sha256', $identifier).'|ip:'.$this->ip();
    }

    private function accountThrottleKey(User $user): string
    {
        return 'login|user:'.$user->getAuthIdentifier().'|ip:'.$this->ip();
    }

    private function ipThrottleKey(): string
    {
        return 'login|ip:'.$this->ip();
    }

    protected function failedValidation(Validator $validator): void
    {
        app(AuthLogService::class)->log($this, 'login_failed_validation', 'failed', [
            'login' => $this->input('login'),
            'remember_me' => $this->boolean('remember'),
            'failure_reason' => 'validation_failed',
            'validation_fields' => $this->safeValidationFields($validator),
        ]);

        parent::failedValidation($validator);
    }

    protected function failedLoginEvent(string $reason): string
    {
        return match ($reason) {
            'inactive_account' => 'login_failed_inactive_user',
            'blocked_account' => 'login_failed_blocked_user',
            'deleted_account' => 'login_failed_deleted_user',
            default => 'login_failed_invalid_credentials',
        };
    }

    /**
     * @return list<string>
     */
    protected function safeValidationFields(Validator $validator): array
    {
        return collect(array_keys($validator->errors()->messages()))
            ->reject(fn (string $field): bool => in_array($field, ['password', 'password_confirmation', 'token', '_token', 'remember_token'], true))
            ->values()
            ->all();
    }
}
