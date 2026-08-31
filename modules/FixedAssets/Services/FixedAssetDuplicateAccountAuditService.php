<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Models\FixedAsset;
use Spatie\Activitylog\Models\Activity;

class FixedAssetDuplicateAccountAuditService
{
    public const ClassificationOldPostedNewEmpty = 'A_OLD_POSTED_NEW_EMPTY';

    public const ClassificationOldEmptyNewPosted = 'B_OLD_EMPTY_NEW_POSTED';

    public const ClassificationBothEmpty = 'C_BOTH_EMPTY';

    public const ClassificationBothPosted = 'D_BOTH_POSTED';

    public const ClassificationOtherReferences = 'E_OTHER_REFERENCES';

    public function __construct(
        private readonly FixedAssetAccountReferenceInspector $referenceInspector,
    ) {}

    /** @return array<string, mixed> */
    public function auditReadOnly(Company $company, ?string $assetDocNum = null): array
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

            $result = $this->audit($company, $assetDocNum);
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
    public function audit(Company $company, ?string $assetDocNum = null): array
    {
        $assets = FixedAsset::query()
            ->forCompany((int) $company->getKey())
            ->when($assetDocNum, fn ($query) => $query->where('doc_num', $assetDocNum))
            ->oldest('id')
            ->get();

        if ($assetDocNum !== null && $assets->count() !== 1) {
            throw new DomainException('The requested company and fixed asset document number must resolve to exactly one active record.');
        }

        $allAccounts = Account::withTrashed()
            ->forCompany((int) $company->getKey())
            ->oldest('id')
            ->get()
            ->keyBy(fn (Account $account): int => (int) $account->getKey());
        $root = $this->fixedAssetRoot($allAccounts);
        $activityEvidence = $this->activityTransitionEvidence($assets, (int) $company->getKey());
        $metadataEvidence = $this->stableMetadataEvidence($assets, $allAccounts);
        $sourceEvidence = $this->sourceDocumentEvidence($assets, $allAccounts, $root, (int) $company->getKey());
        $creationEvidence = $this->accountCreationEvidence($assets, $allAccounts, (int) $company->getKey());
        $candidateMaps = $assets->mapWithKeys(function (FixedAsset $asset) use ($allAccounts, $root, $activityEvidence, $metadataEvidence, $sourceEvidence, $creationEvidence): array {
            $assetId = (int) $asset->getKey();

            return [$assetId => $this->candidateEvidence(
                $asset,
                $allAccounts,
                $root,
                $activityEvidence[$assetId] ?? [],
                $metadataEvidence[$assetId] ?? [],
                $sourceEvidence[$assetId] ?? [],
                $creationEvidence[$assetId] ?? [],
            )];
        });
        $candidateAccountIds = $candidateMaps
            ->flatMap(fn (array $candidates): array => array_keys($candidates))
            ->map(fn (mixed $accountId): int => (int) $accountId)
            ->unique()
            ->values()
            ->all();
        $financials = $this->financialMetrics($candidateAccountIds);
        $journals = $this->journalDocuments($candidateAccountIds);
        $references = $this->referenceInspector->inspect($candidateAccountIds);
        $creationEvents = $this->accountCreationEvents($candidateAccountIds, (int) $company->getKey());
        $cases = $assets
            ->map(fn (FixedAsset $asset): ?array => $this->assetCase(
                $asset,
                $allAccounts,
                $root,
                $candidateMaps[(int) $asset->getKey()] ?? [],
                $financials,
                $journals,
                $references,
                $creationEvents,
            ))
            ->filter()
            ->values();
        $actualDuplicates = $cases->where('is_actual_duplicate', true);

        return [
            'generated_at' => now()->toIso8601String(),
            'company' => [
                'id' => (int) $company->getKey(),
                'doc_num' => $company->doc_num,
                'name' => (string) $company->name,
            ],
            'read_only_enforced' => false,
            'assets_scanned' => $assets->count(),
            'actual_duplicates' => $actualDuplicates->count(),
            'duplicate_assets' => $actualDuplicates->count(),
            'review_candidates' => $cases->where('is_actual_duplicate', false)->count(),
            'safe_empty_account_repairs' => $actualDuplicates->where('repair_status', 'safe')->count(),
            'safe_cases' => $actualDuplicates->where('repair_status', 'safe')->count(),
            'both_posted_blocked_cases' => $actualDuplicates->where('classification', self::ClassificationBothPosted)->count(),
            'other_reference_blocked_cases' => $actualDuplicates->where('classification', self::ClassificationOtherReferences)->count(),
            'blocked_cases' => $cases->where('repair_status', 'blocked')->count(),
            'cases' => $cases->all(),
        ];
    }

