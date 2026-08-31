<?php

namespace Modules\Accounting\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Company;

class AccountCodeAllocator
{
    private const MaxTransactionAttempts = 3;

    private int $transactionDepth = 0;

    /**
     * Execute the complete write transaction again only when allocation can be retried safely.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function transaction(Closure $callback): mixed
    {
        if ($this->transactionDepth > 0) {
            return $callback();
        }

        $this->transactionDepth++;

        try {
            for ($attempt = 1; $attempt <= self::MaxTransactionAttempts; $attempt++) {
                try {
                    return DB::transaction($callback);
                } catch (QueryException $exception) {
                    if ($attempt === self::MaxTransactionAttempts || ! $this->isRetryable($exception)) {
                        throw $exception;
                    }
                }
            }
        } finally {
            $this->transactionDepth--;
        }

        throw new \LogicException('Account allocation retry loop exited unexpectedly.');
    }

    public function nextChildCode(Account $parent): string
    {
        if (DB::connection()->transactionLevel() === 0) {
            return $this->transaction(fn (): string => $this->nextChildCode($parent));
        }

        $companyId = (int) $parent->company_id;

        Company::query()
            ->whereKey($companyId)
            ->lockForUpdate()
            ->firstOrFail();

        $prefix = (string) $parent->account_code;
        $companyCodes = Account::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->pluck('account_code')
            ->map(fn (mixed $accountCode): string => (string) $accountCode);
        $siblingSuffixes = Account::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('parent_id', $parent->getKey())
            ->pluck('account_code')
            ->map(function (mixed $accountCode) use ($prefix): ?int {
                $suffix = substr((string) $accountCode, strlen($prefix));

                return $suffix !== '' && ctype_digit($suffix) ? (int) $suffix : null;
            })
            ->filter(fn (?int $suffix): bool => $suffix !== null);

        $suffix = ($siblingSuffixes->max() ?? 0) + 1;
        $candidate = $prefix.(string) $suffix;

        while ($companyCodes->containsStrict($candidate)) {
            $suffix++;
            $candidate = $prefix.(string) $suffix;
        }

        return $candidate;
    }

    public function isRetryable(QueryException $exception): bool
    {
        $sqlState = $this->sqlState($exception);

        if (in_array($sqlState, ['40001', '40P01'], true)) {
            return true;
        }

        return $sqlState === '23505'
            && $this->constraintName($exception) === 'accounts_company_account_code_unique_active';
    }

    private function sqlState(QueryException $exception): ?string
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return is_string($sqlState) && preg_match('/^[0-9A-Z]{5}$/D', $sqlState) === 1
            ? $sqlState
            : null;
    }

    private function constraintName(QueryException $exception): ?string
    {
        $driverMessage = $exception->errorInfo[2] ?? null;

        if (! is_string($driverMessage)
            || preg_match('/constraint ["\']([A-Za-z0-9_]+)["\']/i', $driverMessage, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
