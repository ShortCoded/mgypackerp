<?php

namespace App\Support\Http;

use App\Support\Database\PostgresConstraintExceptionMapper;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class JsonExceptionRenderer
{
    private const CorrelationAttribute = 'erp_correlation_id';

    private const LoggedAttribute = 'erp_unexpected_error_logged';

    public function __construct(
        private readonly JsonErrorResponse $errors,
        private readonly PostgresConstraintExceptionMapper $databaseErrors,
    ) {}

    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $this->errors->expectsJson($request)) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            $status = $exception->status >= 400 && $exception->status < 500
                ? $exception->status
                : 422;

            return $this->errors->make(
                __($status === 429 ? 'erp_errors.rate_limited' : 'erp_errors.validation_failed'),
                $status === 429 ? 'rate_limited' : 'validation_failed',
                $status,
                $exception->errors(),
            );
        }

        if ($exception instanceof QueryException) {
            return $this->databaseResponse($exception, $request);
        }

        if ($exception instanceof TokenMismatchException) {
            return $this->errors->make(__('erp_errors.session_expired'), 'session_expired', 419);
        }

        if ($exception instanceof AuthenticationException) {
            return $this->errors->make(
                __('erp_errors.authentication_required'),
                'authentication_required',
                401,
                extra: ['redirect' => route('login', [], false)],
            );
        }

        if ($exception instanceof AuthorizationException) {
            return $this->errors->make(__('erp_errors.permission_denied'), 'permission_denied', 403);
        }

        if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
            return $this->errors->make(__('erp_errors.not_found'), 'not_found', 404);
        }

        if ($exception instanceof DomainException) {
            $isConflict = str_contains(class_basename($exception), 'DeleteBlocked')
                || str_contains(class_basename($exception), 'RestoreBlocked');
            $extra = property_exists($exception, 'blockedRecords')
                ? ['data' => ['blocked_records' => $exception->blockedRecords]]
                : [];

            return $this->errors->make(
                $exception->getMessage() ?: __('erp_errors.business_rule_violation'),
                $isConflict ? 'record_in_use' : 'business_rule_violation',
                $isConflict ? 409 : 422,
                extra: $extra,
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->httpResponse($exception, $request);
        }

        $correlationId = $this->correlationId($request);

        return $this->errors->make(
            __('erp_errors.unexpected', ['correlation_id' => $correlationId]),
            'internal_error',
            500,
            correlationId: $correlationId,
        );
    }

    public function correlationId(Request $request): string
    {
        $existing = $request->attributes->get(self::CorrelationAttribute);

        if (is_string($existing) && preg_match('/^[A-Za-z0-9-]{8,64}$/D', $existing) === 1) {
            return $existing;
        }

        $correlationId = (string) Str::uuid();
        $request->attributes->set(self::CorrelationAttribute, $correlationId);

        return $correlationId;
    }

    public function report(Throwable $exception, Request $request): bool
    {
        if (! $this->isUnexpected($exception)) {
            return false;
        }

        $context = [
            'correlation_id' => $this->correlationId($request),
            'timestamp' => now()->toIso8601String(),
            'route_name' => $request->route()?->getName(),
            'http_method' => $request->method(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'company_id' => $request->hasSession() ? $request->session()->get('current_company_id') : null,
            'branch_id' => $request->hasSession() ? $request->session()->get('current_branch_id') : null,
            'exception_class' => $exception::class,
            'stack_trace' => $exception->getTraceAsString(),
        ];

        if ($exception instanceof QueryException) {
            $context['sql_state'] = $this->databaseErrors->sqlState($exception);
            $context['constraint'] = $this->databaseErrors->constraintName($exception);
        }

        Log::error('Unexpected ERP request failure.', $context);
        $request->attributes->set(self::LoggedAttribute, true);

        return true;
    }

    public function reportResponse(Request $request, string $correlationId): void
    {
        if ($request->attributes->getBoolean(self::LoggedAttribute)) {
            return;
        }

        Log::error('Unexpected ERP JSON error response.', [
            'correlation_id' => $correlationId,
            'timestamp' => now()->toIso8601String(),
            'route_name' => $request->route()?->getName(),
            'http_method' => $request->method(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'company_id' => $request->hasSession() ? $request->session()->get('current_company_id') : null,
            'branch_id' => $request->hasSession() ? $request->session()->get('current_branch_id') : null,
            'exception_class' => 'HandledJsonErrorResponse',
        ]);
        $request->attributes->set(self::LoggedAttribute, true);
    }

    private function databaseResponse(QueryException $exception, Request $request): JsonResponse
    {
        $mapping = $this->databaseErrors->map($exception);

        if (! $mapping['mapped']) {
            $correlationId = $this->correlationId($request);

            return $this->errors->make(
                __('erp_errors.unexpected', ['correlation_id' => $correlationId]),
                'internal_error',
                500,
                correlationId: $correlationId,
            );
        }

        $message = __($mapping['translation_key']);
        $fieldErrors = $mapping['field'] !== null ? [$mapping['field'] => [$message]] : [];

        return $this->errors->make(
            $message,
            $mapping['error_code'],
            $mapping['status'],
            $fieldErrors,
        );
    }

    private function httpResponse(HttpExceptionInterface $exception, Request $request): JsonResponse
    {
        $status = $exception->getStatusCode();
        $errorCode = match ($status) {
            401 => 'authentication_required',
            403 => 'permission_denied',
            404 => 'not_found',
            409 => 'invalid_state',
            419 => 'session_expired',
            422 => 'validation_failed',
            429 => 'rate_limited',
            default => 'internal_error',
        };
        $translationKey = match ($status) {
            401 => 'erp_errors.authentication_required',
            403 => 'erp_errors.permission_denied',
            404 => 'erp_errors.not_found',
            409 => 'erp_errors.concurrent_update',
            419 => 'erp_errors.session_expired',
            422 => 'erp_errors.validation_failed',
            429 => 'erp_errors.rate_limited',
            default => 'erp_errors.unexpected',
        };

        if ($status >= 500) {
            $correlationId = $this->correlationId($request);

            return $this->errors->make(
                __($translationKey, ['correlation_id' => $correlationId]),
                $errorCode,
                $status,
                correlationId: $correlationId,
            );
        }

        $message = trim($exception->getMessage()) !== ''
            ? $exception->getMessage()
            : __($translationKey);

        return $this->errors->make($message, $errorCode, $status);
    }

    private function isUnexpected(Throwable $exception): bool
    {
        if ($exception instanceof ValidationException
            || $exception instanceof AuthenticationException
            || $exception instanceof AuthorizationException
            || $exception instanceof TokenMismatchException
            || $exception instanceof ModelNotFoundException
            || $exception instanceof NotFoundHttpException
            || $exception instanceof DomainException) {
            return false;
        }

        if ($exception instanceof QueryException) {
            return ! $this->databaseErrors->map($exception)['mapped'];
        }

        return ! ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
    }
}
