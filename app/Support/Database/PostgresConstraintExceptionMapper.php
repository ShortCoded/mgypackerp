<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;

class PostgresConstraintExceptionMapper
{
    /**
     * @return array{
     *     mapped: bool,
     *     sql_state: string|null,
     *     constraint: string|null,
     *     error_code: string,
     *     translation_key: string,
     *     field: string|null,
     *     status: int
     * }
     */
    public function map(QueryException $exception): array
    {
        $sqlState = $this->sqlState($exception);
        $constraint = $this->constraintName($exception);
        $registry = config('erp_errors.constraints', []);

        if ($constraint !== null && isset($registry[$constraint])) {
            return $this->mapping($registry[$constraint], $sqlState, $constraint);
        }

        foreach (config('erp_errors.constraint_patterns', []) as $pattern => $mapping) {
            if ($constraint !== null && preg_match($pattern, $constraint) === 1) {
                return $this->mapping($mapping, $sqlState, $constraint);
            }
        }

        return match ($sqlState) {
            '23502' => $this->mapping([
                'error_code' => 'missing_required_value',
                'translation_key' => 'erp_errors.missing_required_value',
                'field' => $this->columnName($exception),
                'status' => 422,
            ], $sqlState, $constraint),
            '23503' => $this->foreignKeyMapping($exception, $sqlState, $constraint),
            '23514' => $this->mapping([
                'error_code' => 'invalid_value',
                'translation_key' => 'erp_errors.invalid_value',
                'field' => null,
                'status' => 422,
            ], $sqlState, $constraint),
            '40001', '40P01' => $this->mapping([
                'error_code' => 'concurrent_update',
                'translation_key' => 'erp_errors.concurrent_update',
                'field' => null,
                'status' => 409,
            ], $sqlState, $constraint),
            default => $this->unknown($sqlState, $constraint),
        };
    }

    public function sqlState(QueryException $exception): ?string
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return is_string($sqlState) && preg_match('/^[0-9A-Z]{5}$/D', $sqlState) === 1
            ? $sqlState
            : null;
    }

    public function constraintName(QueryException $exception): ?string
    {
        $driverMessage = $exception->errorInfo[2] ?? null;

        if (! is_string($driverMessage)
            || preg_match('/constraint ["\']([A-Za-z0-9_]+)["\']/i', $driverMessage, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param  array{error_code: string, translation_key: string, field: string|null, status: int}  $mapping
     * @return array{mapped: bool, sql_state: string|null, constraint: string|null, error_code: string, translation_key: string, field: string|null, status: int}
     */
    private function mapping(array $mapping, ?string $sqlState, ?string $constraint): array
    {
        return [
            'mapped' => true,
            'sql_state' => $sqlState,
            'constraint' => $constraint,
            ...$mapping,
        ];
    }

    /**
     * @return array{mapped: bool, sql_state: string|null, constraint: string|null, error_code: string, translation_key: string, field: string|null, status: int}
     */
    private function foreignKeyMapping(QueryException $exception, ?string $sqlState, ?string $constraint): array
    {
        $isDelete = preg_match('/^\s*delete\b/i', $exception->getSql()) === 1;

        return $this->mapping([
            'error_code' => $isDelete ? 'record_in_use' : 'invalid_reference',
            'translation_key' => $isDelete ? 'erp_errors.record_in_use' : 'erp_errors.invalid_reference',
            'field' => null,
            'status' => $isDelete ? 409 : 422,
        ], $sqlState, $constraint);
    }

    private function columnName(QueryException $exception): ?string
    {
        $driverMessage = $exception->errorInfo[2] ?? null;

        if (! is_string($driverMessage)
            || preg_match('/column ["\']([A-Za-z0-9_]+)["\']/i', $driverMessage, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array{mapped: bool, sql_state: string|null, constraint: string|null, error_code: string, translation_key: string, field: string|null, status: int}
     */
    private function unknown(?string $sqlState, ?string $constraint): array
    {
        return [
            'mapped' => false,
            'sql_state' => $sqlState,
            'constraint' => $constraint,
            'error_code' => 'internal_error',
            'translation_key' => 'erp_errors.unexpected',
            'field' => null,
            'status' => 500,
        ];
    }
}
