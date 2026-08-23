<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDepreciation;
use Modules\FixedAssets\Models\FixedAssetDepreciationRun;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\FixedAssets\Models\FixedAssetMovement;

class FixedAssetDepreciationService
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly DocumentNumberService $documents,
        private readonly FixedAssetBookValueService $bookValues,
        private readonly FixedAssetDepreciationCalculator $calculator,
        private readonly JournalEntryService $journals,
        private readonly FinancialPeriodService $financialPeriods,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{financial_period: FinancialPeriod, period_start: Carbon, period_end: Carbon, posting_date: Carbon, eligible: list<array<string, mixed>>, excluded: list<array<string, mixed>>}
     */
    public function preview(array $filters): array
    {
        $companyId = $this->companies->requireCompanyId();
        [$financialPeriod, $periodStart, $periodEnd, $postingDate] = $this->period($companyId, $filters);
        $query = FixedAsset::query()
            ->forCompany($companyId)
            ->with(['account', 'assetGroupAccount', 'branch', 'branchHall', 'costCenter', 'currency', 'movements.sourceCostCenter', 'postedDepreciations']);

        $this->applyFilters($query, $filters, $companyId);

        $eligible = [];
        $excluded = [];

        foreach ($query->orderBy('doc_number')->get() as $asset) {
            if (! $this->matchesDimensionFilters($asset, $periodEnd, $filters, $companyId)) {
                continue;
            }

            $result = $this->previewAsset($asset, $financialPeriod, $periodStart, $periodEnd, $filters);

            if ($result['eligible']) {
                $eligible[] = $result;
            } else {
                $excluded[] = $result;
            }
        }

        return compact('financialPeriod', 'periodStart', 'periodEnd', 'postingDate', 'eligible', 'excluded');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function post(array $filters): FixedAssetDepreciationRun
    {
        return DB::transaction(function () use ($filters): FixedAssetDepreciationRun {
            $companyId = $this->companies->requireCompanyId();
            [$financialPeriod, $periodStart, $periodEnd, $postingDate] = $this->period($companyId, $filters, true);
            $assetIds = FixedAsset::query()->forCompany($companyId)->whereIn('doc_num', $filters['asset_doc_nums'] ?? [])->pluck('id');

            if ($assetIds->isEmpty()) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.select_assets'));
            }

            $assets = FixedAsset::query()
                ->forCompany($companyId)
                ->whereKey($assetIds)
                ->with(['account', 'assetGroupAccount', 'branch', 'branchHall', 'costCenter', 'currency', 'movements.sourceCostCenter', 'postedDepreciations'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $prepared = [];

            foreach ($assets as $asset) {
                if (! $this->matchesDimensionFilters($asset, $periodEnd, $filters, $companyId)) {
                    throw new DomainException(__('fixed_assets.lifecycle.errors.asset_ineligible', [
                        'asset' => $asset->doc_num,
                        'reason' => __('fixed_assets.lifecycle.exclusions.dimension_filter'),
                    ]));
                }

                $result = $this->previewAsset($asset, $financialPeriod, $periodStart, $periodEnd, [...$filters, '_posting' => true]);

                if (! $result['eligible']) {
                    throw new DomainException(__('fixed_assets.lifecycle.errors.asset_ineligible', [
                        'asset' => $asset->doc_num,
                        'reason' => $result['reason'],
                    ]));
                }

                $prepared[] = $result;
            }

            $document = $this->documents->nextForCompany('fixed_asset_depreciation_runs', FixedAssetDepreciationRun::class, $companyId);
            $run = FixedAssetDepreciationRun::query()->create([
                ...$document,
                'company_id' => $companyId,
                'financial_period_id' => $financialPeriod->getKey(),
                'branch_id' => $this->branchId($companyId, $filters['branch_doc_num'] ?? null),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'posting_date' => $postingDate,
                'filters' => $this->publicFilters($filters),
                'total_depreciation' => $this->sum($prepared, 'period_depreciation'),
                'base_total_depreciation' => $this->sum($prepared, 'base_period_depreciation'),
                'status' => FixedAssetDepreciationRun::StatusPosted,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
                'created_by' => auth()->id(),
            ]);

            $mainCurrency = Currency::query()->forCompany($companyId)->where('is_main', true)->whereNull('deleted_at')->firstOrFail();
            $journalLines = [];

            foreach ($prepared as $row) {
                /** @var FixedAsset $asset */
                $asset = $row['asset'];
                /** @var FixedAssetCategoryMapping $mapping */
                $mapping = $row['mapping'];
                $amount = $row['base_period_depreciation'];
                $description = __('fixed_assets.lifecycle.journal.depreciation_line', ['asset' => $asset->doc_num, 'period' => $periodEnd->format('Y-m')]);
                $dimensions = ['cost_center_id' => $row['effective_cost_center_id'], 'branch_id' => $row['effective_branch_id']];
                $journalLines[] = ['account_id' => $mapping->depreciation_expense_account_id, 'debit_amount' => $amount, 'credit_amount' => 0, 'description' => $description, ...$dimensions];
                $journalLines[] = ['account_id' => $mapping->accumulated_depreciation_account_id, 'debit_amount' => 0, 'credit_amount' => $amount, 'description' => $description, ...$dimensions];
            }

            $journal = $this->journals->createPostedFromSource([
                'entry_date' => $postingDate,
                'company_id' => $companyId,
                'financial_period_id' => $financialPeriod->getKey(),
                'branch_id' => $run->branch_id,
                'currency_id' => $mainCurrency->getKey(),
                'exchange_rate' => '1.000000',
                'description' => __('fixed_assets.lifecycle.journal.depreciation_run', ['document' => $run->doc_num]),
                'notes' => null,
                'source_type' => 'fixed_asset_depreciation_run',
                'source_id' => $run->getKey(),
                'source_doc_num' => $run->doc_num,
            ], $journalLines);

            foreach ($prepared as $row) {
                /** @var FixedAsset $asset */
                $asset = $row['asset'];
                FixedAssetDepreciation::query()->create([
                    'depreciation_run_id' => $run->getKey(),
                    'fixed_asset_id' => $asset->getKey(),
                    'company_id' => $companyId,
                    'financial_period_id' => $financialPeriod->getKey(),
                    'period_start' => $row['period_start'],
                    'period_end' => $row['period_end'],
                    'acquisition_cost' => $row['acquisition_cost'],
                    'base_acquisition_cost' => $row['base_acquisition_cost'],
                    'depreciation_base' => $row['depreciation_base'],
                    'base_depreciation_base' => $row['base_depreciation_base'],
                    'period_depreciation' => $row['period_depreciation'],
                    'base_period_depreciation' => $row['base_period_depreciation'],
                    'usage_units' => $row['usage_units'],
                    'accumulated_before' => $row['accumulated_before'],
                    'base_accumulated_before' => $row['base_accumulated_before'],
                    'accumulated_after' => $row['accumulated_after'],
                    'base_accumulated_after' => $row['base_accumulated_after'],
                    'closing_net_book_value' => $row['closing_net_book_value'],
                    'base_closing_net_book_value' => $row['base_closing_net_book_value'],
                    'branch_id' => $row['effective_branch_id'],
                    'cost_center_id' => $row['effective_cost_center_id'],
                    'journal_entry_id' => $journal->getKey(),
                    'status' => FixedAssetDepreciation::StatusPosted,
                    'posted_at' => now(),
                    'posted_by' => auth()->id(),
                ]);

                $asset->forceFill([
                    'net_value' => $row['closing_net_book_value'],
                    'status' => bccomp($row['closing_net_book_value'], $row['residual_value'], 4) <= 0 ? FixedAsset::StatusFullyDepreciated : FixedAsset::StatusActive,
                    'locked_at' => $asset->locked_at ?: now(),
                ])->save();
            }

            $run->forceFill(['journal_entry_id' => $journal->getKey()])->save();

            return $run->load(['lines.asset', 'journalEntry']);
        }, attempts: 3);
    }

    public function reverse(FixedAssetDepreciationRun $run, string $reason): FixedAssetDepreciationRun
    {
        return DB::transaction(function () use ($run, $reason): FixedAssetDepreciationRun {
            $lockedRun = FixedAssetDepreciationRun::query()
                ->where('company_id', $this->companies->requireCompanyId())
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRun->status !== FixedAssetDepreciationRun::StatusPosted) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.run_not_posted'));
            }

            $this->financialPeriods->resolveOpenForPostingDate(
                (int) $lockedRun->company_id,
                $lockedRun->posting_date,
                expectedPeriodId: (int) $lockedRun->financial_period_id,
                lockForUpdate: true,
            );

            $lockedRun->loadMissing(['journalEntry.lines', 'lines']);
            $assetIds = $lockedRun->lines->pluck('fixed_asset_id')->all();

            if (FixedAssetDepreciation::query()
                ->where('company_id', $lockedRun->company_id)
                ->whereIn('fixed_asset_id', $assetIds)
                ->where('status', FixedAssetDepreciation::StatusPosted)
                ->whereDate('period_end', '>', $lockedRun->period_end->toDateString())
                ->exists()
            ) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.later_depreciation_exists'));
            }

            if (FixedAssetDisposal::query()
                ->where('company_id', $lockedRun->company_id)
                ->whereIn('fixed_asset_id', $assetIds)
                ->where('status', FixedAssetDisposal::StatusPosted)
                ->exists()
            ) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.depreciation_reversal_after_disposal'));
            }

            $reversal = $this->journals->createPostedReversalFromSource($lockedRun->journalEntry, [
                'entry_date' => $lockedRun->posting_date,
                'company_id' => $lockedRun->company_id,
                'financial_period_id' => $lockedRun->financial_period_id,
                'branch_id' => $lockedRun->branch_id,
                'currency_id' => $lockedRun->journalEntry->currency_id,
                'exchange_rate' => $lockedRun->journalEntry->exchange_rate,
                'description' => __('fixed_assets.lifecycle.journal.depreciation_reversal', ['document' => $lockedRun->doc_num]),
                'notes' => $reason,
                'source_type' => 'fixed_asset_depreciation_reversal',
                'source_id' => $lockedRun->getKey(),
                'source_doc_num' => $lockedRun->doc_num,
            ]);

            FixedAsset::query()->forCompany((int) $lockedRun->company_id)->whereKey($assetIds)->orderBy('id')->lockForUpdate()->get();
            $lockedRun->lines()->update(['status' => FixedAssetDepreciation::StatusReversed, 'reversed_at' => now(), 'reversed_by' => auth()->id()]);

            foreach (FixedAsset::query()->forCompany((int) $lockedRun->company_id)->whereKey($assetIds)->with('postedDepreciations')->get() as $asset) {
                $position = $this->bookValues->position($asset);
                $asset->forceFill([
                    'net_value' => $position['net_book_value'],
                    'status' => bccomp($position['remaining_depreciable_amount'], '0', 4) <= 0 ? FixedAsset::StatusFullyDepreciated : FixedAsset::StatusActive,
                ])->save();
            }

            $lockedRun->forceFill([
                'status' => FixedAssetDepreciationRun::StatusReversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ])->save();

            return $lockedRun->refresh()->load(['lines.asset', 'journalEntry', 'reversalJournalEntry']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: FinancialPeriod, 1: Carbon, 2: Carbon, 3: Carbon}
     */
    private function period(int $companyId, array $filters, bool $lockForUpdate = false): array
    {
        $postingDate = Carbon::parse($filters['posting_date'] ?? now()->toDateString())->startOfDay();
        $period = $this->financialPeriods->resolveOpenForPostingDate(
            $companyId,
            $postingDate,
            expectedPeriodDocNum: (string) ($filters['financial_period_doc_num'] ?? ''),
            lockForUpdate: $lockForUpdate,
        );

        $periodStart = $postingDate->copy()->startOfMonth()->max($period->from_date->copy());
        $periodEnd = $postingDate->copy()->endOfMonth()->min($period->to_date->copy());

        return [$period, $periodStart, $periodEnd, $postingDate];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function previewAsset(FixedAsset $asset, FinancialPeriod $period, Carbon $periodStart, Carbon $periodEnd, array $filters): array
    {
        $excluded = fn (string $reason): array => ['eligible' => false, 'asset' => $asset, 'reason' => $reason];

        if (! $asset->is_depreciable) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.non_depreciable'));
        }

        if (! in_array($asset->status, [FixedAsset::StatusActive, FixedAsset::StatusFullyDepreciated], true)) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.status'));
        }

        if ($asset->isDisposed() || ($asset->disposed_at && $asset->disposed_at->lte($periodEnd))) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.disposed'));
        }

        if ($asset->depreciation_start_date === null) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.missing_service_date'));
        }

        if ($asset->depreciation_start_date->gt($periodEnd)) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.not_in_service'));
        }

        $asset->loadMissing('postedDepreciations');
        $coveredThrough = collect([
            $asset->previous_depreciation_until_date,
            $asset->postedDepreciations->last()?->period_end,
        ])->filter()->map(fn ($date): Carbon => Carbon::parse($date)->startOfDay())->max();

        if ($coveredThrough instanceof Carbon && $coveredThrough->gte($periodEnd)) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.already_posted'));
        }

        $mapping = $this->mapping($asset);

        if (! $mapping instanceof FixedAssetCategoryMapping || ! $this->mappingAccountsPostable($mapping)) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.account_mapping'));
        }

        $position = $this->bookValues->position($asset);

        if (bccomp($position['remaining_depreciable_amount'], '0', 4) <= 0) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.fully_depreciated'));
        }

        $calculationStart = $coveredThrough instanceof Carbon
            ? $periodStart->copy()->max($coveredThrough->copy()->addDay())
            : $periodStart;
        $usageUnits = data_get($filters, 'usage_units.'.$asset->doc_num);
        $snapshot = $this->calculator->snapshot($asset, $calculationStart, $periodEnd, $position, $usageUnits === null ? null : (string) $usageUnits);
        $effectiveDimensions = $this->accountingDimensionsAsOf($asset, $periodEnd);

        if ($snapshot === null) {
            if ($asset->depreciation_method === FixedAsset::DepreciationMethodUnitsOfProduction && ! ($filters['_posting'] ?? false)) {
                return [
                    'eligible' => true,
                    'asset' => $asset,
                    'mapping' => $mapping,
                    'financial_period' => $period,
                    ...$position,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'period_depreciation' => '0.0000',
                    'base_period_depreciation' => '0.0000',
                    'usage_units' => null,
                    'accumulated_before' => $position['accumulated_depreciation'],
                    'base_accumulated_before' => $position['base_accumulated_depreciation'],
                    'accumulated_after' => $position['accumulated_depreciation'],
                    'base_accumulated_after' => $position['base_accumulated_depreciation'],
                    'closing_net_book_value' => $position['net_book_value'],
                    'base_closing_net_book_value' => $position['base_net_book_value'],
                    'requires_usage_units' => true,
                    ...$effectiveDimensions,
                ];
            }

            $reason = $asset->depreciation_method === FixedAsset::DepreciationMethodUnitsOfProduction
                ? __('fixed_assets.lifecycle.exclusions.usage_units_required')
                : __('fixed_assets.lifecycle.exclusions.invalid_configuration');

            return $excluded($reason);
        }

        $overlapsPostedRange = $asset->postedDepreciations->contains(
            fn (FixedAssetDepreciation $depreciation): bool => $depreciation->period_start->lte(Carbon::parse($snapshot['period_end']))
                && $depreciation->period_end->gte(Carbon::parse($snapshot['period_start'])),
        );

        if ($overlapsPostedRange) {
            return $excluded(__('fixed_assets.lifecycle.exclusions.overlapping_period'));
        }

        return ['eligible' => true, 'asset' => $asset, 'mapping' => $mapping, 'financial_period' => $period, ...$snapshot, ...$effectiveDimensions];
    }

    /**
     * @return array{effective_branch_id: int|null, effective_cost_center_id: int|null, effective_cost_center: CostCenter|null}
     */
    private function accountingDimensionsAsOf(FixedAsset $asset, Carbon $periodEnd): array
    {
        $asset->loadMissing(['movements.sourceCostCenter', 'costCenter']);
        $futureMovement = $asset->movements
            ->filter(fn (FixedAssetMovement $movement): bool => $movement->status === FixedAssetMovement::StatusPosted && $movement->movement_date->gt($periodEnd))
            ->sortBy(fn (FixedAssetMovement $movement): string => $movement->movement_date->format('Ymd').str_pad((string) $movement->getKey(), 20, '0', STR_PAD_LEFT))
            ->first();

        if ($futureMovement instanceof FixedAssetMovement) {
            return [
                'effective_branch_id' => $futureMovement->source_branch_id,
                'effective_cost_center_id' => $futureMovement->source_cost_center_id,
                'effective_cost_center' => $futureMovement->sourceCostCenter,
            ];
        }

        return [
            'effective_branch_id' => $asset->branch_id,
            'effective_cost_center_id' => $asset->cost_center_id,
            'effective_cost_center' => $asset->costCenter,
        ];
    }

    private function mapping(FixedAsset $asset): ?FixedAssetCategoryMapping
    {
        $categoryId = $asset->asset_group_account_id ?: $asset->account?->parent_id;

        return $categoryId
            ? FixedAssetCategoryMapping::query()->where('company_id', $asset->company_id)->where('asset_group_account_id', $categoryId)->first()
            : null;
    }

    private function mappingAccountsPostable(FixedAssetCategoryMapping $mapping): bool
    {
        $accountIds = [
            $mapping->accumulated_depreciation_account_id,
            $mapping->depreciation_expense_account_id,
            $mapping->disposal_gain_account_id,
            $mapping->disposal_loss_account_id,
        ];

        return Account::query()
            ->where('company_id', $mapping->company_id)
            ->whereKey($accountIds)
            ->where('is_postable', true)
            ->where('is_group', false)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->count() === count(array_unique($accountIds));
    }

    /** @param Builder<FixedAsset> $query */
    private function applyFilters(Builder $query, array $filters, int $companyId): void
    {
        $query
            ->when($filters['asset_doc_nums'] ?? null, fn (Builder $query, array $docNums): Builder => $query->whereIn('doc_num', $docNums))
            ->when($this->accountId($companyId, $filters['asset_group_account_doc_num'] ?? null), fn (Builder $query, int $id): Builder => $query->where('asset_group_account_id', $id));
    }

    private function matchesDimensionFilters(FixedAsset $asset, Carbon $periodEnd, array $filters, int $companyId): bool
    {
        $branchId = $this->branchId($companyId, $filters['branch_doc_num'] ?? null);
        $costCenterId = $this->costCenterId($companyId, $filters['cost_center_doc_num'] ?? null);

        if ($branchId === null && $costCenterId === null) {
            return true;
        }

        $dimensions = $this->accountingDimensionsAsOf($asset, $periodEnd);

        return ($branchId === null || $branchId === (int) $dimensions['effective_branch_id'])
            && ($costCenterId === null || $costCenterId === (int) $dimensions['effective_cost_center_id']);
    }

    private function branchId(int $companyId, mixed $docNum): ?int
    {
        return $this->lookupId(Branch::class, $companyId, $docNum);
    }

    private function accountId(int $companyId, mixed $docNum): ?int
    {
        return $this->lookupId(Account::class, $companyId, $docNum);
    }

    private function costCenterId(int $companyId, mixed $docNum): ?int
    {
        return $this->lookupId(CostCenter::class, $companyId, $docNum);
    }

    /** @param class-string<Model> $model */
    private function lookupId(string $model, int $companyId, mixed $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        return $docNum === '' ? null : $model::query()->where('company_id', $companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->value('id');
    }

    /** @param list<array<string, mixed>> $rows */
    private function sum(array $rows, string $field): string
    {
        return array_reduce($rows, fn (string $total, array $row): string => bcadd($total, (string) $row[$field], 4), '0.0000');
    }

    /** @return array<string, mixed> */
    private function publicFilters(array $filters): array
    {
        return array_intersect_key($filters, array_flip(['financial_period_doc_num', 'posting_date', 'branch_doc_num', 'asset_group_account_doc_num', 'cost_center_doc_num', 'asset_doc_nums', 'usage_units']));
    }
}