    /** @param Collection<int, Account> $allAccounts */
    private function fixedAssetRoot(Collection $allAccounts): ?Account
    {
        $metadataMatches = $allAccounts
            ->filter(fn (Account $account): bool => ! $account->trashed()
                && $account->notes === 'system:fixed_asset_foundation:fixed_assets')
            ->values();

        if ($metadataMatches->count() === 1) {
            return $metadataMatches->first();
        }

        $codeMatches = $allAccounts
            ->filter(fn (Account $account): bool => ! $account->trashed()
                && (string) $account->account_code === '121'
                && $account->is_group
                && ! $account->is_postable)
            ->values();

        return $codeMatches->count() === 1 ? $codeMatches->first() : null;
    }

    /**
     * @param  Collection<int, FixedAsset>  $assets
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    private function activityTransitionEvidence(Collection $assets, int $companyId): array
    {
        if ($assets->isEmpty()) {
            return [];
        }

        $activities = Activity::query()
            ->where('subject_type', (new FixedAsset)->getMorphClass())
            ->whereIn('subject_id', $assets->pluck('id')->all())
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->oldest('id')
            ->get(['id', 'subject_id', 'company_id', 'event', 'action', 'status', 'properties', 'created_at'])
            ->groupBy(fn (Activity $activity): int => (int) $activity->subject_id);
        $evidence = [];

        foreach ($assets as $asset) {
            $edges = [];

            foreach ($activities->get((int) $asset->getKey(), collect()) as $activity) {
                if ($activity->status !== null && $activity->status !== 'success') {
                    continue;
                }

                $action = Str::lower(trim((string) ($activity->action ?: $activity->event)));

                if ($action !== '' && ! Str::contains($action, 'update')) {
                    continue;
                }

                $edge = $this->activityAccountEdge($this->activityProperties($activity));

                if ($edge === null || $edge['old'] === $edge['new']) {
                    continue;
                }

                $edges[] = [
                    ...$edge,
                    'confidence' => (int) $activity->company_id === $companyId
                        && $activity->status === 'success'
                        && $action !== '' ? 'high' : 'medium',
                    'activity_id' => (int) $activity->getKey(),
                    'event' => $activity->event,
                    'action' => $activity->action,
                    'recorded_at' => $activity->created_at?->toIso8601String(),
                ];
            }

            $connectedIds = [(int) $asset->account_id];
            $changed = true;

            while ($changed) {
                $changed = false;

                foreach ($edges as $edge) {
                    if (in_array($edge['new'], $connectedIds, true) && ! in_array($edge['old'], $connectedIds, true)) {
                        $connectedIds[] = $edge['old'];
                        $changed = true;
                    }
                }
            }

            foreach ($edges as $edge) {
                if (! in_array($edge['old'], $connectedIds, true) || ! in_array($edge['new'], $connectedIds, true)) {
                    continue;
                }

                foreach (['old', 'new'] as $role) {
                    $accountId = $edge[$role];
                    $evidence[(int) $asset->getKey()][$accountId][] = [
                        'type' => 'fixed_asset_account_change_activity',
                        'source' => "activity_log.properties.{$edge['source']}.{$role}",
                        'value_role' => $role,
                        'old_account_id' => $edge['old'],
                        'new_account_id' => $edge['new'],
                        'activity_id' => $edge['activity_id'],
                        'event' => $edge['event'],
                        'action' => $edge['action'],
                        'recorded_at' => $edge['recorded_at'],
                        'confidence' => $edge['confidence'],
                    ];
                }
            }
        }

        return $evidence;
    }

    /** @return array{old: int, new: int, source: string}|null */
    private function activityAccountEdge(array $properties): ?array
    {
        foreach ([
            ['changes.account_id.old', 'changes.account_id.new', 'changes.account_id'],
            ['old.account_id', 'attributes.account_id', 'old/attributes.account_id'],
            ['old_values.account_id', 'new_values.account_id', 'old_values/new_values.account_id'],
            ['account_id.old', 'account_id.new', 'account_id'],
        ] as [$oldPath, $newPath, $source]) {
            $old = $this->positiveAccountId(data_get($properties, $oldPath));
            $new = $this->positiveAccountId(data_get($properties, $newPath));

            if ($old !== null && $new !== null) {
                return compact('old', 'new', 'source');
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, FixedAsset>  $assets
     * @param  Collection<int, Account>  $allAccounts
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    private function stableMetadataEvidence(Collection $assets, Collection $allAccounts): array
    {
        $evidence = [];

        foreach ($assets as $asset) {
            $assetId = (int) $asset->getKey();
            $assetDocNum = preg_quote((string) $asset->doc_num, '/');

            foreach ($allAccounts as $account) {
                $notes = trim((string) $account->notes);

                if ($notes === '') {
                    continue;
                }

                $idMatches = preg_match('/(?:fixed_asset_id|asset_id)\s*[:=]\s*'.$assetId.'(?:\D|$)/i', $notes) === 1;
                $docMatches = $assetDocNum !== ''
                    && preg_match('/(?:fixed_asset_doc_num|asset_doc_num)\s*[:=]\s*["\']?'.$assetDocNum.'(?:["\']|\s|,|;|$)/i', $notes) === 1;

                if (! $idMatches && ! $docMatches) {
                    continue;
                }

                $evidence[$assetId][(int) $account->getKey()][] = [
                    'type' => 'stable_account_source_metadata',
                    'source' => 'accounts.notes',
                    'matched' => $idMatches ? 'fixed_asset_id' : 'fixed_asset_doc_num',
                    'confidence' => 'high',
                ];
            }
        }

        return $evidence;
    }

    /**
     * @param  Collection<int, FixedAsset>  $assets
     * @param  Collection<int, Account>  $allAccounts
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    private function sourceDocumentEvidence(Collection $assets, Collection $allAccounts, ?Account $root, int $companyId): array
    {
        $documentOwners = [];

        foreach ($assets as $asset) {
            foreach (array_filter([(string) $asset->doc_num, trim((string) $asset->source_doc_num)]) as $document) {
                $documentOwners[$document][] = (int) $asset->getKey();
            }
        }

        if ($documentOwners === []) {
            return [];
        }

        $documentOwners = array_filter($documentOwners, fn (array $assetIds): bool => count(array_unique($assetIds)) === 1);

        if ($documentOwners === []) {
            return [];
        }

        $rows = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->whereIn('journal_entries.source_doc_num', array_keys($documentOwners))
            ->get([
                'journal_entry_lines.account_id',
                'journal_entries.id as journal_entry_id',
                'journal_entries.doc_num as journal_doc_num',
                'journal_entries.source_type',
                'journal_entries.source_id',
                'journal_entries.source_doc_num',
            ]);
        $evidence = [];

        foreach ($rows as $row) {
            $account = $allAccounts->get((int) $row->account_id);

            if (! $account instanceof Account
                || ((int) $account->getKey() !== (int) $assets->firstWhere('id', $documentOwners[$row->source_doc_num][0])?->account_id
                    && (! $root instanceof Account || ! $this->pathContains($account, $root, $allAccounts)))) {
                continue;
            }

            foreach ($documentOwners[$row->source_doc_num] ?? [] as $assetId) {
                $asset = $assets->firstWhere('id', $assetId);
                $directAssetDocument = $asset instanceof FixedAsset && (string) $row->source_doc_num === (string) $asset->doc_num;
                $evidence[$assetId][(int) $account->getKey()][] = [
                    'type' => 'journal_source_document_reference',
                    'source' => 'journal_entries.source_doc_num',
                    'journal_entry_id' => (int) $row->journal_entry_id,
                    'journal_doc_num' => $row->journal_doc_num,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'source_doc_num' => $row->source_doc_num,
                    'confidence' => $directAssetDocument ? 'high' : 'medium',
                ];
            }
        }

        return $evidence;
    }

    /**
     * @param  Collection<int, FixedAsset>  $assets
     * @param  Collection<int, Account>  $allAccounts
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    private function accountCreationEvidence(Collection $assets, Collection $allAccounts, int $companyId): array
    {
        if ($assets->isEmpty()) {
            return [];
        }

        $assetsById = $assets->keyBy(fn (FixedAsset $asset): int => (int) $asset->getKey());
        $assetsByDocument = [];

        foreach ($assets as $asset) {
            foreach (array_filter([(string) $asset->doc_num, trim((string) $asset->source_doc_num)]) as $document) {
                $assetsByDocument[$document][] = $asset;
            }
        }
        $activities = Activity::query()
            ->where('subject_type', (new Account)->getMorphClass())
            ->whereIn('subject_id', $allAccounts->keys()->all())
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(function ($query): void {
                $query->where('event', 'like', '%create%')->orWhere('action', 'like', '%create%');
            })
            ->oldest('id')
            ->get(['id', 'subject_id', 'company_id', 'event', 'action', 'status', 'properties', 'created_at']);
        $evidence = [];

        foreach ($activities as $activity) {
            if ($activity->status !== null && $activity->status !== 'success') {
                continue;
            }

            $properties = $this->activityProperties($activity);
            $assetIds = collect([
                data_get($properties, 'fixed_asset_id'),
                data_get($properties, 'asset_id'),
                data_get($properties, 'context.fixed_asset_id'),
                data_get($properties, 'attributes.fixed_asset_id'),
            ])->map(fn (mixed $value): ?int => $this->positiveAccountId($value))->filter();
            $sourceType = Str::lower((string) (data_get($properties, 'source_type') ?? data_get($properties, 'source.type')));

            if (Str::contains(Str::replace(['_', '-', '\\'], '', $sourceType), 'fixedasset')) {
                $assetIds->push($this->positiveAccountId(data_get($properties, 'source_id') ?? data_get($properties, 'source.id')));
            }

            $documentValues = collect([
                data_get($properties, 'fixed_asset_doc_num'),
                data_get($properties, 'asset_doc_num'),
                data_get($properties, 'source_doc_num'),
                data_get($properties, 'context.fixed_asset_doc_num'),
                data_get($properties, 'attributes.fixed_asset_doc_num'),
            ])->map(fn (mixed $value): string => trim((string) $value))->filter();
            $matchedAssets = $assetIds
                ->filter()
                ->map(fn (int $assetId): ?FixedAsset => $assetsById->get($assetId))
                ->merge($documentValues->map(fn (string $document): ?FixedAsset => count($assetsByDocument[$document] ?? []) === 1
                    ? $assetsByDocument[$document][0]
                    : null))
                ->filter(fn (?FixedAsset $asset): bool => $asset instanceof FixedAsset)
                ->unique(fn (FixedAsset $asset): int => (int) $asset->getKey())
                ->values();

            if ($matchedAssets->count() !== 1 || ! $allAccounts->has((int) $activity->subject_id)) {
                continue;
            }

            $asset = $matchedAssets->first();
            $evidence[(int) $asset->getKey()][(int) $activity->subject_id][] = [
                'type' => 'account_creation_source_metadata',
                'source' => 'activity_log.account_creation.properties',
                'activity_id' => (int) $activity->getKey(),
                'event' => $activity->event,
                'action' => $activity->action,
                'recorded_at' => $activity->created_at?->toIso8601String(),
                'confidence' => (int) $activity->company_id === $companyId && $activity->status === 'success' ? 'high' : 'medium',
            ];
        }

        return $evidence;
    }

    /**
     * @param  Collection<int, Account>  $allAccounts
     * @param  array<int, list<array<string, mixed>>>  $activityEvidence
     * @param  array<int, list<array<string, mixed>>>  $metadataEvidence
     * @param  array<int, list<array<string, mixed>>>  $sourceEvidence
     * @param  array<int, list<array<string, mixed>>>  $creationEvidence
     * @return array<int, list<array<string, mixed>>>
     */
    private function candidateEvidence(
        FixedAsset $asset,
        Collection $allAccounts,
        ?Account $root,
        array $activityEvidence,
        array $metadataEvidence,
        array $sourceEvidence,
        array $creationEvidence,
    ): array {
        $candidates = [];
        $currentAccountId = (int) $asset->account_id;

        if ($currentAccountId > 0) {
            $candidates[$currentAccountId][] = [
                'type' => 'stable_current_reference',
                'source' => 'fixed_assets.account_id',
                'asset_id' => (int) $asset->getKey(),
                'asset_doc_num' => $asset->doc_num,
                'confidence' => 'high',
            ];
        }

        foreach ([$activityEvidence, $metadataEvidence, $sourceEvidence, $creationEvidence] as $evidenceGroup) {
            foreach ($evidenceGroup as $accountId => $items) {
                $candidates[(int) $accountId] = [...($candidates[(int) $accountId] ?? []), ...$items];
            }
        }

        if ($root instanceof Account) {
            foreach ($allAccounts as $account) {
                if ($account->trashed()
                    || $account->is_group
                    || ! $account->is_postable
                    || $this->normalizedName((string) $account->name) !== $this->normalizedName((string) $asset->asset_name)
                    || ! $this->pathContains($account, $root, $allAccounts)
                ) {
                    continue;
                }

                $accountId = (int) $account->getKey();

                if (array_key_exists($accountId, $candidates)) {
                    continue;
                }

                $candidates[$accountId] = [
                    [
                        'type' => 'review_name_match',
                        'source' => 'accounts.name',
                        'value' => $account->name,
                        'confidence' => 'review_only',
                    ],
                    [
                        'type' => 'review_hierarchy_match',
                        'source' => 'accounts.parent_id ancestry',
                        'value' => (int) $root->getKey(),
                        'confidence' => 'review_only',
                    ],
                    [
                        'type' => 'review_timestamp_proximity',
                        'source' => 'accounts.created_at/fixed_assets.updated_at',
                        'account_created_at' => $account->created_at?->toIso8601String(),
                        'asset_created_at' => $asset->created_at?->toIso8601String(),
                        'asset_updated_at' => $asset->updated_at?->toIso8601String(),
                        'confidence' => 'review_only',
                    ],
                ];
            }
        }

        ksort($candidates);

        return $candidates;
    }

    /**
     * @param  Collection<int, Account>  $allAccounts
     * @param  array<int, list<array<string, mixed>>>  $candidateEvidence
     * @param  array<int, array<string, mixed>>  $financials
     * @param  array<int, list<array<string, mixed>>>  $journals
     * @param  array<int, list<array<string, mixed>>>  $references
     * @param  array<int, list<array<string, mixed>>>  $creationEvents
     * @return array<string, mixed>|null
     */
    private function assetCase(
        FixedAsset $asset,
        Collection $allAccounts,
        ?Account $root,
        array $candidateEvidence,
        array $financials,
        array $journals,
        array $references,
        array $creationEvents,
    ): ?array {
        $candidateAccounts = collect(array_keys($candidateEvidence))
            ->map(fn (int $accountId): ?Account => $allAccounts->get($accountId))
            ->filter(fn (?Account $account): bool => $account instanceof Account)
            ->sortBy('id')
            ->values();
        $activeCandidates = $candidateAccounts->reject(fn (Account $account): bool => $account->trashed())->values();

        if ($activeCandidates->count() < 2) {
            return null;
        }

        $accountRows = $candidateAccounts->map(function (Account $account) use ($asset, $allAccounts, $root, $candidateEvidence, $financials, $journals, $references, $creationEvents): array {
            $accountId = (int) $account->getKey();
            $accountEvidence = $candidateEvidence[$accountId] ?? [];
            $confidence = $this->evidenceConfidence($accountEvidence);
            $accountReferences = $references[$accountId] ?? [];
            $otherReferences = $this->otherReferences($accountReferences, $asset);
            $financial = $financials[$accountId] ?? $this->emptyFinancialMetrics();

            return [
                'id' => $accountId,
                'doc_num' => $account->doc_num,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'parent_id' => $account->parent_id,
                'parent_doc_num' => $allAccounts->get((int) $account->parent_id)?->doc_num,
                'parent_path' => $account->parent_id ? $this->path($allAccounts->get((int) $account->parent_id), $allAccounts) : null,
                'full_path' => $this->path($account, $allAccounts),
                'path' => $this->path($account, $allAccounts),
                'status' => $account->status,
                'deleted' => $account->trashed(),
                'created_at' => $account->created_at?->toIso8601String(),
                'updated_at' => $account->updated_at?->toIso8601String(),
                'deleted_at' => $account->deleted_at?->toIso8601String(),
                'is_current_reference' => (int) $asset->account_id === $accountId,
                'association_role' => (int) $asset->account_id === $accountId
                    ? 'current_referenced_account'
                    : ($confidence === 'review_only' ? 'review_candidate' : 'previous_orphan_account'),
                'eligible_fixed_asset_leaf' => $root instanceof Account
                    && ! $account->is_group
                    && $account->is_postable
                    && $this->pathContains($account, $root, $allAccounts),
                'evidence' => $accountEvidence,
                'detection_confidence' => $confidence,
                'account_creation_events' => $creationEvents[$accountId] ?? [],
                'referencing_source_documents' => $journals[$accountId] ?? [],
                'all_database_references' => $accountReferences,
                'other_database_references' => $otherReferences,
                'other_database_reference_count' => collect($otherReferences)->sum('count'),
                ...$financial,
            ];
        })->all();
        $activeRows = collect($accountRows)->where('deleted', false)->values();
        $reliableRows = $activeRows->whereIn('detection_confidence', ['high', 'medium'])->values();
        $isActualDuplicate = $reliableRows->count() >= 2;
        $postedRows = $activeRows->filter(fn (array $account): bool => $account['posted_journal_line_count'] > 0)->values();
        $hasOtherReferences = $activeRows->contains(fn (array $account): bool => $account['other_database_reference_count'] > 0
            || $account['non_posted_journal_line_count'] > 0);
        $classification = $this->classification($asset, $postedRows, $hasOtherReferences);
        $canonical = $this->recommendedCanonical($asset, $activeRows, $postedRows, $isActualDuplicate);
        $currentAccount = $activeRows->firstWhere('id', (int) $asset->account_id);
        $previousOrphans = $activeRows
            ->where('id', '!=', (int) $asset->account_id)
            ->whereIn('detection_confidence', ['high', 'medium'])
            ->values();
        $reviewOnlyCandidates = $activeRows
            ->where('id', '!=', (int) $asset->account_id)
            ->where('detection_confidence', 'review_only')
            ->values();
        [$repairStatus, $reason] = $this->repairDecision(
            $asset,
            $activeRows,
            $classification,
            $canonical,
            $isActualDuplicate,
            $root,
            $allAccounts,
        );

        return [
            'company_id' => (int) $asset->company_id,
            'asset' => [
                'id' => (int) $asset->getKey(),
                'doc_num' => $asset->doc_num,
                'name' => $asset->asset_name,
                'status' => $asset->status,
                'deleted' => $asset->trashed(),
                'created_at' => $asset->created_at?->toIso8601String(),
                'updated_at' => $asset->updated_at?->toIso8601String(),
                'deleted_at' => $asset->deleted_at?->toIso8601String(),
                'current_account_id' => $asset->account_id,
                'asset_group_account_id' => $asset->asset_group_account_id,
                'source_type' => $asset->source_type,
                'source_id' => $asset->source_id,
                'source_doc_num' => $asset->source_doc_num,
                'capitalized_at' => $asset->capitalized_at?->toIso8601String(),
            ],
            'asset_id' => (int) $asset->getKey(),
            'asset_doc_num' => $asset->doc_num,
            'asset_name' => $asset->asset_name,
            'current_account_id' => $asset->account_id,
            'current_account' => $currentAccount,
            'previous_orphan_accounts' => $previousOrphans->all(),
            'name_similarity_review_candidates' => $reviewOnlyCandidates->all(),
            'approved_parent_account_id' => $asset->asset_group_account_id,
            'approved_parent_path' => $asset->asset_group_account_id
                ? $this->path($allAccounts->get((int) $asset->asset_group_account_id), $allAccounts)
                : null,
            'candidate_account_ids' => $activeRows->pluck('id')->all(),
            'is_actual_duplicate' => $isActualDuplicate,
            'detection_confidence' => $isActualDuplicate && $reliableRows->every(fn (array $account): bool => $account['detection_confidence'] === 'high')
                ? 'high'
                : ($isActualDuplicate ? 'medium' : 'review_only'),
            'classification' => $classification,
            'repair_status' => $repairStatus,
            'safe_or_blocked' => $repairStatus,
            'repair_reason' => $reason,
            'decision_reason' => $reason,
            'recommended_canonical_account_id' => $canonical['id'] ?? (int) $asset->account_id,
            'duplicate_account_ids' => $activeRows->where('id', '!=', $canonical['id'] ?? (int) $asset->account_id)->pluck('id')->all(),
            'accounts' => $accountRows,
            'requires_financial_review' => $postedRows->count() > 1,
            'financial_review_pack' => $postedRows->count() > 1
                ? $this->financialReviewPack($activeRows)
                : null,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $postedRows */
    private function classification(FixedAsset $asset, Collection $postedRows, bool $hasOtherReferences): string
    {
        if ($hasOtherReferences) {
            return self::ClassificationOtherReferences;
        }

        if ($postedRows->count() > 1) {
            return self::ClassificationBothPosted;
        }

        if ($postedRows->count() === 1) {
            return (int) $postedRows->first()['id'] === (int) $asset->account_id
                ? self::ClassificationOldEmptyNewPosted
                : self::ClassificationOldPostedNewEmpty;
        }

        return self::ClassificationBothEmpty;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $activeRows
     * @param  Collection<int, array<string, mixed>>  $postedRows
     * @return array<string, mixed>|null
     */
    private function recommendedCanonical(FixedAsset $asset, Collection $activeRows, Collection $postedRows, bool $isActualDuplicate): ?array
    {
        if ($postedRows->count() === 1) {
            return $postedRows->first();
        }

        if ($isActualDuplicate && $postedRows->isEmpty()) {
            return $activeRows
                ->where('detection_confidence', 'high')
                ->sortBy(fn (array $account): string => ($account['created_at'] ?? '9999').'-'.str_pad((string) $account['id'], 20, '0', STR_PAD_LEFT))
                ->first();
        }

        return $activeRows->firstWhere('id', (int) $asset->account_id) ?: $activeRows->first();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $activeRows
     * @param  Collection<int, Account>  $allAccounts
     * @return array{0: string, 1: string}
     */
    private function repairDecision(
        FixedAsset $asset,
        Collection $activeRows,
        string $classification,
        ?array $canonical,
        bool $isActualDuplicate,
        ?Account $root,
        Collection $allAccounts,
    ): array {
        if (! $isActualDuplicate) {
            return ['blocked', 'Name, hierarchy, or timestamp similarity identified a review candidate, but no stable ownership evidence proves a duplicate.'];
        }

        if ($activeRows->count() !== 2) {
            return ['blocked', 'More than two active candidate accounts exist; review and repair one explicitly approved pair at a time.'];
        }

        if ($activeRows->contains(fn (array $account): bool => $account['detection_confidence'] !== 'high')) {
            return ['blocked', 'Every account in an automatic repair requires high-confidence ownership evidence.'];
        }

        if ($activeRows->contains(fn (array $account): bool => ! $account['eligible_fixed_asset_leaf'])) {
            return ['blocked', 'A candidate is not a valid postable leaf under the unambiguous Fixed Asset account root.'];
        }

        if ($classification === self::ClassificationBothPosted) {
            return ['blocked', 'Both accounts contain posted journal history; financial review is mandatory and journal lines must not be moved.'];
        }

        if ($classification === self::ClassificationOtherReferences) {
            return ['blocked', 'A candidate has non-posted journals or database references outside the expected asset ownership and posted journal lines.'];
        }

        if (! is_array($canonical)) {
            return ['blocked', 'No canonical account can be selected deterministically.'];
        }

        $approvedParent = $asset->asset_group_account_id
            ? $allAccounts->get((int) $asset->asset_group_account_id)
            : null;

        if (! $root instanceof Account
            || ! $approvedParent instanceof Account
            || $approvedParent->trashed()
            || $approvedParent->status !== 'active'
            || ! $approvedParent->is_group
            || $approvedParent->is_postable
            || ! $this->pathContains($approvedParent, $root, $allAccounts)
        ) {
            return ['blocked', 'The approved current Fixed Asset category is unavailable, ambiguous, or invalid.'];
        }

        return ['safe', match ($classification) {
            self::ClassificationOldPostedNewEmpty => 'Keep the original posted account, repoint and reparent it, then archive the empty current replacement.',
            self::ClassificationOldEmptyNewPosted => 'Keep the current posted account and archive the empty historical orphan.',
            default => 'Keep the oldest account with reliable ownership evidence, repoint and reparent it, then archive the other empty account.',
        }];
    }

    /** @param list<int> $accountIds @return array<int, array<string, mixed>> */
    private function financialMetrics(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $postedPredicate = "journal_entries.status = 'posted' AND journal_entries.is_posted = TRUE AND journal_entries.deleted_at IS NULL";
        $rows = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->selectRaw('journal_entry_lines.account_id AS account_id')
            ->selectRaw('COUNT(*) AS journal_line_count')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit_amount), 0) AS total_debit')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit_amount), 0) AS total_credit')
            ->selectRaw("SUM(CASE WHEN {$postedPredicate} THEN 1 ELSE 0 END) AS posted_journal_line_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$postedPredicate} THEN journal_entry_lines.debit_amount ELSE 0 END), 0) AS posted_debit_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$postedPredicate} THEN journal_entry_lines.credit_amount ELSE 0 END), 0) AS posted_credit_total")
            ->groupBy('journal_entry_lines.account_id')
            ->get()
            ->keyBy('account_id');
        $metrics = [];

        foreach ($accountIds as $accountId) {
            $row = $rows->get($accountId);
            $journalLineCount = (int) ($row?->journal_line_count ?? 0);
            $postedJournalLineCount = (int) ($row?->posted_journal_line_count ?? 0);
            $totalDebit = $this->decimal($row?->total_debit ?? 0);
            $totalCredit = $this->decimal($row?->total_credit ?? 0);
            $postedDebit = $this->decimal($row?->posted_debit_total ?? 0);
            $postedCredit = $this->decimal($row?->posted_credit_total ?? 0);
            $metrics[$accountId] = [
                'journal_line_count' => $journalLineCount,
                'journal_lines' => $journalLineCount,
                'posted_journal_line_count' => $postedJournalLineCount,
                'non_posted_journal_line_count' => $journalLineCount - $postedJournalLineCount,
                'total_debit' => $totalDebit,
                'debit_total' => $totalDebit,
                'total_credit' => $totalCredit,
                'credit_total' => $totalCredit,
                'balance' => bcsub($totalDebit, $totalCredit, 4),
                'posted_debit_total' => $postedDebit,
                'posted_credit_total' => $postedCredit,
                'posted_balance' => bcsub($postedDebit, $postedCredit, 4),
            ];
        }

        return $metrics;
    }

    /** @param list<int> $accountIds @return array<int, list<array<string, mixed>>> */
    private function journalDocuments(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->selectRaw('journal_entry_lines.account_id AS account_id, journal_entries.id AS journal_entry_id')
            ->addSelect([
                'journal_entries.doc_num as journal_doc_num',
                'journal_entries.entry_date',
                'journal_entries.status',
                'journal_entries.is_posted',
                'journal_entries.deleted_at',
                'journal_entries.source_type',
                'journal_entries.source_id',
                'journal_entries.source_doc_num',
                'journal_entries.reversed_entry_id',
            ])
            ->selectRaw('COUNT(*) AS line_count')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit_amount), 0) AS debit_total')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit_amount), 0) AS credit_total')
            ->groupBy([
                'journal_entry_lines.account_id', 'journal_entries.id', 'journal_entries.doc_num',
                'journal_entries.entry_date', 'journal_entries.status', 'journal_entries.is_posted',
                'journal_entries.deleted_at', 'journal_entries.source_type', 'journal_entries.source_id',
                'journal_entries.source_doc_num', 'journal_entries.reversed_entry_id',
            ])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->get()
            ->groupBy(fn (object $row): int => (int) $row->account_id)
            ->map(fn (Collection $rows): array => $rows->map(function (object $row): array {
                $debit = $this->decimal($row->debit_total);
                $credit = $this->decimal($row->credit_total);

                return [
                    'journal_entry_id' => (int) $row->journal_entry_id,
                    'journal_doc_num' => $row->journal_doc_num,
                    'entry_date' => (string) $row->entry_date,
                    'status' => $row->status,
                    'is_posted' => (bool) $row->is_posted,
                    'deleted_at' => $row->deleted_at,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'source_doc_num' => $row->source_doc_num,
                    'reversed_entry_id' => $row->reversed_entry_id,
                    'line_count' => (int) $row->line_count,
                    'debit_total' => $debit,
                    'credit_total' => $credit,
                    'balance' => bcsub($debit, $credit, 4),
                ];
            })->all())
            ->all();
    }

    /** @param list<int> $accountIds @return array<int, list<array<string, mixed>>> */
    private function accountCreationEvents(array $accountIds, int $companyId): array
    {
        if ($accountIds === []) {
            return [];
        }

        return Activity::query()
            ->where('subject_type', (new Account)->getMorphClass())
            ->whereIn('subject_id', $accountIds)
            ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(function ($query): void {
                $query->where('event', 'like', '%create%')->orWhere('action', 'like', '%create%');
            })
            ->oldest('id')
            ->get(['id', 'subject_id', 'company_id', 'event', 'action', 'status', 'properties', 'created_at'])
            ->groupBy(fn (Activity $activity): int => (int) $activity->subject_id)
            ->map(fn (Collection $activities): array => $activities->map(fn (Activity $activity): array => [
                'activity_id' => (int) $activity->getKey(),
                'event' => $activity->event,
                'action' => $activity->action,
                'status' => $activity->status,
                'company_id' => $activity->company_id,
                'created_at' => $activity->created_at?->toIso8601String(),
                'properties' => $this->activityProperties($activity),
            ])->all())
            ->all();
    }

    /**
     * @param  list<array{table: string, column: string, count: int, rows: list<array<string, mixed>>}>  $references
     * @return list<array{table: string, column: string, count: int, rows: list<array<string, mixed>>}>
     */
    private function otherReferences(array $references, FixedAsset $asset): array
    {
        return collect($references)
            ->filter(function (array $reference) use ($asset): bool {
                if ($reference['table'] === 'journal_entry_lines' && $reference['column'] === 'account_id') {
                    return false;
                }

                if ($reference['table'] !== 'fixed_assets' || $reference['column'] !== 'account_id') {
                    return true;
                }

                return collect($reference['rows'])->contains(fn (array $row): bool => (int) ($row['id'] ?? 0) !== (int) $asset->getKey())
                    || $reference['count'] !== 1;
            })
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $activeRows @return array<string, mixed> */
    private function financialReviewPack(Collection $activeRows): array
    {
        return [
            'treatment' => [
                'duplicate_posting' => 'Reverse the erroneous source journal through the normal reversal workflow.',
                'valid_split_postings' => 'Post an approved reclassification journal at an explicit cutover date, then deactivate the obsolete account.',
                'closed_period_rule' => 'Do not rewrite closed-period history.',
            ],
            'accounts' => $activeRows->map(fn (array $account): array => [
                'account_id' => $account['id'],
                'account_doc_num' => $account['doc_num'],
                'posted_journal_line_count' => $account['posted_journal_line_count'],
                'posted_debit_total' => $account['posted_debit_total'],
                'posted_credit_total' => $account['posted_credit_total'],
                'posted_balance' => $account['posted_balance'],
                'journals' => $account['referencing_source_documents'],
            ])->all(),
        ];
    }

    /** @param list<array<string, mixed>> $evidence */
    private function evidenceConfidence(array $evidence): string
    {
        $confidences = collect($evidence)->pluck('confidence');

        return $confidences->contains('high') ? 'high' : ($confidences->contains('medium') ? 'medium' : 'review_only');
    }

    /** @param Collection<int, Account> $allAccounts */
    private function pathContains(Account $account, Account $ancestor, Collection $allAccounts): bool
    {
        $visited = [];
        $current = $account;

        while ($current instanceof Account && ! in_array((int) $current->getKey(), $visited, true)) {
            if ((int) $current->getKey() === (int) $ancestor->getKey()) {
                return true;
            }

            $visited[] = (int) $current->getKey();
            $current = $current->parent_id ? $allAccounts->get((int) $current->parent_id) : null;
        }

        return false;
    }

    /** @param Collection<int, Account> $allAccounts */
    private function path(?Account $account, Collection $allAccounts): ?string
    {
        if (! $account instanceof Account) {
            return null;
        }

        $segments = [];
        $visited = [];
        $current = $account;

        while ($current instanceof Account && ! in_array((int) $current->getKey(), $visited, true)) {
            $visited[] = (int) $current->getKey();
            array_unshift($segments, $current->codeNameLabel());
            $current = $current->parent_id ? $allAccounts->get((int) $current->parent_id) : null;
        }

        if ($current instanceof Account) {
            array_unshift($segments, '[cycle]');
        }

        return implode(' > ', $segments);
    }

    /** @return array<string, mixed> */
    private function activityProperties(Activity $activity): array
    {
        return $activity->properties instanceof Collection
            ? $activity->properties->all()
            : (is_array($activity->properties) ? $activity->properties : []);
    }

    private function positiveAccountId(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function normalizedName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    /** @return array<string, mixed> */
    private function emptyFinancialMetrics(): array
    {
        return [
            'journal_line_count' => 0,
            'journal_lines' => 0,
            'posted_journal_line_count' => 0,
            'non_posted_journal_line_count' => 0,
            'total_debit' => '0.0000',
            'debit_total' => '0.0000',
            'total_credit' => '0.0000',
            'credit_total' => '0.0000',
            'balance' => '0.0000',
            'posted_debit_total' => '0.0000',
            'posted_credit_total' => '0.0000',
            'posted_balance' => '0.0000',
        ];
    }
}
