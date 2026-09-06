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
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\HR\Models\HrEmployee;
use Modules\Sales\Models\Customer;
use Modules\Sales\Services\CustomerInvoiceService;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        private readonly CustomerInvoiceService $customerInvoices,
    ) {}

    public function activate(FixedAsset $asset, string $activationDate, ?string $existingJournalDocNum = null): FixedAsset
    {
        return app(FixedAssetCostMovementService::class)->recognize($asset, $activationDate, $existingJournalDocNum);
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
            $movementPeriod = $this->openPeriodForDate((int) $asset->company_id, $movementDate);
            $this->assertNotBeforeLifecycleStart($asset, $movementDate, 'movement_before_asset');
            $lastDate = $asset->movements()->where('status', 'posted')->max('movement_date');
            if ($asset->postedDepreciations()->whereDate('period_end', '>=', $movementDate)->exists()) {
                throw new DomainException(__('fixed_assets.cycle.later_movements'));
            }

            if ($lastDate !== null && $movementDate->lt(Carbon::parse($lastDate))) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.movement_before_latest'));
            }

            $destinationBranch = $this->modelByDocNum(Branch::class, (int) $asset->company_id, $data['destination_branch_doc_num'] ?? null);
            app(FixedAssetAccessService::class)->assertBranch((int) $destinationBranch?->getKey());
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
                'financial_period_id' => $movementPeriod->getKey(),
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

            app(ActivityLogger::class)->log(request(), 'fixed_assets', 'transfer', 'success', ['subject' => $asset, 'properties_only' => true, 'properties' => ['document' => $movement->doc_num]]);

            return $movement->load(['asset', 'sourceBranch', 'destinationBranch', 'sourceBranchHall', 'destinationBranchHall', 'sourceCostCenter', 'destinationCostCenter']);
        }, attempts: 3);
    }

    public function custody(FixedAsset $asset, array $data): FixedAssetMovement
    {
        return DB::transaction(function () use ($asset, $data): FixedAssetMovement {
            $asset = $this->lockedAsset($asset);
            $this->assertOperational($asset);
            $date = Carbon::parse($data['movement_date']);
            $period = $this->openPeriodForDate((int) $asset->company_id, $date);
            $this->assertNotBeforeLifecycleStart($asset, $date, 'movement_before_asset');
            if ($asset->movements()->where('status', 'posted')->whereDate('movement_date', '>', $date)->exists()) {
                throw new DomainException(__('fixed_assets.cycle.later_movements'));
            }
            $previous = $asset->movements()->where('movement_type', FixedAssetMovement::TypeCustody)->where('status', 'posted')->first();
            $employee = empty($data['custodian_doc_num']) ? null : HrEmployee::query()->where('company_id', $asset->company_id)->where('doc_num', $data['custodian_doc_num'])->where('status', 'active')->first();
            if (! empty($data['custodian_doc_num']) && (! $employee || ! in_array((int) $employee->branch_id, app(FixedAssetAccessService::class)->branchIds(), true))) {
                throw new DomainException(__('fixed_assets.cycle.custodian_unavailable'));
            }
            if ((int) $previous?->destination_custodian_id === (int) $employee?->getKey()) {
                throw new DomainException(__('fixed_assets.cycle.custody_no_change'));
            }
            $movement = $asset->movements()->create([
                ...$this->documents->nextForCompany('fixed_asset_movements', FixedAssetMovement::class, (int) $asset->company_id),
                'company_id' => $asset->company_id, 'financial_period_id' => $period->getKey(),
                'movement_type' => FixedAssetMovement::TypeCustody, 'movement_date' => $date,
                'source_custodian_id' => $previous?->destination_custodian_id, 'destination_custodian_id' => $employee?->getKey(),
                'source_branch_id' => $asset->branch_id, 'destination_branch_id' => $asset->branch_id,
                'source_cost_center_id' => $asset->cost_center_id, 'destination_cost_center_id' => $asset->cost_center_id,
                'reason' => $data['reason'], 'notes' => $data['notes'] ?? null, 'status' => 'posted',
                'requested_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now(),
                'approved_by' => auth()->id(), 'approved_at' => now(), 'created_by' => auth()->id(),
            ]);
            app(ActivityLogger::class)->log(request(), 'fixed_assets', 'custody', 'success', ['subject' => $asset, 'properties' => ['document' => $movement->doc_num]]);

            return $movement;
        }, attempts: 3);
    }

    public function previewDisposal(FixedAsset $asset, array $data): array
    {
        app(FixedAssetAccessService::class)->assertAsset($asset);
        if (! $asset->hasPostedRecognition()) {
            throw new DomainException(__('fixed_assets.cycle.recognition_required'));
        }
        $date = Carbon::parse($data['disposal_date']);
        $cutoff = $this->depreciationCalculator->disposalCutoffDate($date);
        $position = $this->bookValues->position($asset, $date);
        $depreciation = app(FixedAssetDepreciationService::class);
        $next = $depreciation->nextUnpostedDate($asset);
        if ($depreciation->hasHistoricalGap($asset)) {
            throw new DomainException(__('fixed_assets.cycle.historical_depreciation_gap', ['period' => $next->format('Y-m')]));
        }
        $covered = $next?->copy()->subDay()->toDateString();
        $required = '0.0000';
        $requiredBase = '0.0000';
        $simulation = $next ? $this->bookValues->position($asset, $next) : $position;
        if ($asset->is_depreciable && $next) {
            while ($next->lte($cutoff) && bccomp($simulation['remaining_depreciable_amount'], '0', 4) > 0) {
                $end = $next->copy()->endOfMonth()->min($cutoff);
                $row = $this->depreciationCalculator->snapshot($asset, $next, $end, $simulation);
                if (! $row) {
                    break;
                }
                $required = bcadd($required, $row['period_depreciation'], 4);
                $requiredBase = bcadd($requiredBase, $row['base_period_depreciation'], 4);
                $simulation = [...$row, 'accumulated_depreciation' => $row['accumulated_after'], 'base_accumulated_depreciation' => $row['base_accumulated_after'], 'net_book_value' => $row['closing_net_book_value'], 'base_net_book_value' => $row['base_closing_net_book_value'], 'remaining_depreciable_amount' => bcsub($row['closing_net_book_value'], $row['residual_value'], 4), 'base_remaining_depreciable_amount' => bcsub($row['base_closing_net_book_value'], $row['base_residual_value'], 4)];
                $next = $end->copy()->addDay();
            }
        }
        $hasGap = $asset->is_depreciable && bccomp($position['remaining_depreciable_amount'], '0', 4) > 0
            && ($covered ? Carbon::parse($covered)->lt($cutoff) : ($asset->depreciation_start_date && $asset->depreciation_start_date->lte($cutoff)));
        $proceeds = $this->scale($data['proceeds'] ?? 0);
        $expenses = $this->scale($data['disposal_expenses'] ?? 0);
        $net = bcsub($proceeds, $expenses, 4);
        $nbv = bcsub($position['net_book_value'], $required, 4);
        $gainLoss = bcsub($net, $nbv, 4);
        $proceedsAccount = $this->modelByDocNum(Account::class, (int) $asset->company_id, $data['proceeds_account_doc_num'] ?? null);
        $expensesAccount = $this->modelByDocNum(Account::class, (int) $asset->company_id, $data['expenses_account_doc_num'] ?? null);
        $invoicePath = ($data['settlement_path'] ?? FixedAssetDisposal::SettlementDirect) === FixedAssetDisposal::SettlementCustomerInvoice;
        $journalPreview = [];
        $rate = $this->rate($asset->exchange_rate);
        $baseNBV = bcsub($position['base_net_book_value'], $requiredBase, 4);
        $baseProceeds = bcmul($proceeds, $rate, 4);
        $baseExpenses = bcmul($expenses, $rate, 4);
        $baseGainLoss = bcsub(bcsub($baseProceeds, $baseExpenses, 4), $baseNBV, 4);
        $mapping = $this->disposalMapping($asset, bcadd($position['base_accumulated_depreciation'], $requiredBase, 4), $baseGainLoss, $invoicePath);
        $append = function (?Account $account, string $debit, string $credit) use (&$journalPreview): void {
            if (bccomp(bcadd($debit, $credit, 4), '0', 4) > 0) {
                $journalPreview[] = ['account' => $account?->codeNameLabel() ?: __('fixed_assets.lifecycle.errors.account_unavailable'), 'debit' => $debit, 'credit' => $credit, 'debit_display' => $this->numbers->format($debit), 'credit_display' => $this->numbers->format($credit)];
            }
        };
        $append($mapping->accumulatedDepreciationAccount, bcadd($position['base_accumulated_depreciation'], $requiredBase, 4), '0');
        $append($asset->account, '0', $position['base_acquisition_cost']);
        if ($invoicePath) {
            $append($mapping->disposalClearingAccount, $baseNBV, '0');
            $append($mapping->disposalClearingAccount, bccomp($baseGainLoss, '0', 4) > 0 ? $baseGainLoss : '0', bccomp($baseGainLoss, '0', 4) < 0 ? bcmul($baseGainLoss, '-1', 4) : '0');
            $append($mapping->disposalClearingAccount, $baseExpenses, '0');
            $customer = $this->modelByDocNum(Customer::class, (int) $asset->company_id, $data['customer_doc_num'] ?? null);
            $tax = bcdiv(bcmul($baseProceeds, $this->scale($data['tax_rate'] ?? '0'), 8), '100', 4);
            $taxAccount = Account::query()->where('company_id', $asset->company_id)->where('status', 'active')->where('is_postable', true)->whereHas('classification', fn ($query) => $query->where('code', 'tax_payable'))->orderBy('account_code')->first();
            $append($customer?->account, bcadd($baseProceeds, $tax, 4), '0');
            $append($mapping->disposalClearingAccount, '0', $baseProceeds);
            $append($taxAccount, '0', $tax);
        } else {
            $append($proceedsAccount, $baseProceeds, '0');
        }
        $append($expensesAccount, '0', $baseExpenses);
        $append($mapping->disposalGainAccount, '0', bccomp($baseGainLoss, '0', 4) > 0 ? $baseGainLoss : '0');
        $append($mapping->disposalLossAccount, bccomp($baseGainLoss, '0', 4) < 0 ? bcmul($baseGainLoss, '-1', 4) : '0', '0');
        $display = collect(['cost' => $position['acquisition_cost'], 'accumulated' => $position['accumulated_depreciation'], 'cutoff' => $cutoff->toDateString(), 'depreciation_required' => $required, 'nbv' => $nbv, 'proceeds' => $proceeds, 'disposal_expenses' => $expenses, 'net_proceeds' => $net, 'gain_loss' => $gainLoss])
            ->map(fn (string $value, string $key): array => ['label' => __('fixed_assets.cycle.'.$key), 'value' => $key === 'cutoff' ? app(DateFormatService::class)->formatDate($value, '') : $this->numbers->format($value)])->values()->all();
        $display[] = ['label' => __('fixed_assets.cycle.journal_currency'), 'value' => (string) Currency::query()->forCompany((int) $asset->company_id)->where('is_main', true)->value('code')];
        if ($invoicePath) {
            $display[] = ['label' => __('fixed_assets.cycle.invoice_settlement'), 'value' => $proceeds];
        }

        return ['display' => $display, 'journal_preview' => $journalPreview, 'gap_message' => __('fixed_assets.cycle.disposal_gap', ['date' => $cutoff->toDateString()]), 'position' => $position, 'cutoff' => $cutoff->toDateString(), 'depreciation_required' => $required,
            'has_gap' => $hasGap, 'proceeds' => $proceeds, 'disposal_expenses' => $expenses,
            'net_proceeds' => $net, 'net_book_value' => $nbv, 'gain_loss' => $gainLoss,
            'accumulated_depreciation' => bcadd($position['accumulated_depreciation'], $required, 4)];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispose(FixedAsset $asset, array $data): FixedAssetDisposal
    {
        return DB::transaction(function () use ($asset, $data): FixedAssetDisposal {
            $asset = $this->lockedAsset($asset);
            $this->assertOperational($asset);
            $preview = $this->previewDisposal($asset, $data);
            if ($preview['has_gap']) {
                throw new DomainException(__('fixed_assets.cycle.disposal_gap', ['date' => $preview['cutoff']]));
            }
            $date = Carbon::parse($data['disposal_date']);
            $period = $this->openPeriodForDate((int) $asset->company_id, $date);
            $this->assertNotBeforeLifecycleStart($asset, $date, 'disposal_before_asset');
            $latestMovementDate = $asset->movements()->where('status', 'posted')->max('movement_date');

            if ($latestMovementDate !== null && $date->lt(Carbon::parse($latestMovementDate))) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.disposal_before_latest_movement'));
            }

            $depreciationCutoff = $this->depreciationCalculator->disposalCutoffDate($date);
            if ($asset->postedDepreciations()->whereDate('period_end', '>', $depreciationCutoff->toDateString())->exists()) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.future_depreciation_exists'));
            }

            $position = $this->bookValues->position($asset, $date);
            $proceeds = $this->scale($data['proceeds'] ?? 0);
            $settlementPath = (string) ($data['settlement_path'] ?? FixedAssetDisposal::SettlementDirect);
            $taxRate = $this->scale($data['tax_rate'] ?? 0);
            $taxAmount = bcdiv(bcmul($proceeds, $taxRate, 8), '100', 4);
            $grossProceeds = bcadd($proceeds, $taxAmount, 4);
            $baseProceeds = bcmul($proceeds, $this->rate($asset->exchange_rate), 4);
            $expenses = $this->scale($data['disposal_expenses'] ?? 0);
            $netProceeds = bcsub($proceeds, $expenses, 4);
            $baseNetProceeds = bcmul($netProceeds, $this->rate($asset->exchange_rate), 4);
            $baseExpenses = bcmul($expenses, $this->rate($asset->exchange_rate), 4);
            $gain = bccomp($netProceeds, $position['net_book_value'], 4) > 0 ? bcsub($netProceeds, $position['net_book_value'], 4) : '0.0000';
            $loss = bccomp($position['net_book_value'], $netProceeds, 4) > 0 ? bcsub($position['net_book_value'], $netProceeds, 4) : '0.0000';
            $baseGain = bccomp($baseNetProceeds, $position['base_net_book_value'], 4) > 0 ? bcsub($baseNetProceeds, $position['base_net_book_value'], 4) : '0.0000';
            $baseLoss = bccomp($position['base_net_book_value'], $baseNetProceeds, 4) > 0 ? bcsub($position['base_net_book_value'], $baseNetProceeds, 4) : '0.0000';
            $mapping = $this->disposalMapping($asset, $position['base_accumulated_depreciation'], bcsub($baseGain, $baseLoss, 4), $settlementPath === FixedAssetDisposal::SettlementCustomerInvoice);
            $proceedsAccount = $this->modelByDocNum(Account::class, (int) $asset->company_id, $data['proceeds_account_doc_num'] ?? null);

            if ($settlementPath === FixedAssetDisposal::SettlementDirect && bccomp($proceeds, '0', 4) > 0 && (! $proceedsAccount instanceof Account || ! $this->postable($proceedsAccount))) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.proceeds_account_required'));
            }

            $expensesAccount = $this->modelByDocNum(Account::class, (int) $asset->company_id, $data['expenses_account_doc_num'] ?? null);
            if (bccomp($expenses, '0', 4) < 0 || (bccomp($expenses, '0', 4) > 0 && (! $expensesAccount instanceof Account || ! $this->postable($expensesAccount)))) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.account_unavailable'));
            }
            if (in_array((int) $proceedsAccount?->getKey(), [(int) $asset->account_id, (int) $mapping->accumulated_depreciation_account_id], true) || in_array((int) $expensesAccount?->getKey(), [(int) $asset->account_id, (int) $mapping->accumulated_depreciation_account_id], true)) {
                throw new DomainException(__('fixed_assets.cycle.invalid_counter'));
            }
            if ($settlementPath === FixedAssetDisposal::SettlementDirect && bccomp($taxRate, '0', 4) > 0) {
                throw new DomainException(__('fixed_assets.cycle.direct_tax'));
            }
            if (! $asset->account || ! $this->postable($asset->account)) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.account_unavailable'));
            }
            $customerDocNum = $this->nullableString($data['customer_doc_num'] ?? null);
            $customer = $this->modelByDocNum(Customer::class, (int) $asset->company_id, $customerDocNum);

            if ($customerDocNum !== null && (! $customer instanceof Customer || $customer->status !== 'active')) {
                throw new DomainException(__('fixed_assets.lifecycle.errors.customer_unavailable'));
            }
            if ($settlementPath === FixedAssetDisposal::SettlementCustomerInvoice
                && ($data['disposition_type'] !== FixedAssetDisposal::TypeSale || ! $customer instanceof Customer)) {
                throw new DomainException(__('An invoiced Fixed Asset disposal requires a sale and an active Customer.'));
            }

            $disposal = FixedAssetDisposal::query()->create([
                ...$this->documents->nextForCompany('fixed_asset_disposals', FixedAssetDisposal::class, (int) $asset->company_id),
                'company_id' => $asset->company_id,
                'financial_period_id' => $period->getKey(),
                'fixed_asset_id' => $asset->getKey(),
                'asset_status_before' => $asset->status,
                'branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id,
                'accumulated_account_id' => $mapping->accumulated_depreciation_account_id,
                'disposal_date' => $date,
                'disposition_type' => $data['disposition_type'],
                'reason' => $data['reason'],
                'customer_id' => $customer?->getKey(),
                'proceeds_account_id' => $proceedsAccount?->getKey(),
                'settlement_path' => $settlementPath,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'gross_proceeds' => $grossProceeds,
                'original_cost' => $position['acquisition_cost'],
                'base_original_cost' => $position['base_acquisition_cost'],
                'accumulated_depreciation' => $position['accumulated_depreciation'],
                'base_accumulated_depreciation' => $position['base_accumulated_depreciation'],
                'net_book_value' => $position['net_book_value'],
                'base_net_book_value' => $position['base_net_book_value'],
                'proceeds' => $proceeds,
                'base_proceeds' => $baseProceeds,
                'disposal_expenses' => $expenses, 'base_disposal_expenses' => $baseExpenses, 'net_proceeds' => $netProceeds, 'expenses_account_id' => $expensesAccount?->getKey(),
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

            $dimensions = ['branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id];
            $description = __('fixed_assets.lifecycle.journal.disposal_line', ['asset' => $asset->doc_num]);
            $mainCurrency = Currency::query()->forCompany((int) $asset->company_id)->where('is_main', true)->whereNull('deleted_at')->firstOrFail();

            if ($settlementPath === FixedAssetDisposal::SettlementCustomerInvoice) {
                $clearingAccount = $mapping->disposalClearingAccount;
                if (! $clearingAccount instanceof Account || ! $this->postable($clearingAccount)) {
                    throw new DomainException(__('The Fixed Asset disposal clearing account is not configured.'));
                }
                $derecognitionLines = [];
                if (bccomp($position['base_accumulated_depreciation'], '0', 4) > 0) {
                    $derecognitionLines[] = ['account_id' => $mapping->accumulated_depreciation_account_id, 'debit_amount' => $position['base_accumulated_depreciation'], 'credit_amount' => 0, 'description' => $description, ...$dimensions];
                }
                if (bccomp($position['base_net_book_value'], '0', 4) > 0) {
                    $derecognitionLines[] = ['account_id' => $clearingAccount->getKey(), 'debit_amount' => $position['base_net_book_value'], 'credit_amount' => 0, 'description' => $description, ...$dimensions];
                }
                $derecognitionLines[] = ['account_id' => $asset->account_id, 'debit_amount' => 0, 'credit_amount' => $position['base_acquisition_cost'], 'description' => $description, ...$dimensions];
                $journal = $this->journals->createPostedFromSource([
                    'entry_date' => $date, 'company_id' => $asset->company_id,
                    'financial_period_id' => $period->getKey(), 'branch_id' => $asset->branch_id,
                    'currency_id' => $mainCurrency->getKey(), 'exchange_rate' => '1.000000',
                    'description' => __('Fixed Asset derecognition :document', ['document' => $disposal->doc_num]),
                    'notes' => $data['notes'] ?? null, 'source_type' => 'fixed_asset_disposal_derecognition',
                    'source_id' => $disposal->getKey(), 'source_doc_num' => $disposal->doc_num,
                ], $derecognitionLines);

                $invoice = $this->customerInvoices->createNonStockSourceInvoice([
                    'company_id' => $asset->company_id, 'financial_period_id' => $period->getKey(),
                    'branch_id' => $asset->branch_id, 'customer_id' => $customer->getKey(),
                    'invoice_date' => $date->toDateString(), 'due_date' => $data['due_date'] ?? $date->toDateString(),
                    'currency_id' => $asset->currency_id, 'exchange_rate' => $asset->exchange_rate,
                    'net_amount' => $proceeds, 'tax_amount' => $taxAmount,
                    'description' => __('Fixed Asset sale :asset', ['asset' => $asset->doc_num]),
                    'source_type' => 'fixed_asset_disposal', 'source_id' => $disposal->getKey(),
                    'source_doc_num' => $disposal->doc_num,
                    'source_snapshot' => ['fixed_asset_doc_num' => $asset->doc_num, 'disposal_doc_num' => $disposal->doc_num, 'tax_rate' => $taxRate, 'tax_code' => bccomp($taxAmount, '0', 4) > 0 ? 'VAT' : 'EXEMPT', 'unit_code' => 'EA'],
                    'notes' => $data['notes'] ?? null,
                ]);
                $invoice = $this->customerInvoices->post($invoice);

                $gainLossLines = bccomp($baseGain, '0', 4) > 0
                    ? [
                        ['account_id' => $clearingAccount->getKey(), 'debit_amount' => $baseGain, 'credit_amount' => 0, 'description' => $description, ...$dimensions],
                        ['account_id' => $mapping->disposal_gain_account_id, 'debit_amount' => 0, 'credit_amount' => $baseGain, 'description' => $description, ...$dimensions],
                    ]
                    : [
                        ['account_id' => $mapping->disposal_loss_account_id, 'debit_amount' => $baseLoss, 'credit_amount' => 0, 'description' => $description, ...$dimensions],
                        ['account_id' => $clearingAccount->getKey(), 'debit_amount' => 0, 'credit_amount' => $baseLoss, 'description' => $description, ...$dimensions],
                    ];
                $gainLossJournal = bccomp($baseGain, '0', 4) > 0 || bccomp($baseLoss, '0', 4) > 0
                    ? $this->journals->createPostedFromSource([
                        'entry_date' => $date, 'company_id' => $asset->company_id,
                        'financial_period_id' => $period->getKey(), 'branch_id' => $asset->branch_id,
                        'currency_id' => $mainCurrency->getKey(), 'exchange_rate' => '1.000000',
                        'description' => __('Fixed Asset disposal gain/loss :document', ['document' => $disposal->doc_num]),
                        'notes' => $data['notes'] ?? null, 'source_type' => 'fixed_asset_disposal_gain_loss',
                        'source_id' => $disposal->getKey(), 'source_doc_num' => $disposal->doc_num,
                    ], $gainLossLines)
                    : null;
                $expensesJournal = bccomp($baseExpenses, '0', 4) > 0 ? $this->journals->createPostedFromSource([
                    'entry_date' => $date, 'company_id' => $asset->company_id, 'financial_period_id' => $period->getKey(),
                    'branch_id' => $asset->branch_id, 'currency_id' => $mainCurrency->getKey(), 'exchange_rate' => '1.000000',
                    'description' => $description, 'source_type' => 'fixed_asset_disposal_expenses', 'source_id' => $disposal->getKey(), 'source_doc_num' => $disposal->doc_num,
                ], [
                    ['account_id' => $clearingAccount->getKey(), 'debit_amount' => $baseExpenses, 'credit_amount' => '0', 'description' => $description, ...$dimensions],
                    ['account_id' => $expensesAccount->getKey(), 'debit_amount' => '0', 'credit_amount' => $baseExpenses, 'description' => $description, ...$dimensions],
                ]) : null;
                $disposal->forceFill([
                    'expenses_journal_entry_id' => $expensesJournal?->getKey(),
                    'journal_entry_id' => $journal->getKey(), 'customer_invoice_id' => $invoice->getKey(),
                    'gain_loss_journal_entry_id' => $gainLossJournal?->getKey(),
                ])->save();
            } else {
                $journalLines = [];

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

                if (bccomp($baseExpenses, '0', 4) > 0) {
                    $journalLines[] = ['account_id' => $expensesAccount->getKey(), 'debit_amount' => '0', 'credit_amount' => $baseExpenses, 'description' => $description, ...$dimensions];
                }
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
            }
            $status = match ($data['disposition_type']) {
                FixedAssetDisposal::TypeSale => FixedAsset::StatusSold,
                FixedAssetDisposal::TypeWriteOff => FixedAsset::StatusWrittenOff,
                default => FixedAsset::StatusDisposed,
            };
            $this->audit->saveUpdate($asset, ['status' => $status, 'disposed_at' => $date, 'net_value' => '0.0000', 'locked_at' => $asset->locked_at ?: now()]);

            app(ActivityLogger::class)->log(request(), 'fixed_assets', 'disposal', 'success', ['subject' => $asset, 'properties_only' => true, 'properties' => ['document' => $disposal->doc_num, 'journal_entry_id' => $disposal->journal_entry_id]]);

            return $disposal->refresh()->load(['asset', 'customer', 'proceedsAccount', 'journalEntry']);
        }, attempts: 3);
    }

    public function canReverseDisposal(FixedAssetDisposal $disposal): bool
    {
        if ($disposal->status !== FixedAssetDisposal::StatusPosted) {
            return false;
        }
        try {
            app(FixedAssetAccessService::class)->assertAsset($disposal->asset);
            $period = $this->financialPeriods->resolveOpenForPostingDate((int) $disposal->company_id, $disposal->disposal_date, expectedPeriodId: (int) $disposal->financial_period_id);
            app(FixedAssetAccessService::class)->assertPeriod($period);
        } catch (DomainException|HttpException $exception) {
            return false;
        }
        $invoice = $disposal->customerInvoice;

        return ! $invoice || ($invoice->posting_status === 'posted'
            && bccomp((string) $invoice->paid_amount, '0', 4) <= 0
            && bccomp((string) $invoice->credited_amount, '0', 4) <= 0
            && $invoice->electronic_invoice_uuid === null
            && in_array($invoice->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)
            && ! $invoice->returns()->where('status', '<>', 'cancelled')->exists()
            && ! $invoice->creditNotes()->exists());
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

            $asset = $this->lockedAsset($disposal->asset);
            $period = $this->financialPeriods->resolveOpenForPostingDate(
                (int) $disposal->company_id,
                $disposal->disposal_date,
                expectedPeriodId: (int) $disposal->financial_period_id,
                lockForUpdate: true,
            );

            app(FixedAssetAccessService::class)->assertPeriod($period);
            $disposal->loadMissing(['journalEntry.lines', 'gainLossJournalEntry.lines', 'customerInvoice']);
            if ($disposal->customerInvoice) {
                $this->customerInvoices->reopen($disposal->customerInvoice, $reason);
            }
            $gainLossReversal = $disposal->gainLossJournalEntry
                ? $this->journals->createPostedReversalFromSource($disposal->gainLossJournalEntry, [
                    'entry_date' => $disposal->disposal_date,
                    'company_id' => $disposal->company_id,
                    'financial_period_id' => $disposal->financial_period_id,
                    'currency_id' => $disposal->gainLossJournalEntry->currency_id,
                    'exchange_rate' => $disposal->gainLossJournalEntry->exchange_rate,
                    'description' => __('Fixed Asset disposal gain/loss reversal :document', ['document' => $disposal->doc_num]),
                    'notes' => $reason,
                    'source_type' => 'fixed_asset_disposal_gain_loss_reversal',
                    'source_id' => $disposal->getKey(),
                    'source_doc_num' => $disposal->doc_num,
                ])
                : null;
            $expenseReversal = $disposal->expensesJournalEntry ? $this->journals->createPostedReversalFromSource($disposal->expensesJournalEntry, [
                'entry_date' => $disposal->disposal_date, 'company_id' => $disposal->company_id, 'financial_period_id' => $disposal->financial_period_id,
                'currency_id' => $disposal->expensesJournalEntry->currency_id, 'exchange_rate' => '1.000000', 'description' => $reason,
                'source_type' => 'fixed_asset_disposal_expenses_reversal', 'source_id' => $disposal->getKey(), 'source_doc_num' => $disposal->doc_num,
            ]) : null;
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
            $disposal->forceFill([
                'status' => FixedAssetDisposal::StatusReversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'expenses_reversal_journal_entry_id' => $expenseReversal?->getKey(),
                'gain_loss_reversal_journal_entry_id' => $gainLossReversal?->getKey(),
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ])->save();

            $position = $this->bookValues->position($asset);
            $this->audit->saveUpdate($asset, [
                'status' => $disposal->asset_status_before
                    ?: (bccomp($position['remaining_depreciable_amount'], '0', 4) <= 0 ? FixedAsset::StatusFullyDepreciated : FixedAsset::StatusActive),
                'disposed_at' => null, 'net_value' => $position['net_book_value'],
            ]);

            app(ActivityLogger::class)->log(request(), 'fixed_assets', 'disposal', 'success', ['subject' => $asset, 'properties_only' => true, 'properties' => ['document' => $disposal->doc_num, 'status' => $disposal->status, 'journal_entry_id' => $disposal->journal_entry_id]]);

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
            'accumulated_depreciation_account_id' => empty($data['accumulated_depreciation_account_doc_num']) ? null : $this->requiredPostableOrGroupAccount($companyId, $data['accumulated_depreciation_account_doc_num'])->getKey(),
            'depreciation_expense_account_id' => empty($data['depreciation_expense_account_doc_num']) ? null : $this->requiredPostableOrGroupAccount($companyId, $data['depreciation_expense_account_doc_num'])->getKey(),
            'disposal_gain_account_id' => empty($data['disposal_gain_account_doc_num']) ? null : $this->requiredPostableOrGroupAccount($companyId, $data['disposal_gain_account_doc_num'])->getKey(),
            'disposal_loss_account_id' => empty($data['disposal_loss_account_doc_num']) ? null : $this->requiredPostableOrGroupAccount($companyId, $data['disposal_loss_account_doc_num'])->getKey(),
            'disposal_clearing_account_id' => empty($data['disposal_clearing_account_doc_num']) ? null : $this->requiredPostableOrGroupAccount($companyId, $data['disposal_clearing_account_doc_num'])->getKey(),
        ];

        $mapping = FixedAssetCategoryMapping::query()->firstOrNew([
            'company_id' => $companyId,
            'asset_group_account_id' => $category->getKey(),
        ]);

        if (! $mapping->exists) {
            $mapping->created_by = auth()->id();
        }

        $mapping->fill($values);
        if ($mapping->exists && $mapping->isDirty('accumulated_depreciation_account_id') && FixedAsset::query()->where('company_id', $companyId)->where('asset_group_account_id', $category->getKey())->get()->contains(fn (FixedAsset $asset): bool => $asset->isMasterLocked())) {
            throw new DomainException(__('fixed_assets.cycle.accumulated_mapping_locked'));
        }
        $mapping->assertValidAccounts();
        if (! $mapping->exists && ! array_filter($mapping->only(['accumulated_depreciation_account_id', 'depreciation_expense_account_id', 'disposal_gain_account_id', 'disposal_loss_account_id', 'disposal_clearing_account_id']))) {
            return $mapping;
        }
        if (! $mapping->exists || $mapping->isDirty()) {
            if ($mapping->exists) {
                $mapping->updated_by = auth()->id();
            }
            $mapping->save();
            app(ActivityLogger::class)->log(request(), 'fixed_assets', 'accounting_mapping', 'success', ['subject' => $mapping, 'properties_only' => true, 'properties' => ['category' => $category->doc_num]]);
        }

        return $mapping->refresh();
    }

    private function lockedAsset(FixedAsset $asset): FixedAsset
    {
        app(FixedAssetAccessService::class)->assertAsset($asset);

        $asset = FixedAsset::query()->forCompany($this->companies->requireCompanyId())->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
        app(FixedAssetAccessService::class)->assertAsset($asset);

        return $asset;
    }

    private function assertOperational(FixedAsset $asset): void
    {
        if (! in_array($asset->status, [FixedAsset::StatusActive, FixedAsset::StatusSuspended, FixedAsset::StatusFullyDepreciated], true) || $asset->isDisposed()) {
            throw new DomainException(__('fixed_assets.lifecycle.errors.asset_not_operational'));
        }
    }

    private function openPeriodForDate(int $companyId, Carbon $date): FinancialPeriod
    {
        $period = $this->financialPeriods->resolveOpenForPostingDate($companyId, $date, lockForUpdate: true);
        app(FixedAssetAccessService::class)->assertPeriod($period);

        return $period;
    }

    private function disposalMapping(FixedAsset $asset, string $accumulated, string $gainLoss, bool $invoice): FixedAssetCategoryMapping
    {
        $fields = [];
        if (bccomp($accumulated, '0', 4) > 0) {
            $fields[] = 'accumulated_depreciation_account_id';
        }
        if (bccomp($gainLoss, '0', 4) > 0) {
            $fields[] = 'disposal_gain_account_id';
        } elseif (bccomp($gainLoss, '0', 4) < 0) {
            $fields[] = 'disposal_loss_account_id';
        }
        if ($invoice) {
            $fields[] = 'disposal_clearing_account_id';
        }

        return FixedAssetCategoryMapping::resolveForAsset($asset, $fields);
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
