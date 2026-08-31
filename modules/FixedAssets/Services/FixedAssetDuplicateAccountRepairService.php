<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Spatie\Activitylog\Models\Activity;

class FixedAssetDuplicateAccountRepairService
{
    public const ProductionAcknowledgementPrefix = 'APPLY_PRODUCTION_FIXED_ASSET_REPAIR';

    public function __construct(
        private readonly FixedAssetDuplicateAccountAuditService $audit,
        private readonly FixedAssetBookValueService $bookValues,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<string, int|string>  $expectations
     * @return array{manifest: array<string, mixed>, review_token: string, production_acknowledgement: string}
     */
    public function reviewReadOnly(Company $company, string $assetDocNum, array $expectations): array
    {
        return $this->inReadOnlyTransaction(function () use ($company, $assetDocNum, $expectations): array {
            return $this->review($company, $assetDocNum, $expectations);
        });
    }

    /**
     * @param  array<string, int|string>  $expectations
     * @return array{manifest: array<string, mixed>, review_token: string, production_acknowledgement: string}
     */
    public function review(Company $company, string $assetDocNum, array $expectations): array
    {
        $audit = $this->audit->audit($company, $assetDocNum);
        $case = collect($audit['cases'])->firstWhere('asset_doc_num', $assetDocNum);

        if (! is_array($case) || ! $case['is_actual_duplicate']) {
            throw new DomainException('The reviewed asset does not have an evidence-backed duplicate account pair.');
        }

        if ($case['repair_status'] !== 'safe'
            || ! in_array($case['classification'], [
                FixedAssetDuplicateAccountAuditService::ClassificationOldPostedNewEmpty,
                FixedAssetDuplicateAccountAuditService::ClassificationOldEmptyNewPosted,
                FixedAssetDuplicateAccountAuditService::ClassificationBothEmpty,
            ], true)
        ) {
            throw new DomainException('The reviewed case is blocked and cannot be applied automatically: '.$case['decision_reason']);
        }

        $canonicalAccountId = $this->positiveInteger($expectations, 'canonical_account_id');
        $duplicateAccountId = $this->positiveInteger($expectations, 'duplicate_account_id');
        $expectedCurrentAccountId = $this->positiveInteger($expectations, 'expected_current_account_id');
        $expectedParentAccountId = $this->positiveInteger($expectations, 'expected_parent_account_id');

        if ($canonicalAccountId === $duplicateAccountId) {
            throw new DomainException('The canonical and duplicate account IDs must differ.');
        }

        if ((int) $case['recommended_canonical_account_id'] !== $canonicalAccountId) {
            throw new DomainException('The explicit canonical account does not match the audit decision.');
        }

        if (collect($case['duplicate_account_ids'])->map(fn (mixed $id): int => (int) $id)->all() !== [$duplicateAccountId]) {
            throw new DomainException('The explicit duplicate account does not match the single reviewed duplicate.');
        }

        if ((int) $case['current_account_id'] !== $expectedCurrentAccountId) {
            throw new DomainException('The current fixed_assets.account_id differs from the expected value.');
        }

        if ((int) $case['approved_parent_account_id'] !== $expectedParentAccountId) {
            throw new DomainException('The approved current category account differs from the expected value.');
        }

        $accounts = collect($case['accounts'])->keyBy(fn (array $account): int => (int) $account['id']);
        $canonical = $accounts->get($canonicalAccountId);
        $duplicate = $accounts->get($duplicateAccountId);

        if (! is_array($canonical) || ! is_array($duplicate)) {
            throw new DomainException('Both explicitly selected accounts must be present in the reviewed evidence set.');
        }

        $this->assertExpectedMetrics('canonical', $canonical, $expectations);
        $this->assertExpectedMetrics('duplicate', $duplicate, $expectations);

        $parentIds = collect([$canonical['parent_id'], $duplicate['parent_id'], $expectedParentAccountId])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $journalState = $this->journalState([$canonicalAccountId, $duplicateAccountId]);
        $parentState = $this->parentState($parentIds, (int) $company->getKey());
        $asset = FixedAsset::query()->whereKey($case['asset']['id'])->firstOrFail();
        $accountModels = Account::withTrashed()
            ->where('company_id', $company->getKey())
            ->whereIn('id', [$canonicalAccountId, $duplicateAccountId])
            ->get()
            ->keyBy(fn (Account $account): int => (int) $account->getKey());
        $accountingReconciliation = $this->accountingReconciliation($asset, $canonicalAccountId);

        if (! $accountingReconciliation['reconciled']) {
            throw new DomainException('The Fixed Asset subledger does not reconcile to the reviewed canonical GL account. Automatic repair is blocked.');
        }

        $manifest = [
            'manifest_version' => 1,
            'company' => [
                'id' => (int) $company->getKey(),
                'doc_num' => $company->doc_num,
                'name' => $company->name,
            ],
            'asset' => $case['asset'],
            'asset_database_row' => $asset->getAttributes(),
            'classification' => $case['classification'],
            'decision_reason' => $case['decision_reason'],
            'canonical_account_id' => $canonicalAccountId,
            'duplicate_account_id' => $duplicateAccountId,
            'expected_current_account_id' => $expectedCurrentAccountId,
            'approved_parent_account_id' => $expectedParentAccountId,
            'canonical_account' => $this->manifestAccount($canonical),
            'canonical_account_database_row' => $accountModels->get($canonicalAccountId)?->getAttributes(),
            'duplicate_account' => $this->manifestAccount($duplicate),
            'duplicate_account_database_row' => $accountModels->get($duplicateAccountId)?->getAttributes(),
            'parent_state' => $parentState,
            'journal_state' => $journalState,
            'accounting_reconciliation' => $accountingReconciliation,
            'reference_state_hash' => $this->hashValue([
                $canonicalAccountId => $canonical['all_database_references'],
                $duplicateAccountId => $duplicate['all_database_references'],
            ]),
            'evidence_state_hash' => $this->hashValue([
                $canonicalAccountId => $canonical['evidence'],
                $duplicateAccountId => $duplicate['evidence'],
            ]),
        ];
        $reviewToken = $this->hashValue($manifest);

        return [
            'manifest' => $manifest,
            'review_token' => $reviewToken,
            'production_acknowledgement' => $this->productionAcknowledgement(
                (string) $company->doc_num,
                $assetDocNum,
                $canonicalAccountId,
                $duplicateAccountId,
            ),
        ];
    }

    /**
     * @param  array<string, int|string>  $expectations
     * @return array{changed: bool, idempotent: bool, review_token: string, verification: array<string, mixed>}
     */
    public function apply(
        Company $company,
        string $assetDocNum,
        array $expectations,
        string $reviewToken,
        string $productionAcknowledgement,
    ): array {
        if (! app()->isDownForMaintenance()) {
            throw new DomainException('Apply requires Laravel maintenance mode with Octane and queue workers stopped.');
        }

        $requiredAcknowledgement = $this->productionAcknowledgement(
            (string) $company->doc_num,
            $assetDocNum,
            $this->positiveInteger($expectations, 'canonical_account_id'),
            $this->positiveInteger($expectations, 'duplicate_account_id'),
        );

        if (! hash_equals($requiredAcknowledgement, $productionAcknowledgement)) {
            throw new DomainException('The explicit Production acknowledgement does not match the reviewed account pair.');
        }

        return DB::transaction(function () use ($company, $assetDocNum, $expectations, $reviewToken): array {
            $companyId = (int) $company->getKey();
            $canonicalAccountId = $this->positiveInteger($expectations, 'canonical_account_id');
            $duplicateAccountId = $this->positiveInteger($expectations, 'duplicate_account_id');
            $expectedParentAccountId = $this->positiveInteger($expectations, 'expected_parent_account_id');
            $asset = FixedAsset::withTrashed()
                ->where('company_id', $companyId)
                ->where('doc_num', $assetDocNum)
                ->lockForUpdate()
                ->get();

            if ($asset->count() !== 1 || $asset->first()->trashed()) {
                throw new DomainException('The company and asset document number must still resolve to exactly one active Fixed Asset record.');
            }

            $asset = $asset->first();
            $accounts = Account::withTrashed()
                ->where('company_id', $companyId)
                ->whereIn('id', [$canonicalAccountId, $duplicateAccountId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($accounts->count() !== 2) {
                throw new DomainException('The explicit canonical and duplicate account IDs no longer resolve to the reviewed company.');
            }

            $parentIds = $accounts->pluck('parent_id')
                ->push($expectedParentAccountId)
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            Account::withTrashed()
                ->where('company_id', $companyId)
                ->whereKey($parentIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->lockTouchedJournals([$canonicalAccountId, $duplicateAccountId]);
            $receipt = $this->repairReceipt((int) $asset->getKey(), $reviewToken);

            if ($receipt instanceof Activity && $this->isAppliedState($asset, $accounts, $canonicalAccountId, $duplicateAccountId, $expectedParentAccountId)) {
                $manifest = $this->activityProperties($receipt)['manifest'] ?? null;

                if (! is_array($manifest)) {
                    throw new DomainException('The prior repair receipt is incomplete; manual review is required.');
                }

                return [
                    'changed' => false,
                    'idempotent' => true,
                    'review_token' => $reviewToken,
                    'verification' => $this->verifyAppliedState($asset->refresh(), $manifest),
                ];
            }

            $review = $this->review($company, $assetDocNum, $expectations);

            if (! hash_equals($review['review_token'], $reviewToken)) {
                throw new DomainException('The database state differs from the reviewed manifest. Generate and approve a new dry run.');
            }

            $manifest = $review['manifest'];

            if (collect($manifest['journal_state']['journals'])->contains(fn (array $journal): bool => $journal['balanced'] !== true)) {
                throw new DomainException('A referenced journal is not balanced; automatic repair is blocked.');
            }

            $canonical = $accounts->firstWhere('id', $canonicalAccountId);
            $duplicate = $accounts->firstWhere('id', $duplicateAccountId);
            $approvedParent = Account::query()
                ->where('company_id', $companyId)
                ->whereKey($expectedParentAccountId)
                ->where('status', 'active')
                ->where('is_group', true)
                ->where('is_postable', false)
                ->whereNull('deleted_at')
                ->first();

            if (! $canonical instanceof Account || ! $duplicate instanceof Account || ! $approvedParent instanceof Account) {
                throw new DomainException('The reviewed account or approved category is no longer eligible.');
            }

            $now = now();
            $actorId = auth()->id();
            $canonicalUpdates = [
                'name' => $asset->asset_name,
                'parent_id' => $approvedParent->getKey(),
                'level' => (int) $approvedParent->level + 1,
                'account_classification_id' => $approvedParent->account_classification_id,
                'account_type' => $approvedParent->account_type,
                'statement_type' => $approvedParent->statement_type,
                'normal_balance' => $approvedParent->normal_balance,
                'is_group' => false,
                'is_postable' => true,
                'status' => 'active',
                'updated_by' => $actorId,
                'updated_at' => $now,
            ];

            if ((int) $canonical->parent_id !== (int) $approvedParent->getKey()) {
                $canonicalUpdates['account_code'] = $this->nextChildAccountCode($approvedParent);
            }

            $assetMatches = FixedAsset::query()
                ->whereKey($asset->getKey())
                ->where('company_id', $companyId)
                ->where('account_id', $manifest['expected_current_account_id'])
                ->where('asset_group_account_id', $manifest['approved_parent_account_id'])
                ->where('updated_at', $asset->getRawOriginal('updated_at'))
                ->exists();

            if (! $assetMatches) {
                throw new DomainException('Compare-and-swap validation failed for the Fixed Asset row.');
            }

            $canonicalUpdated = Account::query()
                ->whereKey($canonicalAccountId)
                ->where('company_id', $companyId)
                ->where('status', $manifest['canonical_account']['status'])
                ->whereNull('deleted_at')
                ->where('updated_at', $canonical->getRawOriginal('updated_at'))
                ->update($canonicalUpdates);

            if ($canonicalUpdated !== 1) {
                throw new DomainException('Compare-and-swap validation failed for the canonical account.');
            }

            $assetUpdated = FixedAsset::query()
                ->whereKey($asset->getKey())
                ->where('company_id', $companyId)
                ->where('account_id', $manifest['expected_current_account_id'])
                ->where('asset_group_account_id', $manifest['approved_parent_account_id'])
                ->where('updated_at', $asset->getRawOriginal('updated_at'))
                ->update([
                    'account_id' => $canonicalAccountId,
                    'updated_by' => $actorId,
                    'updated_at' => $now,
                ]);

            if ($assetUpdated !== 1) {
                throw new DomainException('Compare-and-swap update failed for the Fixed Asset row.');
            }

            $duplicateArchived = Account::query()
                ->whereKey($duplicateAccountId)
                ->where('company_id', $companyId)
                ->where('status', $manifest['duplicate_account']['status'])
                ->whereNull('deleted_at')
                ->where('updated_at', $duplicate->getRawOriginal('updated_at'))
                ->update([
                    'status' => 'inactive',
                    'deleted_by' => $actorId,
                    'deleted_at' => $now,
                ]);

            if ($duplicateArchived !== 1) {
                throw new DomainException('Compare-and-swap archive failed for the empty duplicate account.');
            }

            $asset = $asset->refresh();
            $verification = $this->verifyAppliedState($asset, $manifest);
            $this->activityLogger->log(request(), 'fixed_assets', 'duplicate_account_repair', 'success', [
                'subject' => $asset,
                'company_id' => $companyId,
                'properties_only' => true,
                'properties' => [
                    'manifest_hash' => $reviewToken,
                    'classification' => $manifest['classification'],
                    'canonical_account_id' => $canonicalAccountId,
                    'duplicate_account_id' => $duplicateAccountId,
                    'before' => [
                        'asset_account_id' => $manifest['expected_current_account_id'],
                        'canonical_account' => $manifest['canonical_account'],
                        'duplicate_account' => $manifest['duplicate_account'],
                    ],
                    'after' => $verification,
                    'manifest' => $manifest,
                ],
            ]);

            return [
                'changed' => true,
                'idempotent' => false,
                'review_token' => $reviewToken,
                'verification' => $verification,
            ];
        }, 3);
    }

    public function productionAcknowledgement(string $companyDocNum, string $assetDocNum, int $canonicalAccountId, int $duplicateAccountId): string
    {
        return implode(':', [
            self::ProductionAcknowledgementPrefix,
            $companyDocNum,
            $assetDocNum,
            $canonicalAccountId,
            $duplicateAccountId,
        ]);
    }

    /** @param array<string, mixed> $account @return array<string, mixed> */
    private function manifestAccount(array $account): array
    {
        return collect($account)->only([
            'id', 'doc_num', 'account_code', 'name', 'parent_id', 'full_path', 'status', 'deleted',
            'created_at', 'updated_at', 'deleted_at', 'posted_journal_line_count',
            'non_posted_journal_line_count', 'journal_line_count', 'total_debit', 'total_credit',
            'balance', 'posted_debit_total', 'posted_credit_total', 'posted_balance',
            'other_database_reference_count', 'detection_confidence',
        ])->all();
    }

    /** @param array<string, mixed> $account @param array<string, int|string> $expectations */
    private function assertExpectedMetrics(string $role, array $account, array $expectations): void
    {
        $expectedCount = $this->nonNegativeInteger($expectations, "expected_{$role}_journal_count");

        if ((int) $account['journal_line_count'] !== $expectedCount) {
            throw new DomainException("The {$role} journal-line count differs from the expected value.");
        }

        foreach (['debit' => 'total_debit', 'credit' => 'total_credit', 'balance' => 'balance'] as $option => $field) {
            $expected = $this->decimal($expectations["expected_{$role}_{$option}"] ?? null);

            if (bccomp((string) $account[$field], $expected, 4) !== 0) {
                throw new DomainException("The {$role} {$option} total differs from the expected value.");
            }
        }
    }

    /** @param list<int> $accountIds @return array<string, mixed> */
    private function journalState(array $accountIds): array
    {
        $journalIds = DB::table('journal_entry_lines')
            ->whereIn('account_id', $accountIds)
            ->orderBy('journal_entry_id')
            ->pluck('journal_entry_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $headers = $journalIds->isEmpty()
            ? collect()
            : DB::table('journal_entries')->whereIn('id', $journalIds->all())->orderBy('id')->get()->map(fn (object $row): array => (array) $row);
        $lines = $journalIds->isEmpty()
            ? collect()
            : DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds->all())->orderBy('journal_entry_id')->orderBy('line_no')->orderBy('id')->get()->map(fn (object $row): array => (array) $row);
        $journals = $journalIds->map(function (int $journalId) use ($headers, $lines): array {
            $header = $headers->firstWhere('id', $journalId);
            $journalLines = $lines->where('journal_entry_id', $journalId);
            $debit = $this->decimal($journalLines->sum('debit_amount'));
            $credit = $this->decimal($journalLines->sum('credit_amount'));

            return [
                'journal_entry_id' => $journalId,
                'doc_num' => $header['doc_num'] ?? null,
                'entry_date' => $header['entry_date'] ?? null,
                'status' => $header['status'] ?? null,
                'is_posted' => (bool) ($header['is_posted'] ?? false),
                'source_type' => $header['source_type'] ?? null,
                'source_id' => $header['source_id'] ?? null,
                'source_doc_num' => $header['source_doc_num'] ?? null,
                'line_count' => $journalLines->count(),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => bcsub($debit, $credit, 4),
                'balanced' => bccomp($debit, $credit, 4) === 0,
            ];
        })->all();

        return [
            'journal_ids' => $journalIds->all(),
            'header_count' => $headers->count(),
            'line_count' => $lines->count(),
            'headers_hash' => $this->hashValue($headers->all()),
            'lines_hash' => $this->hashValue($lines->all()),
            'journals' => $journals,
        ];
    }

    /** @param list<int> $parentIds @return array<string, mixed> */
    private function parentState(array $parentIds, int $companyId): array
    {
        $parents = Account::withTrashed()
            ->where('company_id', $companyId)
            ->whereKey($parentIds)
            ->orderBy('id')
            ->get()
            ->map(fn (Account $account): array => $account->getAttributes())
            ->all();

        return ['account_ids' => $parentIds, 'hash' => $this->hashValue($parents), 'accounts' => $parents];
    }

    /** @param list<int> $accountIds */
    private function lockTouchedJournals(array $accountIds): void
    {
        $journalIds = DB::table('journal_entry_lines')
            ->whereIn('account_id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('journal_entry_id')
            ->unique()
            ->values();

        if ($journalIds->isEmpty()) {
            return;
        }

        DB::table('journal_entries')->whereIn('id', $journalIds->all())->orderBy('id')->lockForUpdate()->get();
        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds->all())->orderBy('id')->lockForUpdate()->get();
    }

    /** @param Collection<int, Account> $accounts */
    private function isAppliedState(FixedAsset $asset, Collection $accounts, int $canonicalAccountId, int $duplicateAccountId, int $parentAccountId): bool
    {
        $canonical = $accounts->firstWhere('id', $canonicalAccountId);
        $duplicate = $accounts->firstWhere('id', $duplicateAccountId);

        return (int) $asset->account_id === $canonicalAccountId
            && $canonical instanceof Account
            && ! $canonical->trashed()
            && $canonical->status === 'active'
            && (int) $canonical->parent_id === $parentAccountId
            && $duplicate instanceof Account
            && $duplicate->trashed()
            && $duplicate->status === 'inactive';
    }

    /** @param array<string, mixed> $manifest @return array<string, mixed> */
    private function verifyAppliedState(FixedAsset $asset, array $manifest): array
    {
        $companyId = (int) $manifest['company']['id'];
        $canonicalAccountId = (int) $manifest['canonical_account_id'];
        $duplicateAccountId = (int) $manifest['duplicate_account_id'];
        $parentAccountId = (int) $manifest['approved_parent_account_id'];
        $assetCount = FixedAsset::withTrashed()
            ->where('company_id', $companyId)
            ->where('doc_num', $manifest['asset']['doc_num'])
            ->count();
        $canonical = Account::withTrashed()->where('company_id', $companyId)->find($canonicalAccountId);
        $duplicate = Account::withTrashed()->where('company_id', $companyId)->find($duplicateAccountId);
        $activeCandidateCount = Account::query()
            ->where('company_id', $companyId)
            ->whereIn('id', [$canonicalAccountId, $duplicateAccountId])
            ->where('status', 'active')
            ->count();
        $journalState = $this->journalState([$canonicalAccountId, $duplicateAccountId]);
        $parentState = $this->parentState($manifest['parent_state']['account_ids'], $companyId);
        $accountingReconciliation = $this->accountingReconciliation($asset, $canonicalAccountId);
        $workflowResolution = $this->workflowResolution($asset, $canonicalAccountId, $manifest, $journalState);
        $structuralChecks = [
            'one_fixed_asset_record' => $assetCount === 1,
            'asset_references_canonical' => (int) $asset->account_id === $canonicalAccountId,
            'one_active_chart_node' => $activeCandidateCount === 1,
            'canonical_under_approved_category' => $canonical instanceof Account && (int) $canonical->parent_id === $parentAccountId,
            'canonical_active_and_postable' => $canonical instanceof Account && ! $canonical->trashed() && $canonical->status === 'active' && ! $canonical->is_group && $canonical->is_postable,
            'duplicate_inactive_and_archived' => $duplicate instanceof Account && $duplicate->trashed() && $duplicate->status === 'inactive',
            'shared_parents_unchanged' => hash_equals((string) $manifest['parent_state']['hash'], (string) $parentState['hash']),
            'journal_headers_unchanged' => hash_equals((string) $manifest['journal_state']['headers_hash'], (string) $journalState['headers_hash']),
            'journal_lines_unchanged' => hash_equals((string) $manifest['journal_state']['lines_hash'], (string) $journalState['lines_hash']),
            'all_touched_journals_balanced' => ! collect($journalState['journals'])->contains(fn (array $journal): bool => $journal['balanced'] !== true),
            'fixed_asset_subledger_reconciles_to_gl' => $accountingReconciliation['reconciled'],
            'depreciation_workflow_resolves' => $workflowResolution['depreciation_workflow_resolves'],
            'disposal_workflow_resolves_canonical' => $workflowResolution['disposal_workflow_resolves_canonical'],
            'reversal_workflow_preserves_original_lines' => $workflowResolution['reversal_workflow_preserves_original_lines'],
            'movement_workflow_resolves_canonical' => $workflowResolution['movement_workflow_resolves_canonical'],
        ];

        if (in_array(false, $structuralChecks, true)) {
            throw new DomainException('Post-repair verification failed; the transaction was rolled back.');
        }

        return [
            ...$structuralChecks,
            'fixed_asset_count' => $assetCount,
            'canonical_account_id' => $canonicalAccountId,
            'duplicate_account_id' => $duplicateAccountId,
            'journal_state' => $journalState,
            'accounting_reconciliation' => $accountingReconciliation,
            'workflow_account_resolution' => $workflowResolution,
        ];
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $journalState @return array<string, mixed> */
    private function workflowResolution(FixedAsset $asset, int $canonicalAccountId, array $manifest, array $journalState): array
    {
        $relationshipAccountId = (int) $asset->account()->withTrashed()->value('id');
        $mapping = FixedAssetCategoryMapping::query()
            ->where('company_id', $asset->company_id)
            ->where('asset_group_account_id', $asset->asset_group_account_id)
            ->first();
        $rawMappingAccountIds = $mapping instanceof FixedAssetCategoryMapping
            ? collect([
                $mapping->accumulated_depreciation_account_id,
                $mapping->depreciation_expense_account_id,
                $mapping->disposal_gain_account_id,
                $mapping->disposal_loss_account_id,
            ])->map(fn (mixed $id): int => (int) $id)
            : collect();
        $mappingAccountIds = $rawMappingAccountIds->filter()->unique()->values();
        $availableMappingAccountCount = $mappingAccountIds->isEmpty()
            ? 0
            : Account::query()
                ->where('company_id', $asset->company_id)
                ->whereKey($mappingAccountIds->all())
                ->where('status', 'active')
                ->where('is_group', false)
                ->where('is_postable', true)
                ->count();
        $depreciationResolves = $mapping instanceof FixedAssetCategoryMapping
            && $rawMappingAccountIds->count() === 4
            && $rawMappingAccountIds->every(fn (int $id): bool => $id > 0)
            && $mappingAccountIds->count() === $availableMappingAccountCount
            && $mappingAccountIds->count() > 0;
        $journalLinesUnchanged = hash_equals(
            (string) $manifest['journal_state']['lines_hash'],
            (string) $journalState['lines_hash'],
        );

        return [
            'asset_relationship_account_id' => $relationshipAccountId,
            'depreciation_category_account_id' => (int) $asset->asset_group_account_id,
            'depreciation_mapping_id' => $mapping?->getKey(),
            'depreciation_mapping_account_ids' => $mappingAccountIds->all(),
            'depreciation_workflow_resolves' => $depreciationResolves,
            'disposal_cost_account_id' => (int) $asset->account_id,
            'disposal_workflow_resolves_canonical' => $relationshipAccountId === $canonicalAccountId
                && (int) $asset->account_id === $canonicalAccountId,
            'reversal_workflow_preserves_original_lines' => $journalLinesUnchanged,
            'movement_workflow_resolves_canonical' => $relationshipAccountId === $canonicalAccountId,
        ];
    }

    /** @return array<string, mixed> */
    private function accountingReconciliation(FixedAsset $asset, int $canonicalAccountId): array
    {
        $bookValue = $this->bookValues->position($asset);
        $costGl = $this->postedAccountBalance((int) $asset->company_id, $canonicalAccountId, true);
        $costDifference = bcsub($bookValue['base_acquisition_cost'], $costGl, 4);
        $mapping = FixedAssetCategoryMapping::query()
            ->where('company_id', $asset->company_id)
            ->where('asset_group_account_id', $asset->asset_group_account_id)
            ->first();
        $depreciationJournalIds = DB::table('fixed_asset_depreciations')
            ->where('fixed_asset_id', $asset->getKey())
            ->where('status', 'posted')
            ->whereNotNull('journal_entry_id')
            ->pluck('journal_entry_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $accumulatedGl = $mapping instanceof FixedAssetCategoryMapping
            ? $this->postedAccountBalance(
                (int) $asset->company_id,
                (int) $mapping->accumulated_depreciation_account_id,
                false,
                $depreciationJournalIds,
            )
            : '0.0000';
        $accumulatedDifference = bcsub($bookValue['base_accumulated_depreciation'], $accumulatedGl, 4);
        $subledgerNet = $bookValue['base_net_book_value'];
        $glNet = bcsub($costGl, $accumulatedGl, 4);
        $netDifference = bcsub($subledgerNet, $glNet, 4);
        $uncapitalizedAndEmpty = $asset->capitalized_at === null
            && bccomp($costGl, '0', 4) === 0
            && $depreciationJournalIds === [];
        $reconciled = $uncapitalizedAndEmpty
            || (bccomp($costDifference, '0', 4) === 0
                && bccomp($accumulatedDifference, '0', 4) === 0
                && bccomp($netDifference, '0', 4) === 0);

        return [
            'scope' => 'asset cost account and asset-linked posted depreciation journals',
            'status' => $uncapitalizedAndEmpty ? 'not_applicable_uncapitalized' : ($reconciled ? 'reconciled' : 'difference'),
            'reconciled' => $reconciled,
            'cost' => [
                'subledger' => $bookValue['base_acquisition_cost'],
                'general_ledger' => $costGl,
                'difference' => $costDifference,
            ],
            'accumulated_depreciation' => [
                'subledger' => $bookValue['base_accumulated_depreciation'],
                'general_ledger' => $accumulatedGl,
                'difference' => $accumulatedDifference,
                'account_id' => $mapping?->accumulated_depreciation_account_id,
                'journal_entry_ids' => $depreciationJournalIds,
            ],
            'net_book_value' => [
                'subledger' => $subledgerNet,
                'general_ledger' => $glNet,
                'difference' => $netDifference,
            ],
        ];
    }

    /** @param list<int>|null $journalEntryIds */
    private function postedAccountBalance(int $companyId, int $accountId, bool $debitNormal, ?array $journalEntryIds = null): string
    {
        if ($journalEntryIds === []) {
            return '0.0000';
        }

        $balance = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.is_posted', true)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entry_lines.account_id', $accountId)
            ->when($journalEntryIds !== null, fn ($query) => $query->whereIn('journal_entries.id', $journalEntryIds))
            ->selectRaw('COALESCE(SUM((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate), 0) AS balance')
            ->value('balance');
        $balance = $this->decimal($balance);

        return $debitNormal ? $balance : bcmul($balance, '-1', 4);
    }

    private function nextChildAccountCode(Account $parent): string
    {
        $prefix = (string) $parent->account_code;
        $suffix = Account::withTrashed()
            ->where('company_id', $parent->company_id)
            ->where('parent_id', $parent->getKey())
            ->pluck('account_code')
            ->map(function (string $accountCode) use ($prefix): ?int {
                $value = substr($accountCode, strlen($prefix));

                return $value !== '' && ctype_digit($value) ? (int) $value : null;
            })
            ->filter(fn (?int $value): bool => $value !== null)
            ->max();

        return $prefix.(string) (($suffix ?? 0) + 1);
    }

    private function repairReceipt(int $assetId, string $reviewToken): ?Activity
    {
        return Activity::query()
            ->where('subject_type', (new FixedAsset)->getMorphClass())
            ->where('subject_id', $assetId)
            ->where('event', 'duplicate_account_repair')
            ->where('status', 'success')
            ->latest('id')
            ->get()
            ->first(fn (Activity $activity): bool => ($this->activityProperties($activity)['manifest_hash'] ?? null) === $reviewToken);
    }

    /** @return array<string, mixed> */
    private function activityProperties(Activity $activity): array
    {
        return $activity->properties instanceof Collection
            ? $activity->properties->all()
            : (is_array($activity->properties) ? $activity->properties : []);
    }

    /** @param array<string, int|string> $values */
    private function positiveInteger(array $values, string $key): int
    {
        $value = filter_var($values[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($value === false) {
            throw new DomainException("{$key} must be a positive integer.");
        }

        return (int) $value;
    }

    /** @param array<string, int|string> $values */
    private function nonNegativeInteger(array $values, string $key): int
    {
        $value = filter_var($values[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($value === false) {
            throw new DomainException("{$key} must be a non-negative integer.");
        }

        return (int) $value;
    }

    private function decimal(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || ! is_numeric($value)) {
            throw new DomainException('Expected financial values must be numeric.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    private function hashValue(mixed $value): string
    {
        return hash('sha256', (string) json_encode($this->canonicalize($value), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @template TReturn @param callable(): TReturn $callback @return TReturn */
    private function inReadOnlyTransaction(callable $callback): mixed
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $sqliteQueryOnly = false;

        if ($driver === 'mysql') {
            $connection->statement('SET TRANSACTION READ ONLY');
        } elseif ($driver === 'sqlite') {
            $connection->statement('PRAGMA query_only = ON');
            $sqliteQueryOnly = true;
        } elseif ($driver !== 'pgsql') {
            throw new DomainException("The {$driver} driver has no configured read-only review guard.");
        }

        $connection->beginTransaction();

        try {
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
            }

            return $callback();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            if ($sqliteQueryOnly) {
                $connection->statement('PRAGMA query_only = OFF');
            }
        }
    }
}
