<?php

namespace Modules\Accounting\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Product;
use Modules\FixedAssets\Services\FixedAssetReportService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Throwable;

final class ReconciliationCenterService
{
    public const Customers = 'customers_ar';

    public const Suppliers = 'suppliers_ap';

    public const CashSafes = 'cash_safes_gl';

    public const Banks = 'banks_gl';

    public const BankStatement = 'bank_statement_books';

    public const Inventory = 'inventory_gl';

    public const FixedAssets = 'fixed_assets_gl';

    public const PayrollPayable = 'payroll_payable_gl';

    public const PayrollSettlement = 'payroll_settlement_cash_bank';

    public const CostCenters = 'cost_centers_allocation';

    public const Production = 'wip_fg_cogs';

    public const FinancialStatements = 'financial_statement_cross_checks';

    public function __construct(
        private readonly ReconciliationComparisonService $comparisons,
        private readonly PostingAccountResolver $accounts,
        private readonly FixedAssetReportService $fixedAssetReports,
    ) {}

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::Customers,
            self::Suppliers,
            self::CashSafes,
            self::Banks,
            self::BankStatement,
            self::Inventory,
            self::FixedAssets,
            self::PayrollPayable,
            self::PayrollSettlement,
            self::CostCenters,
            self::Production,
            self::FinancialStatements,
        ];
    }

    /** @return array<string, mixed> */
    public function report(
        int $companyId,
        int $financialPeriodId,
        int $branchId,
        string $fromDate,
        string $toDate,
        ?string $type = null,
    ): array {
        $openingDate = Carbon::parse($fromDate)->subDay()->toDateString();
        $builders = [
            self::Customers => fn (): array => $this->customerReconciliation($companyId, $branchId, $openingDate, $toDate),
            self::Suppliers => fn (): array => $this->supplierReconciliation($companyId, $branchId, $openingDate, $toDate),
            self::CashSafes => fn (): array => $this->holderReconciliation('cashbox', $companyId, $branchId, $openingDate, $toDate),
            self::Banks => fn (): array => $this->holderReconciliation('bank', $companyId, $branchId, $openingDate, $toDate),
            self::BankStatement => fn (): array => $this->unavailableBankStatement(),
            self::Inventory => fn (): array => $this->inventoryReconciliation($companyId, $branchId, $openingDate, $toDate),
            self::FixedAssets => fn (): array => $this->fixedAssetReconciliation($companyId, $branchId, $openingDate, $toDate),
            self::PayrollPayable => fn (): array => $this->payrollPayableReconciliation($companyId, $branchId, $openingDate, $toDate),
            self::PayrollSettlement => fn (): array => $this->unavailablePayrollSettlement(),
            self::CostCenters => fn (): array => $this->costCenterReconciliation($companyId, $financialPeriodId, $branchId, $openingDate, $toDate),
            self::Production => fn (): array => $this->productionReconciliation($companyId, $branchId, $openingDate, $toDate),
            self::FinancialStatements => fn (): array => $this->financialStatementReconciliation($companyId, $branchId, $openingDate, $toDate),
        ];

        $selected = $type !== null && isset($builders[$type]) ? [$type => $builders[$type]] : $builders;
        $results = collect($selected)->map(fn (callable $builder): array => $builder())->values();

        return [
            'filters' => [
                'company_id' => $companyId,
                'financial_period_id' => $financialPeriodId,
                'branch_id' => $branchId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'type' => $type,
            ],
            'results' => $results,
            'status_counts' => $results->countBy('status')->all(),
            'mismatch_count' => $results->sum(fn (array $result): int => (int) $result['summary']['mismatch_count']),
            'absolute_difference_total' => $results->reduce(
                fn (string $sum, array $result): string => bcadd($sum, (string) $result['summary']['absolute_difference_total'], 4),
                '0.0000',
            ),
        ];
    }

    private function customerReconciliation(int $companyId, int $branchId, string $openingDate, string $toDate): array
    {
        $parties = DB::table('customers')->where('company_id', $companyId)->get(['id', 'doc_num', 'name', 'account_id'])->keyBy('id');
        $openingSource = $this->customerSourceAt($companyId, $branchId, $openingDate);
        $endingSource = $this->customerSourceAt($companyId, $branchId, $toDate);
        $accountIds = $parties->pluck('account_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $openingGl = $this->glBalances($companyId, $branchId, $openingDate, $accountIds, 'customer_id');
        $endingGl = $this->glBalances($companyId, $branchId, $toDate, $accountIds, 'customer_id');
        [$rows, $mappingConfigured] = $this->partyRows(
            $parties,
            $openingSource,
            $endingSource,
            $openingGl,
            $endingGl,
            creditNormal: false,
            openingDate: $openingDate,
            toDate: $toDate,
        );

        return $this->comparisons->compare(
            self::Customers,
            __('reconciliation_center.types.'.self::Customers),
            $rows,
            $mappingConfigured,
            $openingSource->isNotEmpty() || $endingSource->isNotEmpty() || $openingGl->isNotEmpty() || $endingGl->isNotEmpty(),
        );
    }

    private function supplierReconciliation(int $companyId, int $branchId, string $openingDate, string $toDate): array
    {
        $parties = DB::table('suppliers')->where('company_id', $companyId)->get(['id', 'doc_num', 'name', 'account_id'])->keyBy('id');
        $openingSource = $this->supplierSourceAt($companyId, $branchId, $openingDate);
        $endingSource = $this->supplierSourceAt($companyId, $branchId, $toDate);
        $accountIds = $parties->pluck('account_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $openingGl = $this->glBalances($companyId, $branchId, $openingDate, $accountIds, 'supplier_id');
        $endingGl = $this->glBalances($companyId, $branchId, $toDate, $accountIds, 'supplier_id');
        [$rows, $mappingConfigured] = $this->partyRows(
            $parties,
            $openingSource,
            $endingSource,
            $openingGl,
            $endingGl,
            creditNormal: true,
            openingDate: $openingDate,
            toDate: $toDate,
        );

        return $this->comparisons->compare(
            self::Suppliers,
            __('reconciliation_center.types.'.self::Suppliers),
            $rows,
            $mappingConfigured,
            $openingSource->isNotEmpty() || $endingSource->isNotEmpty() || $openingGl->isNotEmpty() || $endingGl->isNotEmpty(),
        );
    }

    private function holderReconciliation(string $holder, int $companyId, int $branchId, string $openingDate, string $toDate): array
    {
        $isCashbox = $holder === 'cashbox';
        $table = $isCashbox ? 'cashboxes' : 'bank_accounts';
        $key = $isCashbox ? self::CashSafes : self::Banks;
        $holders = DB::table($table)
            ->where('company_id', $companyId)
            ->when($isCashbox, fn (Builder $query): Builder => $query->where('branch_id', $branchId))
            ->get($isCashbox
                ? ['id', 'doc_num', 'name', 'account_id']
                : ['id', 'doc_num', 'account_name as name', 'account_id'])
            ->keyBy('id');
        $openingSource = $this->holderSourceAt($holder, $companyId, $branchId, $openingDate);
        $endingSource = $this->holderSourceAt($holder, $companyId, $branchId, $toDate);
        $accountIds = $holders->pluck('account_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $openingGl = $this->glBalances($companyId, $branchId, $openingDate, $accountIds);
        $endingGl = $this->glBalances($companyId, $branchId, $toDate, $accountIds);
        $rows = collect();
        $mappingConfigured = true;

        foreach ($holders as $id => $record) {
            $accountId = $record->account_id === null ? null : (int) $record->account_id;
            $sourceOpening = (string) ($openingSource[$id] ?? '0');
            $sourceEnding = (string) ($endingSource[$id] ?? '0');
            $glOpening = $accountId === null ? '0' : (string) ($openingGl["none:{$accountId}"] ?? '0');
            $glEnding = $accountId === null ? '0' : (string) ($endingGl["none:{$accountId}"] ?? '0');
            if ($accountId === null && ($this->nonZero($sourceOpening) || $this->nonZero($sourceEnding))) {
                $mappingConfigured = false;
            }
            if (! $this->hasEvidence([$sourceOpening, $sourceEnding, $glOpening, $glEnding])) {
                continue;
            }
            $rows->push($this->balanceRow(
                (string) $id,
                trim($record->doc_num.' / '.$record->name),
                $sourceOpening,
                $sourceEnding,
                $glOpening,
                $glEnding,
                $accountId,
                $openingDate,
                $toDate,
            ));
        }

        return $this->comparisons->compare(
            $key,
            __('reconciliation_center.types.'.$key),
            $rows,
            $mappingConfigured,
            $rows->isNotEmpty(),
        );
    }

    private function unavailableBankStatement(): array
    {
        return $this->comparisons->compare(
            self::BankStatement,
            __('reconciliation_center.types.'.self::BankStatement),
            [],
            sourceAvailable: false,
            notes: [__('reconciliation_center.notes.bank_statement_unavailable')],
        );
    }

    private function inventoryReconciliation(int $companyId, int $branchId, string $openingDate, string $toDate): array
    {
        $mappingConfigured = true;
        $productAccounts = [];
        $products = Product::withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('item_classification', Product::stockableItemClassifications())
            ->get(['id', 'doc_num', 'name', 'item_classification']);

        foreach ($products as $product) {
            try {
                $productAccounts[$product->getKey()] = $this->accounts->inventoryForProduct($companyId, $product, __('reconciliation_center.events.inventory'))->getKey();
            } catch (DomainException) {
                $mappingConfigured = false;
            }
        }

        $specialAccounts = [];
        $specialStatuses = InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('transaction_date', '<=', $toDate)
            ->whereIn('stock_status', [InventoryTransaction::StatusQuarantine, InventoryTransaction::StatusRework])
            ->distinct()
            ->pluck('stock_status');
        foreach ([
            InventoryTransaction::StatusQuarantine => PostingAccountResolver::QuarantineInventory,
            InventoryTransaction::StatusRework => PostingAccountResolver::ReworkInventory,
        ] as $status => $classification) {
            if (! $specialStatuses->contains($status)) {
                continue;
            }
            try {
                $specialAccounts[$status] = $this->accounts->resolve($companyId, $classification, __('reconciliation_center.events.inventory'))->getKey();
            } catch (DomainException) {
                $mappingConfigured = false;
            }
        }

        $accountIds = collect([...array_values($productAccounts), ...array_values($specialAccounts)])
            ->unique()->map(fn (mixed $id): int => (int) $id)->values()->all();
        $openingSource = $this->inventorySourceAt($companyId, $branchId, $openingDate, $productAccounts, $specialAccounts);
        $endingSource = $this->inventorySourceAt($companyId, $branchId, $toDate, $productAccounts, $specialAccounts);
        $openingGl = $this->glBalances($companyId, $branchId, $openingDate, $accountIds);
        $endingGl = $this->glBalances($companyId, $branchId, $toDate, $accountIds);
        $accounts = Account::withTrashed()->whereIn('id', $accountIds)->get()->keyBy('id');
        $rows = collect($accountIds)->map(function (int $accountId) use ($openingSource, $endingSource, $openingGl, $endingGl, $accounts, $openingDate, $toDate): array {
            $account = $accounts->get($accountId);

            return $this->balanceRow(
                (string) $accountId,
                $account?->codeNameLabel() ?? (string) $accountId,
                (string) ($openingSource[$accountId] ?? '0'),
                (string) ($endingSource[$accountId] ?? '0'),
                (string) ($openingGl["none:{$accountId}"] ?? '0'),
                (string) ($endingGl["none:{$accountId}"] ?? '0'),
                $accountId,
                $openingDate,
                $toDate,
            );
        })->filter(fn (array $row): bool => $this->hasEvidence([
            $row['source_opening'], $row['source_ending'], $row['gl_opening'], $row['gl_ending'],
        ]))->values();

        return $this->comparisons->compare(
            self::Inventory,
            __('reconciliation_center.types.'.self::Inventory),
            $rows,
            $mappingConfigured,
            $rows->isNotEmpty(),
            notes: [__('reconciliation_center.notes.inventory_source')],
        );
    }

    private function fixedAssetReconciliation(int $companyId, int $branchId, string $openingDate, string $toDate): array
    {
        try {
            $branchDocNum = Branch::query()->where('company_id', $companyId)->whereKey($branchId)->value('doc_num');
            $opening = $this->fixedAssetReports->report([
                'type' => FixedAssetReportService::Reconciliation,
                'to_date' => $openingDate,
                'branch_doc_num' => $branchDocNum,
            ])['rows']->keyBy(fn (array $row): string => (string) ($row['account'] ?? $row['reconciliation_type']));
            $ending = $this->fixedAssetReports->report([
                'type' => FixedAssetReportService::Reconciliation,
                'to_date' => $toDate,
                'branch_doc_num' => $branchDocNum,
            ])['rows']->keyBy(fn (array $row): string => (string) ($row['account'] ?? $row['reconciliation_type']));
            $keys = $opening->keys()->merge($ending->keys())->unique();
            $rows = $keys->map(function (string $key) use ($opening, $ending): array {
                $openingRow = $opening->get($key, []);
                $endingRow = $ending->get($key, []);

                return $this->balanceRow(
                    $key,
                    $key,
                    (string) ($openingRow['subledger'] ?? '0'),
                    (string) ($endingRow['subledger'] ?? '0'),
                    (string) ($openingRow['general_ledger'] ?? $openingRow['gl'] ?? '0'),
                    (string) ($endingRow['general_ledger'] ?? $endingRow['gl'] ?? '0'),
                );
            });

            return $this->comparisons->compare(
                self::FixedAssets,
                __('reconciliation_center.types.'.self::FixedAssets),
                $rows,
                sourceAvailable: $rows->isNotEmpty(),
            );
        } catch (Throwable) {
            return $this->comparisons->compare(
                self::FixedAssets,
                __('reconciliation_center.types.'.self::FixedAssets),
                [],
                sourceAvailable: false,
                notes: [__('reconciliation_center.notes.fixed_assets_unavailable')],
            );
        }
    }

    private function payrollPayableReconciliation(int $companyId, int $branchId, string $openingDate, string $toDate): array
    {
        if (! Schema::hasTable('hr_payroll_runs')) {
            return $this->comparisons->compare(self::PayrollPayable, __('reconciliation_center.types.'.self::PayrollPayable), [], sourceAvailable: false);
        }

        $payableAccounts = Account::query()
            ->join('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.company_id', $companyId)
            ->where('account_classifications.code', 'payroll_payable')
            ->where('accounts.is_postable', true)
            ->pluck('accounts.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $mappingConfigured = count($payableAccounts) === 1;
        $accountId = $mappingConfigured ? $payableAccounts[0] : null;
        $openingSource = $this->payrollSourceAt($companyId, $openingDate);
        $endingSource = $this->payrollSourceAt($companyId, $toDate);
        $openingGl = $accountId === null ? collect() : $this->glBalances($companyId, $branchId, $openingDate, [$accountId], creditNormal: true);
        $endingGl = $accountId === null ? collect() : $this->glBalances($companyId, $branchId, $toDate, [$accountId], creditNormal: true);
        $row = $this->balanceRow(
            'payroll_payable',
            __('reconciliation_center.rows.payroll_payable'),
            $openingSource,
            $endingSource,
            (string) ($openingGl["none:{$accountId}"] ?? '0'),
            (string) ($endingGl["none:{$accountId}"] ?? '0'),
            $accountId,
            $openingDate,
            $toDate,
        );

        return $this->comparisons->compare(
            self::PayrollPayable,
            __('reconciliation_center.types.'.self::PayrollPayable),
            $this->hasEvidence([$openingSource, $endingSource, $row['gl_opening'], $row['gl_ending']]) ? [$row] : [],
            $mappingConfigured,
            $this->hasEvidence([$openingSource, $endingSource, $row['gl_opening'], $row['gl_ending']]),
        );
    }

    private function unavailablePayrollSettlement(): array
    {
        return $this->comparisons->compare(
            self::PayrollSettlement,
            __('reconciliation_center.types.'.self::PayrollSettlement),
            [],
            applicable: false,
            notes: [__('reconciliation_center.notes.payroll_settlement_unavailable')],
        );
    }

    private function costCenterReconciliation(int $companyId, int $financialPeriodId, int $branchId, string $openingDate, string $toDate): array
    {
        if (! Schema::hasTable('cost_overhead_allocation_runs')) {
            return $this->comparisons->compare(self::CostCenters, __('reconciliation_center.types.'.self::CostCenters), [], sourceAvailable: false);
        }

        $opening = $this->overheadTotalsAt($companyId, $financialPeriodId, $branchId, $openingDate);
        $ending = $this->overheadTotalsAt($companyId, $financialPeriodId, $branchId, $toDate);
        $row = $this->balanceRow(
            'overhead_allocation',
            __('reconciliation_center.rows.overhead_allocation'),
            $opening['eligible'],
            $ending['eligible'],
            $opening['accounted'],
            $ending['accounted'],
        );

        return $this->comparisons->compare(
            self::CostCenters,
            __('reconciliation_center.types.'.self::CostCenters),
            $this->hasEvidence([$opening['eligible'], $ending['eligible'], $opening['accounted'], $ending['accounted']]) ? [$row] : [],
            sourceAvailable: $this->nonZero($opening['eligible']) || $this->nonZero($ending['eligible']),
            notes: [__('reconciliation_center.notes.cost_center_invariant')],
        );
    }

    private function productionReconciliation(int $companyId, int $branchId, string $openingDate, string $toDate): array
    {
        $mappingConfigured = true;
        $mappings = [];
        foreach ([
            'wip' => PostingAccountResolver::WorkInProcessInventory,
            'finished_goods' => PostingAccountResolver::FinishedGoodsInventory,
            'cogs' => PostingAccountResolver::CostOfGoodsSold,
        ] as $key => $classification) {
            try {
                $mappings[$key] = $this->accounts->resolve($companyId, $classification, __('reconciliation_center.events.production'))->getKey();
            } catch (DomainException) {
                $mappingConfigured = false;
            }
        }

        $accountIds = array_values($mappings);
        $openingGl = $this->glBalances($companyId, $branchId, $openingDate, $accountIds);
        $endingGl = $this->glBalances($companyId, $branchId, $toDate, $accountIds);
        $rows = collect();
        foreach (['wip', 'finished_goods', 'cogs'] as $key) {
            $accountId = $mappings[$key] ?? null;
            $sourceOpening = $this->productionSourceAt($key, $companyId, $branchId, $openingDate);
            $sourceEnding = $this->productionSourceAt($key, $companyId, $branchId, $toDate);
            $rows->push($this->balanceRow(
                $key,
                __('reconciliation_center.rows.'.$key),
                $sourceOpening,
                $sourceEnding,
                $accountId === null ? '0' : (string) ($openingGl["none:{$accountId}"] ?? '0'),
                $accountId === null ? '0' : (string) ($endingGl["none:{$accountId}"] ?? '0'),
                $accountId,
                $openingDate,
                $toDate,
            ));
        }
        $rows = $rows->filter(fn (array $row): bool => $this->hasEvidence([$row['source_opening'], $row['source_ending'], $row['gl_opening'], $row['gl_ending']]))->values();

        return $this->comparisons->compare(
            self::Production,
            __('reconciliation_center.types.'.self::Production),
            $rows,
            $mappingConfigured,
            $rows->isNotEmpty(),
            notes: [__('reconciliation_center.notes.production_source')],
        );
    }

    private function financialStatementReconciliation(int $companyId, int $branchId, string $openingDate, string $toDate): array
    {
        $opening = $this->accountingEquationAt($companyId, $branchId, $openingDate);
        $ending = $this->accountingEquationAt($companyId, $branchId, $toDate);
        $row = $this->balanceRow(
            'accounting_equation',
            __('reconciliation_center.rows.accounting_equation'),
            $opening['debit_side'],
            $ending['debit_side'],
            $opening['credit_side'],
            $ending['credit_side'],
        );

        return $this->comparisons->compare(
            self::FinancialStatements,
            __('reconciliation_center.types.'.self::FinancialStatements),
            $ending['has_data'] ? [$row] : [],
            sourceAvailable: $ending['has_data'],
            notes: [__('reconciliation_center.notes.financial_statement_internal')],
        );
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: bool} */
    private function partyRows(
        Collection $parties,
        Collection $openingSource,
        Collection $endingSource,
        Collection $openingGl,
        Collection $endingGl,
        bool $creditNormal,
        string $openingDate,
        string $toDate,
    ): array {
        $rows = collect();
        $mappingConfigured = true;

        foreach ($parties as $partyId => $party) {
            $accountId = $party->account_id === null ? null : (int) $party->account_id;
            $sourceOpening = (string) ($openingSource[$partyId] ?? '0');
            $sourceEnding = (string) ($endingSource[$partyId] ?? '0');
            $glKey = "{$partyId}:".($accountId ?? 'none');
            $glOpening = $accountId === null ? '0' : (string) ($openingGl[$glKey] ?? '0');
            $glEnding = $accountId === null ? '0' : (string) ($endingGl[$glKey] ?? '0');
            if ($accountId === null && ($this->nonZero($sourceOpening) || $this->nonZero($sourceEnding))) {
                $mappingConfigured = false;
            }
            if (! $this->hasEvidence([$sourceOpening, $sourceEnding, $glOpening, $glEnding])) {
                continue;
            }
            if ($creditNormal) {
                $glOpening = bcmul($glOpening, '-1', 4);
                $glEnding = bcmul($glEnding, '-1', 4);
            }
            $rows->push($this->balanceRow(
                (string) $partyId,
                trim($party->doc_num.' / '.$party->name),
                $sourceOpening,
                $sourceEnding,
                $glOpening,
                $glEnding,
                $accountId,
                $openingDate,
                $toDate,
            ));
        }

        foreach ($openingGl->keys()->merge($endingGl->keys())->unique() as $glKey) {
            [$partyId, $accountId] = explode(':', (string) $glKey, 2);
            if ($partyId !== 'none' && $parties->has((int) $partyId)) {
                continue;
            }
            $glOpening = (string) ($openingGl[$glKey] ?? '0');
            $glEnding = (string) ($endingGl[$glKey] ?? '0');
            if ($creditNormal) {
                $glOpening = bcmul($glOpening, '-1', 4);
                $glEnding = bcmul($glEnding, '-1', 4);
            }
            if (! $this->hasEvidence([$glOpening, $glEnding])) {
                continue;
            }
            $rows->push($this->balanceRow(
                'orphan-'.$glKey,
                __('reconciliation_center.rows.unassigned_gl', ['account' => $accountId]),
                '0',
                '0',
                $glOpening,
                $glEnding,
                is_numeric($accountId) ? (int) $accountId : null,
                $openingDate,
                $toDate,
            ));
        }

        return [$rows, $mappingConfigured];
    }

    private function customerSourceAt(int $companyId, int $branchId, string $cutoff): Collection
    {
        $invoices = DB::table('customer_invoices')
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNotNull('journal_entry_id')
            ->whereDate('invoice_date', '<=', $cutoff)
            ->where(fn (Builder $query): Builder => $query->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>', $cutoff))
            ->groupBy('customer_id')
            ->selectRaw("customer_id, sum(case when document_type = 'credit_note' then -total_amount * exchange_rate else total_amount * exchange_rate end) as balance")
            ->pluck('balance', 'customer_id');
        $receipts = DB::table('customer_receipts')
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNotNull('journal_entry_id')
            ->whereDate('receipt_date', '<=', $cutoff)
            ->where(fn (Builder $query): Builder => $query->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>', $cutoff))
            ->groupBy('customer_id')
            ->selectRaw('customer_id, sum(amount * exchange_rate) as balance')
            ->pluck('balance', 'customer_id');
        $refunds = Schema::hasTable('customer_credit_refunds')
            ? DB::table('customer_credit_refunds')->where('company_id', $companyId)->where('branch_id', $branchId)
                ->where('status', 'posted')->whereNotNull('journal_entry_id')->whereDate('refund_date', '<=', $cutoff)
                ->groupBy('customer_id')->selectRaw('customer_id, sum(amount * exchange_rate) as balance')->pluck('balance', 'customer_id')
            : collect();
        $opening = $this->openingBalanceDimensionAt($companyId, $branchId, $cutoff, 'customer_id');

        return $this->combineBalances($opening, $invoices)
            ->pipe(fn (Collection $balances): Collection => $this->combineBalances($balances, $receipts, subtractSecond: true))
            ->pipe(fn (Collection $balances): Collection => $this->combineBalances($balances, $refunds));
    }

    private function supplierSourceAt(int $companyId, int $branchId, string $cutoff): Collection
    {
        $invoices = DB::table('purchase_invoices')
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNotNull('journal_entry_id')
            ->whereDate('invoice_date', '<=', $cutoff)
            ->where(fn (Builder $query): Builder => $query->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>', $cutoff))
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, sum(total_amount * exchange_rate) as balance')
            ->pluck('balance', 'supplier_id');
        $payments = DB::table('supplier_payment_contexts')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNotNull('journal_entry_id')
            ->whereDate('payment_date', '<=', $cutoff)
            ->where(fn (Builder $query): Builder => $query->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>', $cutoff))
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, sum(amount * exchange_rate) as balance')
            ->pluck('balance', 'supplier_id');
        $returns = DB::table('purchase_returns')
            ->leftJoin('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_returns.purchase_invoice_id')
            ->whereNull('purchase_returns.deleted_at')
            ->where('purchase_returns.company_id', $companyId)
            ->where('purchase_returns.branch_id', $branchId)
            ->whereNotNull('purchase_returns.journal_entry_id')
            ->whereDate('purchase_returns.return_date', '<=', $cutoff)
            ->where(fn (Builder $query): Builder => $query->whereNull('purchase_returns.reversed_at')->orWhereDate('purchase_returns.reversed_at', '>', $cutoff))
            ->groupBy('purchase_returns.supplier_id')
            ->selectRaw('purchase_returns.supplier_id, sum(purchase_returns.total_amount * coalesce(purchase_invoices.exchange_rate, 1)) as balance')
            ->pluck('balance', 'purchase_returns.supplier_id');
        $opening = $this->openingBalanceDimensionAt($companyId, $branchId, $cutoff, 'supplier_id', creditNormal: true);

        return $this->combineBalances($opening, $invoices)
            ->pipe(fn (Collection $balances): Collection => $this->combineBalances($balances, $payments, subtractSecond: true))
            ->pipe(fn (Collection $balances): Collection => $this->combineBalances($balances, $returns, subtractSecond: true));
    }

    private function holderSourceAt(string $holder, int $companyId, int $branchId, string $cutoff): Collection
    {
        $balances = collect();
        if ($holder === 'cashbox') {
            DB::table('opening_balance_lines as line')
                ->join('opening_balances as opening', 'opening.id', '=', 'line.opening_balance_id')
                ->join('cashboxes as cashbox', 'cashbox.account_id', '=', 'line.account_id')
                ->where('opening.company_id', $companyId)->where('opening.status', 'approved')
                ->where('cashbox.company_id', $companyId)->where('cashbox.branch_id', $branchId)
                ->where('line.branch_id', $branchId)
                ->whereDate('opening.document_date', '<=', $cutoff)
                ->groupBy('cashbox.id')
                ->selectRaw('cashbox.id as holder_id, sum((line.debit_amount - line.credit_amount) * opening.exchange_rate) as balance')
                ->pluck('balance', 'holder_id')
                ->each(fn (mixed $value, mixed $id) => $balances[$id] = bcadd((string) $value, '0', 4));
            DB::table('cash_vouchers')
                ->whereNull('deleted_at')->where('company_id', $companyId)
                ->whereDate('voucher_date', '<=', $cutoff)
                ->where(fn (Builder $query): Builder => $query->where('status', 'approved')->orWhereDate('cancelled_at', '>', $cutoff))
                ->get(['cashbox_id', 'voucher_type', 'amount_base'])
                ->each(function (object $row) use ($balances): void {
                    $signed = $row->voucher_type === 'receipt' ? (string) $row->amount_base : bcmul((string) $row->amount_base, '-1', 4);
                    $balances[$row->cashbox_id] = bcadd((string) ($balances[$row->cashbox_id] ?? '0'), $signed, 4);
                });
        } else {
            DB::table('opening_balance_lines as line')
                ->join('opening_balances as opening', 'opening.id', '=', 'line.opening_balance_id')
                ->join('bank_accounts as bank', 'bank.id', '=', 'line.bank_account_id')
                ->where('opening.company_id', $companyId)->where('opening.status', 'approved')
                ->where('bank.company_id', $companyId)
                ->where('line.branch_id', $branchId)
                ->whereDate('opening.document_date', '<=', $cutoff)
                ->groupBy('bank.id')
                ->selectRaw('bank.id as holder_id, sum((line.debit_amount - line.credit_amount) * opening.exchange_rate) as balance')
                ->pluck('balance', 'holder_id')
                ->each(fn (mixed $value, mixed $id) => $balances[$id] = bcadd((string) $value, '0', 4));
            foreach ([
                ['customer_receipts', 'receipt_date', 'amount', 'bank_account_id', 1, 'approved', 'cancelled_at'],
                ['supplier_payment_contexts', 'payment_date', 'amount', 'bank_account_id', -1, 'approved', 'cancelled_at'],
                ['customer_credit_refunds', 'refund_date', 'amount', 'bank_account_id', -1, 'posted', null],
            ] as [$table, $date, $amount, $holderId, $sign, $status, $cancelledAt]) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->where('company_id', $companyId)->where('branch_id', $branchId)->where('status', $status)
                    ->whereNotNull($holderId)->whereNotNull('journal_entry_id')->whereDate($date, '<=', $cutoff)
                    ->when(in_array($table, ['customer_receipts', 'supplier_payment_contexts'], true), fn (Builder $query): Builder => $query->whereNull('cheque_id'))
                    ->when($cancelledAt !== null, fn (Builder $query): Builder => $query->where(fn (Builder $cancellation): Builder => $cancellation->whereNull($cancelledAt)->orWhereDate($cancelledAt, '>', $cutoff)))
                    ->get([$holderId, $amount, 'exchange_rate'])->each(function (object $row) use ($balances, $holderId, $amount, $sign): void {
                        $value = bcmul((string) $row->{$amount}, (string) ($row->exchange_rate ?? 1), 4);
                        $value = $sign < 0 ? bcmul($value, '-1', 4) : $value;
                        $balances[$row->{$holderId}] = bcadd((string) ($balances[$row->{$holderId}] ?? '0'), $value, 4);
                    });
            }

            DB::table('cheque_clearing_events as event')
                ->join('cheques as cheque', 'cheque.id', '=', 'event.cheque_id')
                ->where('cheque.company_id', $companyId)->whereNotNull('cheque.bank_account_id')
                ->whereNotNull('event.clearing_journal_entry_id')->whereDate('event.clearing_date', '<=', $cutoff)
                ->where(fn (Builder $query): Builder => $query->whereNull('event.reversed_at')->orWhereDate('event.reversed_at', '>', $cutoff))
                ->get(['cheque.bank_account_id', 'cheque.cheque_type', 'cheque.amount_base'])
                ->each(function (object $row) use ($balances): void {
                    $signed = $row->cheque_type === 'received'
                        ? (string) $row->amount_base
                        : bcmul((string) $row->amount_base, '-1', 4);
                    $balances[$row->bank_account_id] = bcadd((string) ($balances[$row->bank_account_id] ?? '0'), $signed, 4);
                });
        }

        DB::table('fund_transfers')->whereNull('deleted_at')->where('company_id', $companyId)
            ->whereDate('transfer_date', '<=', $cutoff)
            ->where(fn (Builder $query): Builder => $query->where('status', 'approved')->orWhereDate('cancelled_at', '>', $cutoff))
            ->get()->each(function (object $row) use ($balances, $holder): void {
                $sourceColumn = $holder === 'cashbox' ? 'source_cashbox_id' : 'source_bank_account_id';
                $targetColumn = $holder === 'cashbox' ? 'target_cashbox_id' : 'target_bank_account_id';
                if ($row->{$sourceColumn} !== null) {
                    $balances[$row->{$sourceColumn}] = bcsub((string) ($balances[$row->{$sourceColumn}] ?? '0'), (string) $row->source_amount_base, 4);
                }
                if ($row->{$targetColumn} !== null) {
                    $balances[$row->{$targetColumn}] = bcadd((string) ($balances[$row->{$targetColumn}] ?? '0'), (string) $row->target_amount_base, 4);
                }
            });

        return $balances;
    }

    private function openingBalanceDimensionAt(
        int $companyId,
        int $branchId,
        string $cutoff,
        string $dimension,
        bool $creditNormal = false,
    ): Collection {
        $balances = DB::table('opening_balance_lines as line')
            ->join('opening_balances as opening', 'opening.id', '=', 'line.opening_balance_id')
            ->where('opening.company_id', $companyId)
            ->where('opening.status', 'approved')
            ->whereNotNull('line.'.$dimension)
            ->where('line.branch_id', $branchId)
            ->whereDate('opening.document_date', '<=', $cutoff)
            ->groupBy('line.'.$dimension)
            ->selectRaw(
                'line.'.$dimension.' as dimension_id, sum((line.debit_amount - line.credit_amount) * opening.exchange_rate) as balance',
            )
            ->pluck('balance', 'dimension_id');

        return $creditNormal
            ? $balances->map(fn (mixed $balance): string => bcmul((string) $balance, '-1', 4))
            : $balances;
    }

    /** @param array<int, int> $productAccounts @param array<string, int> $specialAccounts */
    private function inventorySourceAt(
        int $companyId,
        int $branchId,
        string $cutoff,
        array $productAccounts,
        array $specialAccounts,
    ): Collection {
        $balances = collect();
        InventoryTransaction::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereDate('transaction_date', '<=', $cutoff)
            ->whereNotIn('stock_status', [InventoryTransaction::StatusProductionStaging, InventoryTransaction::StatusWip])
            ->groupBy('product_id', 'stock_status')
            ->selectRaw('product_id, stock_status, coalesce(sum(case when quantity_in > 0 then total_cost else -total_cost end), 0) as value')
            ->get()
            ->each(function (InventoryTransaction $row) use ($balances, $productAccounts, $specialAccounts): void {
                $accountId = $specialAccounts[$row->stock_status] ?? $productAccounts[$row->product_id] ?? null;
                if ($accountId !== null) {
                    $balances[$accountId] = bcadd((string) ($balances[$accountId] ?? '0'), (string) $row->value, 4);
                }
            });

        return $balances;
    }

    private function payrollSourceAt(int $companyId, string $cutoff): string
    {
        $value = DB::table('hr_payslip_items as item')
            ->join('hr_payslips as payslip', 'payslip.id', '=', 'item.payslip_id')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('period.company_id', $companyId)
            ->where('run.status', 'posted')
            ->where('item.direction', 'earning')
            ->whereDate('period.period_end', '<=', $cutoff)
            ->sum('item.amount');

        return bcadd((string) $value, '0', 4);
    }

    /** @return array{eligible: string, accounted: string} */
    private function overheadTotalsAt(int $companyId, int $financialPeriodId, int $branchId, string $cutoff): array
    {
        $row = DB::table('cost_overhead_allocation_runs')
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->where('branch_id', $branchId)
            ->where('status', 'posted')
            ->whereDate('to_date', '<=', $cutoff)
            ->selectRaw('coalesce(sum(eligible_cost), 0) as eligible, coalesce(sum(allocated_cost + unallocated_cost), 0) as accounted')
            ->first();

        return ['eligible' => (string) ($row?->eligible ?? 0), 'accounted' => (string) ($row?->accounted ?? 0)];
    }

    private function productionSourceAt(string $type, int $companyId, int $branchId, string $cutoff): string
    {
        if ($type === 'finished_goods') {
            return (string) InventoryTransaction::query()
                ->join('products', 'products.id', '=', 'inventory_transactions.product_id')
                ->where('inventory_transactions.company_id', $companyId)
                ->where('inventory_transactions.branch_id', $branchId)
                ->where('products.item_classification', Product::ClassificationFinishedProduct)
                ->whereNotIn('inventory_transactions.stock_status', [
                    InventoryTransaction::StatusProductionStaging,
                    InventoryTransaction::StatusWip,
                    InventoryTransaction::StatusQuarantine,
                    InventoryTransaction::StatusRework,
                ])
                ->whereDate('inventory_transactions.transaction_date', '<=', $cutoff)
                ->selectRaw('coalesce(sum(case when quantity_in > 0 then total_cost else -total_cost end), 0) as value')
                ->value('value');
        }

        if ($type === 'cogs') {
            return (string) InventoryTransaction::query()
                ->where('company_id', $companyId)->where('branch_id', $branchId)
                ->whereDate('transaction_date', '<=', $cutoff)
                ->whereIn('transaction_type', [InventoryDocument::TypeSalesDelivery, InventoryDocument::TypeSalesReturnReceipt])
                ->selectRaw('coalesce(sum(case when transaction_type = ? then total_cost else -total_cost end), 0) as value', [InventoryDocument::TypeSalesDelivery])
                ->value('value');
        }

        $documents = DB::table('inventory_document_lines as line')
            ->join('inventory_documents as document', 'document.id', '=', 'line.inventory_document_id')
            ->where('document.company_id', $companyId)->where('document.branch_id', $branchId)
            ->where('document.status', InventoryDocument::StatusPosted)->whereNotNull('document.production_run_id')
            ->whereNull('line.deleted_at')->whereDate('document.document_date', '<=', $cutoff)
            ->selectRaw(
                'coalesce(sum(case
                    when document.document_type in (?, ?) then line.total_cost
                    when document.document_type in (?, ?, ?) then -line.total_cost
                    else 0 end), 0) as value',
                [
                    InventoryDocument::TypeMaterialIssue,
                    InventoryDocument::TypeAdditionalMaterialIssue,
                    InventoryDocument::TypeMaterialReturn,
                    InventoryDocument::TypeProductionWaste,
                    InventoryDocument::TypeProductionReceipt,
                ],
            )->value('value');
        $overhead = Schema::hasTable('cost_overhead_allocation_runs')
            ? DB::table('cost_overhead_allocation_lines as line')
                ->join('cost_overhead_allocation_runs as run', 'run.id', '=', 'line.allocation_run_id')
                ->where('run.company_id', $companyId)->where('run.branch_id', $branchId)->where('run.status', 'posted')
                ->whereDate('run.to_date', '<=', $cutoff)->sum('line.allocated_amount')
            : 0;

        return bcadd((string) $documents, (string) $overhead, 4);
    }

    /** @return array{debit_side: string, credit_side: string, has_data: bool} */
    private function accountingEquationAt(int $companyId, int $branchId, string $cutoff): array
    {
        $rows = $this->glBaseQuery($companyId, $branchId, $cutoff)
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->whereIn('accounts.account_type', Account::accountTypes())
            ->groupBy('accounts.account_type')
            ->selectRaw('accounts.account_type, sum((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate) as balance')
            ->pluck('balance', 'account_type');
        $assets = bcadd((string) ($rows[Account::TypeAsset] ?? 0), '0', 4);
        $liabilities = bcmul((string) ($rows[Account::TypeLiability] ?? 0), '-1', 4);
        $equity = bcmul((string) ($rows[Account::TypeEquity] ?? 0), '-1', 4);
        $revenue = bcmul((string) ($rows[Account::TypeRevenue] ?? 0), '-1', 4);
        $expenses = bcadd((string) ($rows[Account::TypeExpense] ?? 0), '0', 4);

        return [
            'debit_side' => bcadd($assets, $expenses, 4),
            'credit_side' => bcadd(bcadd($liabilities, $equity, 4), $revenue, 4),
            'has_data' => $rows->isNotEmpty(),
        ];
    }

    /** @param list<int> $accountIds */
    private function glBalances(
        int $companyId,
        int $branchId,
        string $cutoff,
        array $accountIds,
        ?string $dimension = null,
        bool $creditNormal = false,
    ): Collection {
        if ($accountIds === []) {
            return collect();
        }

        $query = $this->glBaseQuery($companyId, $branchId, $cutoff)
            ->whereIn('journal_entry_lines.account_id', $accountIds);
        $columns = ['journal_entry_lines.account_id'];
        if ($dimension !== null) {
            $columns[] = 'journal_entry_lines.'.$dimension;
        }
        $rows = $query->groupBy($columns)
            ->selectRaw(
                'journal_entry_lines.account_id'.($dimension === null ? '' : ', journal_entry_lines.'.$dimension)
                .', sum((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate) as balance',
            )->get();

        return $rows->mapWithKeys(function (object $row) use ($dimension, $creditNormal): array {
            $dimensionKey = $dimension === null ? 'none' : ($row->{$dimension} ?? 'none');
            $balance = bcadd((string) $row->balance, '0', 4);

            return ["{$dimensionKey}:{$row->account_id}" => $creditNormal ? bcmul($balance, '-1', 4) : $balance];
        });
    }

    private function glBaseQuery(int $companyId, int $branchId, string $cutoff): Builder
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->whereDate('journal_entries.entry_date', '<=', $cutoff)
            ->where(function (Builder $query) use ($branchId): void {
                $query->where('journal_entry_lines.branch_id', $branchId)
                    ->orWhere(fn (Builder $fallback): Builder => $fallback->whereNull('journal_entry_lines.branch_id')->where('journal_entries.branch_id', $branchId));
            });
    }

    private function balanceRow(
        string $key,
        string $label,
        mixed $sourceOpening,
        mixed $sourceEnding,
        mixed $glOpening,
        mixed $glEnding,
        ?int $accountId = null,
        ?string $openingDate = null,
        ?string $toDate = null,
    ): array {
        $sourceOpening = bcadd((string) $sourceOpening, '0', 4);
        $sourceEnding = bcadd((string) $sourceEnding, '0', 4);
        $glOpening = bcadd((string) $glOpening, '0', 4);
        $glEnding = bcadd((string) $glEnding, '0', 4);
        $account = $accountId === null ? null : Account::withTrashed()->find($accountId);

        return [
            'key' => $key,
            'label' => $label,
            'source_opening' => $sourceOpening,
            'gl_opening' => $glOpening,
            'source_movement' => bcsub($sourceEnding, $sourceOpening, 4),
            'gl_movement' => bcsub($glEnding, $glOpening, 4),
            'source_ending' => $sourceEnding,
            'gl_ending' => $glEnding,
            'gl_url' => $account === null || $openingDate === null || $toDate === null
                ? null
                : route('admin.accounting.reports.account-ledger', [
                    'run' => 1,
                    'all_periods' => 1,
                    'account_doc_num' => $account->doc_num,
                    'from_date' => Carbon::parse($openingDate)->addDay()->toDateString(),
                    'to_date' => $toDate,
                    'branch_doc_num' => request('branch_doc_num'),
                ]),
        ];
    }

    private function combineBalances(Collection $first, Collection $second, bool $subtractSecond = false): Collection
    {
        $balances = $first->map(fn (mixed $value): string => bcadd((string) $value, '0', 4));
        foreach ($second as $key => $value) {
            $balances[$key] = $subtractSecond
                ? bcsub((string) ($balances[$key] ?? '0'), (string) $value, 4)
                : bcadd((string) ($balances[$key] ?? '0'), (string) $value, 4);
        }

        return $balances;
    }

    /** @param iterable<mixed> $values */
    private function hasEvidence(iterable $values): bool
    {
        return collect($values)->contains(fn (mixed $value): bool => $this->nonZero($value));
    }

    private function nonZero(mixed $value): bool
    {
        return is_numeric($value) && bccomp((string) $value, '0', 4) !== 0;
    }
}
