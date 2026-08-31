<?php

use App\Support\Database\PostgresConstraintExceptionMapper;
use Illuminate\Database\QueryException;
use Tests\TestCase;

uses(TestCase::class);

function postgresQueryException(string $sqlState, string $driverMessage, string $sql = 'insert into records values (?)'): QueryException
{
    $previous = new PDOException($driverMessage);
    $previous->errorInfo = [$sqlState, 0, $driverMessage];

    return new QueryException('pgsql', $sql, ['sensitive-value'], $previous);
}

test('it maps known account code collisions without exposing database details', function (): void {
    $mapping = app(PostgresConstraintExceptionMapper::class)->map(postgresQueryException(
        '23505',
        'duplicate key violates unique constraint "accounts_company_account_code_unique_active"',
    ));

    expect($mapping)
        ->mapped->toBeTrue()
        ->error_code->toBe('duplicate_account_code')
        ->translation_key->toBe('erp_errors.duplicate_account_code')
        ->field->toBe('account_code')
        ->status->toBe(422)
        ->constraint->toBe('accounts_company_account_code_unique_active');
});

test('it maps required check and retryable PostgreSQL states', function (string $sqlState, string $errorCode, int $status): void {
    $mapping = app(PostgresConstraintExceptionMapper::class)->map(postgresQueryException(
        $sqlState,
        $sqlState === '23502'
            ? 'null value in column "name" violates not-null constraint'
            : 'safe database failure',
    ));

    expect($mapping['mapped'])->toBeTrue()
        ->and($mapping['error_code'])->toBe($errorCode)
        ->and($mapping['status'])->toBe($status);
})->with([
    'not null' => ['23502', 'missing_required_value', 422],
    'check constraint' => ['23514', 'invalid_value', 422],
    'serialization failure' => ['40001', 'concurrent_update', 409],
    'deadlock' => ['40P01', 'concurrent_update', 409],
]);

test('it distinguishes a protected deletion from an invalid foreign reference', function (): void {
    $mapper = app(PostgresConstraintExceptionMapper::class);
    $deleted = $mapper->map(postgresQueryException('23503', 'foreign key violation', 'delete from parents where id = ?'));
    $inserted = $mapper->map(postgresQueryException('23503', 'foreign key violation', 'insert into children values (?)'));

    expect($deleted['error_code'])->toBe('record_in_use')
        ->and($deleted['status'])->toBe(409)
        ->and($inserted['error_code'])->toBe('invalid_reference')
        ->and($inserted['status'])->toBe(422);
});

test('it sends unknown constraints to the safe internal error path', function (): void {
    $mapping = app(PostgresConstraintExceptionMapper::class)->map(postgresQueryException(
        '23505',
        'duplicate key violates unique constraint "unknown_sensitive_constraint"',
    ));

    expect($mapping['mapped'])->toBeFalse()
        ->and($mapping['error_code'])->toBe('internal_error')
        ->and($mapping['status'])->toBe(500);
});
