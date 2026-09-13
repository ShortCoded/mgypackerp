<?php

namespace App\Services;

use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;

class PostingAccountConfigurationAudit
{
    /**
     * @return array{
     *     ok: bool,
     *     rows: list<array{code: string, classification: string, status: string, accounts: string}>,
     *     valid_count: int,
     *     missing_count: int,
     *     ambiguous_count: int
     * }
     */
    public function forCompany(int $companyId): array
    {
        $codes = PostingAccountResolver::requiredClassificationCodes();
        $classifications = AccountClassification::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');
        $accounts = Account::query()
            ->forCompany($companyId)
            ->eligibleForDirectPosting()
            ->whereHas('classification', fn ($query) => $query->whereIn('code', $codes)->where('status', 'active'))
            ->with('classification:id,code')
            ->ordered()
            ->get(['id', 'doc_num', 'account_code', 'name', 'name_en', 'account_classification_id'])
            ->groupBy(fn (Account $account): string => (string) $account->classification?->code);

        $rows = collect($codes)->map(function (string $code) use ($accounts, $classifications): array {
            $classification = $classifications->get($code);
            $matchingAccounts = $accounts->get($code, collect());
            $status = match (true) {
                ! $classification instanceof AccountClassification || $classification->status !== 'active' => 'classification_missing',
                $matchingAccounts->isEmpty() => 'account_missing',
                $matchingAccounts->count() > 1 => 'ambiguous',
                default => 'ready',
            };

            return [
                'code' => $code,
                'classification' => $classification?->displayName() ?: $code,
                'status' => $status,
                'accounts' => $matchingAccounts->map->codeNameLabel()->implode(' | '),
            ];
        })->values();

        return [
            'ok' => $rows->every(fn (array $row): bool => $row['status'] === 'ready'),
            'rows' => $rows->all(),
            'valid_count' => $rows->where('status', 'ready')->count(),
            'missing_count' => $rows->whereIn('status', ['classification_missing', 'account_missing'])->count(),
            'ambiguous_count' => $rows->where('status', 'ambiguous')->count(),
        ];
    }
}
