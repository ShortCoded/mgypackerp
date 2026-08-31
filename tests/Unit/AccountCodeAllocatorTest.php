<?php

use Illuminate\Database\QueryException;
use Modules\Accounting\Services\AccountCodeAllocator;
use Tests\TestCase;

uses(TestCase::class);

function accountAllocatorQueryException(string $sqlState, string $constraint): QueryException
{
    $message = "database constraint \"{$constraint}\"";
    $previous = new PDOException($message);
    $previous->errorInfo = [$sqlState, 0, $message];

    return new QueryException('pgsql', 'insert into accounts values (?)', ['safe'], $previous);
}

test('it retries the complete allocation transaction after the specific account code collision', function (): void {
    $attempts = 0;

    $result = app(AccountCodeAllocator::class)->transaction(function () use (&$attempts): string {
        $attempts++;

        if ($attempts === 1) {
            throw accountAllocatorQueryException('23505', 'accounts_company_account_code_unique_active');
        }

        return 'allocated';
    });

    expect($result)->toBe('allocated')
        ->and($attempts)->toBe(2);
});

test('it bounds deadlock retries and does not retry unrelated unique constraints', function (): void {
    $deadlockAttempts = 0;
    $otherUniqueAttempts = 0;

    $deadlockAction = function () use (&$deadlockAttempts): void {
        app(AccountCodeAllocator::class)->transaction(function () use (&$deadlockAttempts): void {
            $deadlockAttempts++;
            throw accountAllocatorQueryException('40P01', 'accounts_company_account_code_unique_active');
        });
    };

    expect($deadlockAction)->toThrow(QueryException::class);

    expect($deadlockAttempts)->toBe(3);

    $otherUniqueAction = function () use (&$otherUniqueAttempts): void {
        app(AccountCodeAllocator::class)->transaction(function () use (&$otherUniqueAttempts): void {
            $otherUniqueAttempts++;
            throw accountAllocatorQueryException('23505', 'another_unique_constraint');
        });
    };

    expect($otherUniqueAction)->toThrow(QueryException::class);

    expect($otherUniqueAttempts)->toBe(1);
});
