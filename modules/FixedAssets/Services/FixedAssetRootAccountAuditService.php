<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\FoundationalAccountResolver;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Models\FixedAsset;

class FixedAssetRootAccountAuditService
{
    public function __construct(
        private readonly FoundationalAccountResolver $foundationalAccounts,
        private readonly FixedAssetAccountReferenceInspector $referenceInspector,
    ) {}

    /** @return array<string, mixed> */
    public function auditReadOnly(Company $company): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $sqliteQueryOnlyEnabled = false;

        if ($driver === 'mysql') {
            $connection->statement('SET TRANSACTION READ ONLY');
        }

        if ($driver === 'sqlite') {
            $connection->statement('PRAGMA query_only = ON');
            $sqliteQueryOnlyEnabled = true;
        }

        $connection->beginTransaction();

        try {
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
            } elseif (! in_array($driver, ['mysql', 'sqlite'], true)) {
                throw new DomainException("The {$driver} driver has no configured read-only audit guard.");
            }

            $result = $this->audit($company);
            $result['read_only_enforced'] = true;
            $result['read_only_driver'] = $driver;

            return $result;
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            if ($sqliteQueryOnlyEnabled) {
                $connection->statement('PRAGMA query_only = OFF');
            }
        }
    }

    /** @return array<string, mixed> */
    public function audit(Company $company): array
    {
        $companyId = (int) $company->getKey();
        $classification = AccountClassification::query()
            ->where('code', AccountClassification::FixedAssets)
            ->where('status', 'active')
            ->first();
        $roots = $this->foundationalAccounts->topmostCandidates($companyId, AccountClassification::FixedAssets);
        $canonicalRoot = $roots->count() === 1 ? $roots->first() : null;
        $rootLikeAccounts = $classification instanceof AccountClassification
            ? Account::withTrashed()
                ->forCompany($companyId)
                ->where('account_classification_id', $classification->getKey())
                ->where('is_group', true)
                ->where('is_postable', false)
                ->where(fn ($query) => $query->where('status', 'active')->orWhereNotNull('deleted_at'))
                ->oldest('id')
                ->get()
                ->filter(fn (Account $account): bool => $this->hasRootLikeLabel($account, $classification, $canonicalRoot))
                ->values()
            : collect();
        $duplicateCandidates = $canonicalRoot instanceof Account
            ? $rootLikeAccounts->reject(fn (Account $account): bool => (int) $account->getKey() === (int) $canonicalRoot->getKey())->values()
            : $rootLikeAccounts;
        $accountIds = $duplicateCandidates
            ->map(fn (Account $account): int => (int) $account->getKey())
            ->all();
        $references = $this->referenceInspector->inspect($accountIds);

        return [
            'generated_at' => now()->toIso8601String(),
            'company' => [
                'id' => $companyId,
                'doc_num' => $company->doc_num,
                'name' => (string) $company->name,
            ],
            'classification' => AccountClassification::FixedAssets,
            'resolution_status' => match ($roots->count()) {
                0 => 'missing',
                1 => 'unique',
                default => 'ambiguous',
            },
            'root_candidates' => $roots
                ->map(fn (Account $account): array => $this->foundationalAccounts->candidateDetails($account))
                ->all(),
            'canonical_root' => $canonicalRoot instanceof Account
                ? $this->foundationalAccounts->candidateDetails($canonicalRoot)
                : null,
            'duplicate_candidate_count' => $duplicateCandidates->count(),
            'duplicate_candidates' => $duplicateCandidates
                ->map(fn (Account $account): array => $this->candidateReport($account, $references[(int) $account->getKey()] ?? []))
                ->all(),
            'read_only_enforced' => false,
        ];
    }

    /**
     * @param  list<array{table: string, column: string, count: int, rows: list<array<string, mixed>>}>  $references
     * @return array<string, mixed>
     */
    private function candidateReport(Account $account, array $references): array
    {
        $accountId = (int) $account->getKey();
        $children = Account::withTrashed()
            ->where('company_id', $account->company_id)
            ->where('parent_id', $accountId)
            ->oldest('id')
            ->get(['id', 'doc_num', 'account_code', 'name', 'name_en', 'status', 'deleted_at']);
        $categoryReferences = DB::table('fixed_asset_category_mappings')
            ->where('asset_group_account_id', $accountId)
            ->orderBy('id')
            ->get(['id', 'company_id', 'asset_group_account_id']);
        $fixedAssetReferences = FixedAsset::withTrashed()
            ->where('company_id', $account->company_id)
            ->where(fn ($query) => $query
                ->where('account_id', $accountId)
                ->orWhere('asset_group_account_id', $accountId))
            ->oldest('id')
            ->get(['id', 'doc_num', 'asset_name', 'account_id', 'asset_group_account_id', 'status', 'deleted_at']);
        $journalMetrics = DB::table('journal_entry_lines')
            ->where('account_id', $accountId)
            ->selectRaw('COUNT(*) AS line_count')
            ->selectRaw('COALESCE(SUM(debit_amount), 0) AS debit_total')
            ->selectRaw('COALESCE(SUM(credit_amount), 0) AS credit_total')
            ->first();
        $openingMetrics = DB::table('opening_balance_lines')
            ->where('account_id', $accountId)
            ->selectRaw('COUNT(*) AS line_count')
            ->selectRaw('COALESCE(SUM(debit_amount), 0) AS debit_total')
            ->selectRaw('COALESCE(SUM(credit_amount), 0) AS credit_total')
            ->first();
        $otherReferences = collect($references)
            ->reject(fn (array $reference): bool => $this->isKnownReference($reference['table'], $reference['column']))
            ->values()
            ->all();
        $journalLineCount = (int) ($journalMetrics?->line_count ?? 0);
        $openingLineCount = (int) ($openingMetrics?->line_count ?? 0);
        $referenceCount = collect($references)->sum('count');
        $safeToArchive = $children->isEmpty() && $referenceCount === 0;

        return [
            ...$this->foundationalAccounts->candidateDetails($account),
            'status' => (string) $account->status,
            'deleted' => $account->trashed(),
            'deleted_at' => $account->deleted_at?->toIso8601String(),
            'children_count' => $children->whereNull('deleted_at')->count(),
            'children_with_trashed_count' => $children->count(),
            'children' => $children->map(fn (Account $child): array => [
                'id' => (int) $child->getKey(),
                'doc_num' => $child->doc_num,
                'code' => (string) $child->account_code,
                'name' => (string) $child->name,
                'name_en' => $child->name_en,
                'status' => (string) $child->status,
                'deleted' => $child->trashed(),
            ])->all(),
            'fixed_asset_category_reference_count' => $categoryReferences->count(),
            'fixed_asset_category_references' => $categoryReferences->map(fn (object $row): array => (array) $row)->all(),
            'fixed_asset_reference_count' => $fixedAssetReferences->count(),
            'fixed_asset_references' => $fixedAssetReferences->map(fn (FixedAsset $asset): array => [
                'id' => (int) $asset->getKey(),
                'doc_num' => $asset->doc_num,
                'name' => (string) $asset->asset_name,
                'account_id' => (int) $asset->account_id,
                'asset_group_account_id' => (int) $asset->asset_group_account_id,
                'status' => (string) $asset->status,
                'deleted' => $asset->trashed(),
            ])->all(),
            'journal_line_count' => $journalLineCount,
            'journal_debit_total' => (string) ($journalMetrics?->debit_total ?? '0'),
            'journal_credit_total' => (string) ($journalMetrics?->credit_total ?? '0'),
            'journal_balance' => $this->balance($journalMetrics?->debit_total, $journalMetrics?->credit_total),
            'opening_balance_line_count' => $openingLineCount,
            'opening_balance_debit_total' => (string) ($openingMetrics?->debit_total ?? '0'),
            'opening_balance_credit_total' => (string) ($openingMetrics?->credit_total ?? '0'),
            'opening_balance' => $this->balance($openingMetrics?->debit_total, $openingMetrics?->credit_total),
            'reference_count' => $referenceCount,
            'references' => $references,
            'other_application_references' => $otherReferences,
            'archive_status' => $safeToArchive ? 'safe' : 'blocked',
            'archive_blockers' => array_values(array_filter([
                $children->isNotEmpty() ? 'child_accounts' : null,
                $categoryReferences->isNotEmpty() ? 'fixed_asset_category_mappings' : null,
                $fixedAssetReferences->isNotEmpty() ? 'fixed_assets' : null,
                $journalLineCount > 0 ? 'journal_entry_lines' : null,
                $openingLineCount > 0 ? 'opening_balance_lines' : null,
                $otherReferences !== [] ? 'other_application_references' : null,
            ])),
        ];
    }

    private function hasRootLikeLabel(Account $account, AccountClassification $classification, ?Account $canonicalRoot): bool
    {
        $referenceLabels = [
            $classification->name,
            $classification->name_en,
            $canonicalRoot?->name,
            $canonicalRoot?->name_en,
            'Fixed Assets',
            'أصول ثابتة',
            'الأصول الثابتة',
        ];
        $accountLabels = [$account->name, $account->name_en];

        return collect($accountLabels)
            ->filter(fn (mixed $label): bool => is_string($label) && trim($label) !== '')
            ->contains(fn (string $label): bool => collect($referenceLabels)
                ->filter(fn (mixed $reference): bool => is_string($reference) && trim($reference) !== '')
                ->contains(fn (string $reference): bool => $this->normalizeLabel($reference) === $this->normalizeLabel($label)));
    }

    private function normalizeLabel(string $label): string
    {
        return Str::of($label)
            ->squish()
            ->lower()
            ->replaceMatches('/[^\pL\pN]+/u', '')
            ->toString();
    }

    private function isKnownReference(string $table, string $column): bool
    {
        return in_array("{$table}.{$column}", [
            'accounts.parent_id',
            'fixed_asset_category_mappings.asset_group_account_id',
            'fixed_assets.account_id',
            'fixed_assets.asset_group_account_id',
            'journal_entry_lines.account_id',
            'opening_balance_lines.account_id',
        ], true);
    }

    private function balance(mixed $debit, mixed $credit): string
    {
        return bcsub((string) ($debit ?? '0'), (string) ($credit ?? '0'), 4);
    }
}
