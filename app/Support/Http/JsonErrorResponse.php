<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class JsonErrorResponse
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $extra
     */
    public function make(
        string $message,
        string $errorCode,
        int $status,
        array $errors = [],
        ?string $correlationId = null,
        array $extra = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
        ];

        if ($errors !== []) {
            $payload['errors'] = $this->sanitizeErrors($errors);
        }

        if ($correlationId !== null) {
            $payload['correlation_id'] = $correlationId;
        }

        return response()->json([
            ...$payload,
            ...($status >= 500 ? [] : $this->safeExtras($extra)),
        ], $status);
    }

    public function normalize(Request $request, JsonResponse $response): JsonResponse
    {
        if (! $this->expectsJson($request)) {
            return $response;
        }

        $original = $response->getOriginalContent();
        $data = is_array($original) ? $original : null;

        if ($data === null && $response->getStatusCode() < 400) {
            $decoded = $response->getData(true);
            $data = is_array($decoded) ? $decoded : [];
        }

        $isFailedOperation = ($data['success'] ?? null) === false
            && ($data['type'] ?? null) !== 'no_changes';

        if ($response->getStatusCode() < 400 && ! $isFailedOperation) {
            return $response;
        }

        if ($data === null) {
            $decoded = $response->getData(true);
            $data = is_array($decoded) ? $decoded : [];
        }

        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $isRecordInUse = Arr::has($data, 'data.blocked_records')
            || $this->isKnownRecordInUseMessage($data['message'] ?? null);
        $isStateConflict = Arr::has($data, 'data.conflict_type')
            && Arr::get($data, 'data.conflict_type') !== 'already_active';
        $isDeleteConflict = $request->isMethod('DELETE')
            && $response->getStatusCode() === 422
            && $errors === [];
        $status = ($isRecordInUse || $isStateConflict || $isDeleteConflict)
            ? 409
            : ($response->getStatusCode() < 400 ? 422 : $response->getStatusCode());
        $response->setStatusCode($status);
        $correlationId = $status >= 500
            ? app(JsonExceptionRenderer::class)->correlationId($request)
            : $this->safeCorrelationId($data['correlation_id'] ?? null);

        if ($status >= 500) {
            app(JsonExceptionRenderer::class)->reportResponse($request, $correlationId);
        }
        $errorCode = $this->safeErrorCode($data['error_code'] ?? null)
            ?? ($isRecordInUse ? 'record_in_use' : $this->defaultErrorCode($status, $data, $errors));
        $message = $status >= 500
            ? __('erp_errors.unexpected', ['correlation_id' => $correlationId])
            : $this->safeMessage($data['message'] ?? null, $status);
        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
        ];

        if ($errors !== []) {
            $payload['errors'] = $this->sanitizeErrors($errors);
        }

        if ($correlationId !== null) {
            $payload['correlation_id'] = $correlationId;
        }

        $response->setData([
            ...$payload,
            ...($status >= 500 ? [] : $this->safeExtras($data)),
        ]);

        return $response;
    }

    public function expectsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $errors
     */
    private function defaultErrorCode(int $status, array $data, array $errors): string
    {
        if ($status === 409 && Arr::has($data, 'data.blocked_records')) {
            return 'record_in_use';
        }

        return match ($status) {
            401 => 'authentication_required',
            403 => 'permission_denied',
            404 => 'not_found',
            409 => 'invalid_state',
            419 => 'session_expired',
            422 => $errors === [] ? 'business_rule_violation' : 'validation_failed',
            423 => 'session_locked',
            429 => 'rate_limited',
            default => 'internal_error',
        };
    }

    private function safeMessage(mixed $message, int $status): string
    {
        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        return __(match ($status) {
            401 => 'erp_errors.authentication_required',
            403 => 'erp_errors.permission_denied',
            404 => 'erp_errors.not_found',
            409 => 'erp_errors.concurrent_update',
            419 => 'erp_errors.session_expired',
            422 => 'erp_errors.validation_failed',
            423 => 'erp_errors.session_locked',
            429 => 'erp_errors.rate_limited',
            default => 'erp_errors.validation_failed',
        });
    }

    private function isKnownRecordInUseMessage(mixed $message): bool
    {
        if (! is_string($message) || trim($message) === '') {
            return false;
        }

        $translationKeys = config('erp_errors.record_in_use_translation_keys', []);

        if (! is_array($translationKeys)) {
            return false;
        }

        return collect($translationKeys)
            ->filter(fn (mixed $key): bool => is_string($key))
            ->contains(fn (string $key): bool => __($key) === $message);
    }

    private function safeErrorCode(mixed $errorCode): ?string
    {
        return is_string($errorCode) && preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $errorCode) === 1
            ? $errorCode
            : null;
    }

    private function safeCorrelationId(mixed $correlationId): ?string
    {
        return is_string($correlationId) && preg_match('/^[A-Za-z0-9-]{8,64}$/D', $correlationId) === 1
            ? $correlationId
            : null;
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array<string, list<string>>
     */
    private function sanitizeErrors(array $errors): array
    {
        $sanitized = [];

        foreach ($errors as $field => $messages) {
            if (! is_string($field) || preg_match('/^[A-Za-z0-9_.-]+$/D', $field) !== 1) {
                continue;
            }

            $messages = is_array($messages) ? $messages : [$messages];
            $messages = array_values(array_filter($messages, fn (mixed $message): bool => is_string($message) && trim($message) !== ''));

            if ($messages !== []) {
                $sanitized[$field] = $messages;
            }
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function safeExtras(array $data): array
    {
        return Arr::only($data, [
            'type',
            'data',
            'redirect',
            'redirect_url',
            'submit_action',
            'authenticated',
            'expired',
            'locked',
            'action',
            'lock_screen_url',
        ]);
    }
}
