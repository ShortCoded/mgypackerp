<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\Sales\Models\Customer;

class FixedAssetLifecycleService
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly DocumentNumberService $documents,
        private readonly FixedAssetBookValueService $bookValues,
        private readonly JournalEntryService $journals,
        private readonly NumericFormatService $numbers,
        private readonly CrudAuditService $audit,
        private readonly BusinessPartnerAccountService $accounts,
        private readonly FinancialPeriodService $financialPeriods,
        private readonly FixedAssetDepreciationCalculator $depreciationCalculator,
    ) {}

    public function activate(FixedAsset $asset, string $activationDate): FixedAsset
    {
        return DB::transaction(function () use ($asset, $activationDate): FixedAsset {
            $asset = $this->lockedAsset($asset);

            if ($asset->status !== FixedAsset::StatusDraft) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.only_draft_activation'));
            }

            $date = Carbon::parse($activationDate);
            $this->openPeriodForDate((int) $asset->company_id, $date);

            $serviceDate = $asset->operation_date ?: $asset->acquisition_date ?: $asset->purchase_date ?: $asset->asset_date;

            if ($serviceDate === null || $date->lt($serviceDate)) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.activation_before_service'));
            }

            if ($asset->is_depreciable) {
                $this->requiredMapping($asset);
            }

            $this->audit->saveUpdate($asset, [
                'status' => FixedAsset::StatusActive,
                'capitalized_at' => $date->endOfDay(),
                'capitalized_by' => auth()->id(),
                'locked_at' => now(),
            ]);

            return $asset->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function transfer(FixedAsset $asset, array $data): FixedAssetMovement
    {
        return DB::transaction(function () use ($asset, $data): FixedAssetMovement {
            $asset = $this->lockedAsset($asset);
            $this->assertOperational($asset);
            $movementDate = Carbon::parse($data['movement_date']);
            $this->openPeriodForDate((int) $asset->company_id, $movementDate);
            $this->assertNotBeforeLifecycleStart($asset, $movementDate, 'movement_before_asset');
            $lastDate = $asset->movements()->max('movement_date');

            if ($lastDate !== null && $movementDate->lt(Carbon::parse($lastDate))) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.movement_before_latest'));
            }

            $destinationBranch = $this->modelByDocNum(Branch::class, (int) $asset->company_id, $data['destination_branch_doc_num'] ?? null);
            $destinationCostCenter = $this->modelByDocNum(CostCenter::class, (int) $asset->company_id, $data['destination_cost_center_doc_num'] ?? null);
            $destinationHall = $this->branchHall((int) $asset->company_id, $destinationBranch?->getKey(), $data['destination_branch_hall_uuid'] ?? null);
            $destinationLocation = $this->nullableString($data['destination_location_address'] ?? null);

            if (! $destinationBranch instanceof Branch || $destinationBranch->status !== 'active') {
                throw new DomainException(__('fixed_assets.messages.branch_unavailable'));
            }

            if ($this->nullableString($data['destination_cost_center_doc_num'] ?? null) !== null
                && (! $destinationCostCenter instanceof CostCenter || $destinationCostCenter->status !== 'active' || $destinationCostCenter->is_group)
            ) {
                throw new DomainException(__('fixed_assets.messages.cost_center_unavailable'));
            }

            if ($this->nullableString($data['destination_branch_hall_uuid'] ?? null) !== null && ! $destinationHall instanceof BranchHall) {
                throw new DomainException(__('fixed_assets.messages.hall_branch_mismatch'));
            }
            $changes = [
                'branch_id' => $destinationBranch?->getKey(),
                'branch_hall_id' => $destinationHall?->getKey(),
                'cost_center_id' => $destinationCostCenter?->getKey(),
                'location_address' => $destinationLocation,
                'locked_at' => $asset->locked_at ?: now(),
            ];

            if ((int) $asset->branch_id === (int) $changes['branch_id']
                && (int) $asset->branch_hall_id === (int) $changes['branch_hall_id']
                && (int) $asset->cost_center_id === (int) $changes['cost_center_id']
                && (string) $asset->location_address === (string) $changes['location_address']
            ) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.movement_no_changes'));
            }

            $movement = FixedAssetMovement::query()->create([
                ...$this->documents->nextForCompany('fixed_asset_movements', FixedAssetMovement::class, (int) $asset->company_id),
                'company_id' => $asset->company_id,
                'fixed_asset_id' => $asset->getKey(),
                'movement_date' => $movementDate,
                'source_branch_id' => $asset->branch_id,
                'destination_branch_id' => $destinationBranch?->getKey(),
                'source_branch_hall_id' => $asset->branch_hall_id,
                'destination_branch_hall_id' => $destinationHall?->getKey(),
                'source_location_address' => $asset->location_address,
                'destination_location_address' => $destinationLocation,
                'source_cost_center_id' => $asset->cost_center_id,
                'destination_cost_center_id' => $destinationCostCenter?->getKey(),
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'status' => FixedAssetMovement::StatusPosted,
                'requested_by' => auth()->id(),
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'created_by' => auth()->id(),
            ]);

            $this->audit->saveUpdate($asset, $changes);

            return $movement->load(['asset', 'sourceBranch', 'destinationBranch', 'sourceBranchHall', 'destinationBranchHall', 'sourceCostCenter', 'destinationCostCenter']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispose(FixedAsset $asset, array $data): FixedAssetDisposal
    {
        return DB::transaction(function () use ($asset, $data): FixedAssetDisposal {
            $asset = $this->lockedAsset($asset);
            $this->assertOperational($asset);
            $date = Carbon::parse($data['disposal_date']);
            $period = $this->openPeriodForDate((int) $asset->company_id, $date);
            $this->assertNotBeforeLifecycleStart($asset, $date, 'disposal_before_asset');
            $latestMovementDate = $asset->movements()->max('movement_date');

            if ($latestMovementDate !== null && $date->lt(Carbon::parse($latestMovementDate))) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.disposal_before_latest_movement'));
            }

            $depreciationCutoff = $this->depreciationCalculator->disposalCutoffDate($date);
            if ($asset->postedDepreciations()->whereDate('period_end', '>', $depreciationCutoff->toDateString())->exists()) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.future_depreciation_exists'));
            }

            $mapping = $this->requiredMapping($asset);
            $position = $this->bookValues->position($asset, $date);
            $proceeds = $this->scale($data['proceeds'] ?? 0);
            $baseProceeds = bcmul($proceeds, $this->rate($asset->exchange_rate), 4);
            $gain = bccomp($proceeds, $position['net_book_value'], 4) > 0 ? bcsub($proceeds, $position['net_book_value'], 4) : '0.0000';
            $loss = bccomp($position['net_book_value'], $proceeds, 4) > 0 ? bcsub($position['net_book_value'], $proceeds, 4) : '0.0000';
            $baseGain = bccomp($baseProceeds, $position['base_net_book_value'], 4) > 0 ? bcsub($baseProceeds, $position['base_net_book_value'], 4) : '0.0000';
            $baseLoss = bccomp($position['base_net_book_value'], $baseProceeds, 4) > 0 ? bcsub($position['base_net_book_value'], $baseProceeds, 4) : '0.0000';
            $proceedsAccount = $this->modelByDocNum(Account::class, (int) $asset->company_id, $data['proceeds_account_doc_num'] ?? null);

            if (bccomp($proceeds, '0', 4) > 0 && (! $proceedsAccount instanceof Account || ! $this->postable($proceedsAccount))) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.proceeds_account_required'));
            }

            $customerDocNum = $this->nullableString($data['customer_doc_num'] ?? null);
            $customer = $this->modelByDocNum(Customer::class, (int) $asset->company_id, $customerDocNum);

            if ($customerDocNum !== null && (! $customer instanceof Customer || $customer->status !== 'active')) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.customer_unavailable'));
            }

            $disposal = FixedAssetDisposal::query()->create([
                ...$this->documents->nextForCompany('fixed_asset_disposals', FixedAssetDisposal::class, (int) $asset->company_id),
                'company_id' => $asset->company_id,
                'financial_period_id' => $period->getKey(),
                'fixed_asset_id' => $asset->getKey(),
                'asset_status_before' => $asset->status,
                'disposal_date' => $date,
                'disposition_type' => $data['disposition_type'],
                'reason' => $data['reason'],
                'customer_id' => $customer?->getKey(),
                'proceeds_account_id' => $proceedsAccount?->getKey(),
                'original_cost' => $position['acquisition_cost'],
                'base_original_cost' => $position['base_acquisition_cost'],
                'accumulated_depreciation' => $position['accumulated_depreciation'],
                'base_accumulated_depreciation' => $position['base_accumulated_depreciation'],
                'net_book_value' => $position['net_book_value'],
                'base_net_book_value' => $position['base_net_book_value'],
                'proceeds' => $proceeds,
                'base_proceeds' => $baseProceeds,
                'gain_amount' => $gain,
                'base_gain_amount' => $baseGain,
                'loss_amount' => $loss,
                'base_loss_amount' => $baseLoss,
                'status' => FixedAssetDisposal::StatusPosted,
                'notes' => $data['notes'] ?? null,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'created_by' => auth()->id(),
            ]);

            $journalLines = [];
            $dimensions = ['branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id];
            $description = __('fixed_assets.lifecycle.journal.disposal_line', ['asset' => $asset->doc_num]);

            if (bccomp($baseProceeds, '0', 4) > 0) {
                $journalLines[] = ['account_id' => $proceedsAccount->getKey(), 'debit_amount' => $baseProceeds, 'credit_amount' => 0, 'description' => $description, 'customer_id' => $customer?->getKey(), ...$dimensions];
            }

            if (bccomp($position['base_accumulated_depreciation'], '0', 4) > 0) {
                $journalLines[] = ['account_id' => $mapping->accumulated_depreciation_account_id, 'debit_amount' => $position['base_accumulated_depreciation'], 'credit_amount' => 0, 'description' => $description, ...$dimensions];
            }

            if (bccomp($baseLoss, '0', 4) > 0) {
                $journalLines[] = ['account_id' => $mapping->disposal_loss_account_id, 'debit_amount' => $baseLoss, 'credit_amount' => 0, 'description' => $description, ...$dimensions];
            }

            $journalLines[] = ['account_id' => $asset->account_id, 'debit_amount' => 0, 'credit_amount' => $position['base_acquisition_cost'], 'description' => $description, ...$dimensions];

            if (bccomp($baseGain, '0', 4) > 0) {
                $journalLines[] = ['account_id' => $mapping->disposal_gain_account_id, 'debit_amount' => 0, 'credit_amount' => $baseGain, 'description' => $description, ...$dimensions];
            }

            $mainCurrency = Currency::query()->forCompany((int) $asset->company_id)->where('is_main', true)->whereNull('deleted_at')->firstOrFail();
            $journal = $this->journals->createPostedFromSource([
                'entry_date' => $date,
                'company_id' => $asset->company_id,
                'financial_period_id' => $period->getKey(),
                'branch_id' => $asset->branch_id,
                'currency_id' => $mainCurrency->getKey(),
                'exchange_rate' => '1.000000',
                'description' => __('fixed_assets.lifecycle.journal.disposal', ['document' => $disposal->doc_num]),
                'notes' => $data['notes'] ?? null,
                'source_type' => 'fixed_asset_disposal',
                'source_id' => $disposal->getKey(),
                'source_doc_num' => $disposal->doc_num,
            ], $journalLines);

            $disposal->forceFill(['journal_entry_id' => $journal->getKey()])->save();
            $status = match ($data['disposition_type']) {
                FixedAssetDisposal::TypeSale => FixedAsset::StatusSold,
                FixedAssetDisposal::TypeWriteOff => FixedAsset::StatusWrittenOff,
                default => FixedAsset::StatusDisposed,
            };
            $this->audit->saveUpdate($asset, ['status' => $status, 'disposed_at' => $date, 'locked_at' => $asset->locked_at ?: now()]);

            return $disposal->refresh()->load(['asset', 'customer', 'proceedsAccount', 'journalEntry']);
        }, attempts: 3);
    }

    public function reverseDisposal(FixedAssetDisposal $disposal, string $reason): FixedAssetDisposal
    {
        return DB::transaction(function () use ($disposal, $reason): FixedAssetDisposal {
            $disposal = FixedAssetDisposal::query()
                ->where('company_id', $this->companies->requireCompanyId())
                ->whereKey($disposal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($disposal->status !== FixedAssetDisposal::StatusPosted) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.disposal_not_posted'));
            }

            $this->financialPeriods->resolveOpenForPostingDate(
                (int) $disposal->company_id,
                $disposal->disposal_date,
                expectedPeriodId: (int) $disposal->financial_period_id,
                lockForUpdate: true,
            );

            $disposal->loadMissing('journalEntry.lines');
            $reversal = $this->journals->createPostedReversalFromSource($disposal->journalEntry, [
                'entry_date' => $disposal->disposal_date,
                'company_id' => $disposal->company_id,
                'financial_period_id' => $disposal->financial_period_id,
                'currency_id' => $disposal->journalEntry->currency_id,
                'exchange_rate' => $disposal->journalEntry->exchange_rate,
                'description' => __('fixed_assets.lifecycle.journal.disposal_reversal', ['document' => $disposal->doc_num]),
                'notes' => $reason,
                'source_type' => 'fixed_asset_disposal_reversal',
                'source_id' => $disposal->getKey(),
                'source_doc_num' => $disposal->doc_num,
            ]);
            $asset = $this->lockedAsset($disposal->asset()->firstOrFail());
            $position = $this->bookValues->position($asset);
            $this->audit->saveUpdate($asset, [
                'status' => $disposal->asset_status_before
                    ?: (bccomp($position['remaining_depreciable_amount'], '0', 4) <= 0 ? FixedAsset::StatusFullyDepreciated : FixedAsset::StatusActive),
                'disposed_at' => null,
            ]);
            $disposal->forceFill([
                'status' => FixedAssetDisposal::StatusReversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ])->save();

            return $disposal->refresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function configureCategoryMapping(array $data): FixedAssetCategoryMapping
    {
        $companyId = $this->companies->requireCompanyId();
        $category = $this->requiredPostableOrGroupAccount($companyId, $data['asset_group_account_doc_num'], true);

        if (! $this->accounts->isSelectableGroup(BusinessPartnerAccountService::FixedAsset, $category)) {
            throw new DomainException(__('fixed_assets.messages.asset_category_unavailable'));
        }
        $values = [
            'company_id' => $companyId,
            'asset_group_account_id' => $category->getKey(),
            'accumulated_depreciation_account_id' => $this->requiredPostableOrGroupAccount($companyId, $data['accumulated_depreciation_account_doc_num'])->getKey(),
            'depreciation_expense_account_id' => $this->requiredPostableOrGroupAccount($companyId, $data['depreciation_expense_account_doc_num'])->getKey(),
            'disposal_gain_account_id' => $this->requiredPostableOrGroupAccount($companyId, $data['disposal_gain_account_doc_num'])->getKey(),
            'disposal_loss_account_id' => $this->requiredPostableOrGroupAccount($companyId, $data['disposal_loss_account_doc_num'])->getKey(),
            'updated_by' => auth()->id(),
        ];

        $mapping = FixedAssetCategoryMapping::query()->firstOrNew([
            'company_id' => $companyId,
            'asset_group_account_id' => $category->getKey(),
        ]);

        if (! $mapping->exists) {
            $mapping->created_by = auth()->id();
        }

        $mapping->fill($values)->save();

        return $mapping->refresh();
    }

    private function lockedAsset(FixedAsset $asset): FixedAsset
    {
        return FixedAsset::query()->forCompany($this->companies->requireCompanyId())->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertOperational(FixedAsset $asset): void
    {
        if (! in_array($asset->status, [FixedAsset::StatusActive, FixedAsset::StatusSuspended, FixedAsset::StatusFullyDepreciated], true) || $asset->isDisposed()) {
            throw new DomainException(__('fixed_assets.lifecycle.errors.asset_not_operational'));
        }
    }

    private function openPeriodForDate(int $companyId, Carbon $date): FinancialPeriod
    {
        return $this->financialPeriods->resolveOpenForPostingDate($companyId, $date, lockForUpdate: true);
    }

    private function requiredMapping(FixedAsset $asset): FixedAssetCategoryMapping
    {
        $categoryId = $asset->asset_group_account_id ?: $asset->account()->withTrashed()->value('parent_id');
        $mapping = FixedAssetCategoryMapping::query()->where('company_id', $asset->company_id)->where('asset_group_account_id', $categoryId)->first();

        if (! $mapping instanceof FixedAssetCategoryMapping) {
            throw new DomainException(__('fixed_assets.lifecycle.errors.account_mapping_required'));
        }

        foreach ([$mapping->accumulated_depreciation_account_id, $mapping->depreciation_expense_account_id, $mapping->disposal_gain_account_id, $mapping->disposal_loss_account_id] as $accountId) {
            $account = Account::query()->where('company_id', $asset->company_id)->whereKey($accountId)->whereNull('deleted_at')->first();

            if (! $account instanceof Account || ! $this->postable($account)) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.account_mapping_required'));
            }
        }

        return $mapping;
    }

    private function requiredPostableOrGroupAccount(int $companyId, string $docNum, bool $group = false): Account
    {
        $account = Account::query()->where('company_id', $companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->first();

        if (! $account instanceof Account || $account->status !== 'active' || ($group ? (! $account->is_group || $account->is_postable) : ! $this->postable($account))) {
            throw new DomainException(__('fixed_assets.lifecycle.errors.account_unavailable'));
        }

        return $account;
    }

    private function postable(Account $account): bool
    {
        return ! $account->is_group && $account->is_postable && $account->status === 'active' && ! $account->trashed();
    }

    /** @param class-string<Model> $model */
    private function modelByDocNum(string $model, int $companyId, mixed $docNum): ?Model
    {
        $docNum = trim((string) $docNum);

        return $docNum === '' ? null : $model::query()->where('company_id', $companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->first();
    }

    private function branchHall(int $companyId, mixed $branchId, mixed $uuid): ?BranchHall
    {
        $uuid = trim((string) $uuid);

        return ! $branchId || $uuid === '' ? null : BranchHall::query()
            ->where('branch_id', $branchId)
            ->where('public_uuid', $uuid)
            ->whereNull('deleted_at')
            ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))
            ->first();
    }

    private function scale(mixed $value): string
    {
        return $this->numbers->normalizeToScale($value, 4) ?? '0.0000';
    }

    private function rate(mixed $value): string
    {
        return $this->numbers->normalizeToScale($value, 6) ?? '1.000000';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertNotBeforeLifecycleStart(FixedAsset $asset, Carbon $date, string $error): void
    {
        $dates = collect([
            $asset->asset_date,
            $asset->purchase_date,
            $asset->acquisition_date,
            $asset->operation_date,
            $asset->capitalized_at,
        ])->filter()->map(fn ($value): Carbon => Carbon::parse($value)->startOfDay());
        $lifecycleStart = $dates->max();

        if ($lifecycleStart instanceof Carbon && $date->lt($lifecycleStart)) {
            throw new DomainException(__("fixed_assets.lifecycle.errors.{$error}"));
        }
    }
}
