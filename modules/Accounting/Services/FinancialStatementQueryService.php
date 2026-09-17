<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Currency;
use Modules\Core\Services\NumericFormatService;

class FinancialStatementQueryService
{
    public const IncomeStatement = 'income_statement';

    public const FinancialPosition = 'financial_position';

    public const EquityChanges = 'equity_changes';

    public const CashFlowDirect = 'cash_flow_direct';

    public const CashFlowIndirect = 'cash_flow_indirect';

    public const ViewSummary = 'summary';

    public const ViewDetailed = 'detailed';

    /** @var list<string> */
    private const GrossRevenueClassifications = ['sales_revenue', 'service_revenue'];

    /** @var list<string> */
    private const ContraRevenueClassifications = ['sales_returns', 'sales_discounts'];

    /** @var list<string> */
    private const CostOfSalesClassifications = ['cost_of_goods_sold', 'production_services_expense'];

    /** @var list<string> */
    private const OtherIncomeClassifications = [
        'other_operating_revenue',
        'other_income',
        'scrap_sales_revenue',
        'foreign_exchange_gain',
        'gain_on_asset_disposal',
        'inventory_adjustment_gain',
    ];

    /** @var list<string> */
    private const FinanceCostClassifications = ['finance_cost', 'bank_charges', 'foreign_exchange_loss'];

    /** @var list<string> */
    private const IncomeTaxClassifications = ['income_tax_expense'];

    /** @var list<string> */
    private const CashEquivalentClassifications = ['cash', 'bank', 'cash_in_transit'];

    /** @var list<string> */
    private const ExchangeEffectClassifications = ['foreign_exchange_gain', 'foreign_exchange_loss'];

    /** @var list<string> */
    private const AmbiguousCashFlowClassifications = ['finance_cost'];

    /** @var list<string> */
    private const InvestingClassifications = [
        'fixed_assets',
        'accumulated_depreciation',
        'construction_in_progress',
        'machinery_equipment',
        'molds_tooling',
        'vehicles',
        'it_office_equipment',
        'furniture_fixtures',
        'land',
        'buildings',
        'electrical_equipment',
    ];

    /** @var list<string> */
    private const FinancingClassifications = [
        'capital',
        'retained_earnings',
        'current_year_result',
        'legal_reserve',
        'general_reserve',
        'other_reserves',
        'loans_payable',
        'current_loans_payable',
        'noncurrent_loans_payable',
        'current_lease_liabilities',
        'noncurrent_lease_liabilities',
    ];

    /** @var list<string> */
    private const WorkingCapitalAssetClassifications = [
        'accounts_receivable',
        'allowance_for_doubtful_accounts',
        'inventory',
        'raw_material_inventory',
        'packaging_material_inventory',
        'printing_ink_inventory',
        'spare_parts_inventory',
        'operating_supplies_inventory',
        'work_in_process_inventory',
        'semi_finished_goods_inventory',
        'finished_goods_inventory',
        'goods_in_transit_inventory',
        'scrap_waste_inventory',
        'quarantine_inventory',
        'rework_inventory',
        'inventory_allowance',
        'prepaid_expenses',
        'other_receivables',
        'recoverable_vat',
        'withholding_tax_receivable',
        'supplier_advances',
        'employee_advances',
    ];

    /** @var list<string> */
    private const WorkingCapitalLiabilityClassifications = [
        'accounts_payable',
        'goods_received_not_invoiced',
        'customer_advances',
        'tax_payable',
        'output_vat_payable',
        'withholding_tax_payable',
        'payroll_tax_payable',
        'corporate_income_tax_payable',
        'payroll_payable',
        'accrued_expenses',
        'social_insurance_payable',
        'other_current_liabilities',
    ];

    public function __construct(private readonly NumericFormatService $numbers) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $type = in_array($filters['statement_type'] ?? null, self::types(), true)
            ? $filters['statement_type']
            : self::IncomeStatement;
        $viewMode = ($filters['view_mode'] ?? null) === self::ViewDetailed
            ? self::ViewDetailed
            : self::ViewSummary;
        $filters = [...$filters, 'statement_type' => $type, 'view_mode' => $viewMode];
        $current = $this->statement($type, $filters, 'from_date', 'to_date', $viewMode);
        $comparison = null;

        if (filled($filters['comparison_from_date'] ?? null) && filled($filters['comparison_to_date'] ?? null)) {
            $comparison = $this->statement($type, $filters, 'comparison_from_date', 'comparison_to_date', $viewMode);
            $current = $this->mergeComparison($current, $comparison);
        }

        $scopeIsPartial = ($filters['branch_id'] ?? null) !== null || ($filters['cost_center_id'] ?? null) !== null;

        return [
            'statement_type' => $type,
            'view_mode' => $viewMode,
            'currency' => Currency::query()
                ->forCompany((int) $filters['company_id'])
                ->active()
                ->where('is_main', true)
                ->first(['doc_num', 'code', 'name'])?->only(['doc_num', 'code', 'name']),
            'filters' => $filters,
            'rows' => $current['rows'],
            'summary' => $current['summary'],
            'cash_components' => $current['cash_components'] ?? [],
            'comparison' => $comparison,
            'classification_warnings' => $current['classification_warnings'],
            'classification_complete' => $current['classification_warnings'] === [],
            'scope_is_partial' => $scopeIsPartial,
            'generated_at' => now(),
            'generated_by' => auth()->user()?->name,
        ];
    }

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::IncomeStatement,
            self::FinancialPosition,
            self::EquityChanges,
            self::CashFlowDirect,
            self::CashFlowIndirect,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function statement(string $type, array $filters, string $fromKey, string $toKey, string $viewMode): array
    {
        return match ($type) {
            self::FinancialPosition => $this->financialPosition($filters, (string) $filters[$toKey], $viewMode),
            self::EquityChanges => $this->equityChanges($filters, (string) $filters[$fromKey], (string) $filters[$toKey], $viewMode),
            self::CashFlowDirect => $this->cashFlow($filters, (string) $filters[$fromKey], (string) $filters[$toKey], direct: true),
            self::CashFlowIndirect => $this->cashFlow($filters, (string) $filters[$fromKey], (string) $filters[$toKey], direct: false),
            default => $this->incomeStatement($filters, (string) $filters[$fromKey], (string) $filters[$toKey], $viewMode),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function incomeStatement(array $filters, string $fromDate, string $toDate, string $viewMode): array
    {
        $accounts = $this->accounts($filters, [Account::TypeRevenue, Account::TypeExpense]);
        $movements = $this->movements($filters, $accounts->modelKeys(), $fromDate, $toDate, excludeClosing: true);
        $categories = [
            'gross_revenue' => [],
            'sales_returns_discounts' => [],
            'cost_of_sales' => [],
            'operating_expenses' => [],
            'other_income' => [],
            'finance_costs' => [],
            'income_tax' => [],
            'unclassified_revenue' => [],
            'unclassified_expense' => [],
        ];
        $warnings = [];

        foreach ($accounts as $account) {
            $movement = $movements[(int) $account->getKey()] ?? $this->zeroMovement();

            if (! $this->movementHasValue($movement)) {
                continue;
            }

            $classification = (string) ($account->classification?->code ?? '');
            $category = $this->incomeCategory($account->account_type, $classification);
            $amount = $this->incomeAmount($account->account_type, $category, $movement);
            $categories[$category][] = $this->accountRow($account, $amount, $category);

            if (str_starts_with($category, 'unclassified_')) {
                $warnings[] = $account->codeNameLabel();
            }
        }

        $totals = collect($categories)->map(fn (array $rows): string => $this->sumRows($rows))->all();
        $netRevenue = bcsub($totals['gross_revenue'], $totals['sales_returns_discounts'], 4);
        $grossProfit = bcsub($netRevenue, $totals['cost_of_sales'], 4);
        $operatingExpenses = bcadd($totals['operating_expenses'], $totals['unclassified_expense'], 4);
        $otherIncome = bcadd($totals['other_income'], $totals['unclassified_revenue'], 4);
        $profitBeforeTax = bcsub(
            bcadd($grossProfit, $otherIncome, 4),
            bcadd($operatingExpenses, $totals['finance_costs'], 4),
            4,
        );
        $periodResult = bcsub($profitBeforeTax, $totals['income_tax'], 4);
        $summary = [
            'gross_revenue' => $totals['gross_revenue'],
            'sales_returns_discounts' => $totals['sales_returns_discounts'],
            'net_revenue' => $netRevenue,
            'cost_of_sales' => $totals['cost_of_sales'],
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'other_income' => $otherIncome,
            'finance_costs' => $totals['finance_costs'],
            'profit_before_tax' => $profitBeforeTax,
            'income_tax' => $totals['income_tax'],
            'period_result' => $periodResult,
        ];
        $rows = [];

        foreach (['gross_revenue', 'sales_returns_discounts'] as $category) {
            $rows = [...$rows, ...$this->categoryRows($category, $categories[$category], $totals[$category], $viewMode)];
        }

        $rows[] = $this->totalRow('net_revenue', $netRevenue);
        $rows = [...$rows, ...$this->categoryRows('cost_of_sales', $categories['cost_of_sales'], $totals['cost_of_sales'], $viewMode)];
        $rows[] = $this->totalRow('gross_profit', $grossProfit);

        foreach (['operating_expenses', 'unclassified_expense', 'other_income', 'unclassified_revenue', 'finance_costs'] as $category) {
            $rows = [...$rows, ...$this->categoryRows($category, $categories[$category], $totals[$category], $viewMode)];
        }

        $rows[] = $this->totalRow('profit_before_tax', $profitBeforeTax);
        $rows = [...$rows, ...$this->categoryRows('income_tax', $categories['income_tax'], $totals['income_tax'], $viewMode)];
        $rows[] = $this->totalRow('period_result', $periodResult, 'grand_total');

        return [
            'rows' => $rows,
            'summary' => $summary,
            'classification_warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function financialPosition(array $filters, string $asOfDate, string $viewMode): array
    {
        $accounts = $this->accounts($filters, [Account::TypeAsset, Account::TypeLiability, Account::TypeEquity]);
        $balances = $this->movements($filters, $accounts->modelKeys(), null, $asOfDate);
        $categories = ['assets' => [], 'liabilities' => [], 'equity' => []];

        foreach ($accounts as $account) {
            $movement = $balances[(int) $account->getKey()] ?? $this->zeroMovement();
            $amount = $account->account_type === Account::TypeAsset
                ? bcsub($movement['debit'], $movement['credit'], 4)
                : bcsub($movement['credit'], $movement['debit'], 4);

            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }

            $category = match ($account->account_type) {
                Account::TypeLiability => 'liabilities',
                Account::TypeEquity => 'equity',
                default => 'assets',
            };
            $categories[$category][] = $this->accountRow($account, $amount, $category);
        }

        $unclosedResult = $this->unclosedIncomeResult($filters, $asOfDate);
        $totals = collect($categories)->map(fn (array $rows): string => $this->sumRows($rows))->all();
        $totalEquity = bcadd($totals['equity'], $unclosedResult, 4);
        $liabilitiesAndEquity = bcadd($totals['liabilities'], $totalEquity, 4);
        $difference = bcsub($totals['assets'], $liabilitiesAndEquity, 4);
        $summary = [
            'assets' => $totals['assets'],
            'liabilities' => $totals['liabilities'],
            'ledger_equity' => $totals['equity'],
            'unclosed_period_result' => $unclosedResult,
            'equity' => $totalEquity,
            'liabilities_and_equity' => $liabilitiesAndEquity,
            'difference' => $difference,
            'is_balanced' => bccomp($difference, '0', 4) === 0,
        ];
        $rows = [];

        foreach (['assets', 'liabilities', 'equity'] as $category) {
            $rows = [...$rows, ...$this->categoryRows($category, $categories[$category], $totals[$category], $viewMode)];

            if ($category === 'equity' && bccomp($unclosedResult, '0', 4) !== 0) {
                $rows[] = $this->totalRow('unclosed_period_result', $unclosedResult, 'calculated');
                $rows[] = $this->totalRow('total_equity', $totalEquity);
            }
        }

        $rows[] = $this->totalRow('liabilities_and_equity', $liabilitiesAndEquity, 'grand_total');
        $rows[] = $this->totalRow('statement_difference', $difference, 'check');

        return [
            'rows' => $rows,
            'summary' => $summary,
            'classification_warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function equityChanges(array $filters, string $fromDate, string $toDate, string $viewMode): array
    {
        $accounts = $this->accounts($filters, [Account::TypeEquity]);
        $opening = $this->movements($filters, $accounts->modelKeys(), null, $this->previousDate($fromDate));
        $period = $this->movements($filters, $accounts->modelKeys(), $fromDate, $toDate, closingMode: 'exclude');
        $closingTransfers = $this->movements($filters, $accounts->modelKeys(), $fromDate, $toDate, closingMode: 'only');
        $ending = $this->movements($filters, $accounts->modelKeys(), null, $toDate);
        $rows = [];
        $openingTotal = '0.0000';
        $increaseTotal = '0.0000';
        $decreaseTotal = '0.0000';
        $ledgerEndingTotal = '0.0000';
        $closingTransferTotal = '0.0000';

        foreach ($accounts as $account) {
            $accountId = (int) $account->getKey();
            $openingAmount = $this->creditBalance($opening[$accountId] ?? $this->zeroMovement());
            $increase = ($period[$accountId] ?? $this->zeroMovement())['credit'];
            $decrease = ($period[$accountId] ?? $this->zeroMovement())['debit'];
            $closingTransfer = $this->creditBalance($closingTransfers[$accountId] ?? $this->zeroMovement());
            $endingAmount = $this->creditBalance($ending[$accountId] ?? $this->zeroMovement());

            if ($viewMode === self::ViewDetailed && collect([$openingAmount, $increase, $decrease, $closingTransfer, $endingAmount])
                ->contains(fn (string $amount): bool => bccomp($amount, '0', 4) !== 0)) {
                $rows[] = [
                    'key' => 'account_'.$accountId,
                    'label' => $account->codeNameLabel(),
                    'row_type' => 'account',
                    'opening' => $openingAmount,
                    'increases' => $increase,
                    'decreases' => $decrease,
                    'period_result' => $closingTransfer,
                    'amount' => $endingAmount,
                    'account_doc_num' => (string) $account->doc_num,
                ];
            }

            $openingTotal = bcadd($openingTotal, $openingAmount, 4);
            $increaseTotal = bcadd($increaseTotal, $increase, 4);
            $decreaseTotal = bcadd($decreaseTotal, $decrease, 4);
            $closingTransferTotal = bcadd($closingTransferTotal, $closingTransfer, 4);
            $ledgerEndingTotal = bcadd($ledgerEndingTotal, $endingAmount, 4);
        }

        $unclosedResult = $this->unclosedIncomeResult($filters, $toDate);
        $periodResult = bcadd($closingTransferTotal, $unclosedResult, 4);
        $endingTotal = bcadd($ledgerEndingTotal, $unclosedResult, 4);
        $summary = [
            'opening_equity' => $openingTotal,
            'increases' => $increaseTotal,
            'decreases' => $decreaseTotal,
            'period_result' => $periodResult,
            'closing_equity' => $endingTotal,
        ];
        $rows[] = [
            'key' => 'equity_total',
            'label_key' => 'equity_total',
            'row_type' => 'grand_total',
            'opening' => $openingTotal,
            'increases' => $increaseTotal,
            'decreases' => $decreaseTotal,
            'period_result' => $periodResult,
            'amount' => $endingTotal,
        ];

        return [
            'rows' => $rows,
            'summary' => $summary,
            'classification_warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function cashFlow(array $filters, string $fromDate, string $toDate, bool $direct): array
    {
        $flows = $this->directCashFlows($filters, $fromDate, $toDate);
        $income = $this->incomeStatement($filters, $fromDate, $toDate, self::ViewSummary);
        $periodResult = (string) $income['summary']['period_result'];
        $nonCashAdjustments = $this->nonCashAdjustments($filters, $fromDate, $toDate);
        $workingCapitalChanges = $this->workingCapitalAdjustment($filters, $fromDate, $toDate);
        $indirectOperating = bcadd(bcadd($periodResult, $nonCashAdjustments, 4), $workingCapitalChanges, 4);
        $directOperating = $flows['operating']['net'];
        $operating = $direct ? $directOperating : $indirectOperating;
        $beginningCash = $this->cashBalance($filters, $this->previousDate($fromDate));
        $endingCash = $this->cashBalance($filters, $toDate);
        $netChange = bcadd(
            bcadd($directOperating, $flows['investing']['net'], 4),
            bcadd(
                bcadd($flows['financing']['net'], $flows['exchange_effect']['net'], 4),
                $flows['unclassified']['net'],
                4,
            ),
            4,
        );
        $expectedEnding = bcadd($beginningCash, $netChange, 4);
        $equationDifference = bcsub($endingCash, $expectedEnding, 4);
        $operatingDifference = bcsub($directOperating, $indirectOperating, 4);
        $summary = [
            'period_result' => $periodResult,
            'non_cash_adjustments' => $nonCashAdjustments,
            'working_capital_changes' => $workingCapitalChanges,
            'direct_operating' => $directOperating,
            'indirect_operating' => $indirectOperating,
            'operating_difference' => $operatingDifference,
            'operating' => $operating,
            'investing' => $flows['investing']['net'],
            'financing' => $flows['financing']['net'],
            'unclassified' => $flows['unclassified']['net'],
            'exchange_effect' => $flows['exchange_effect']['net'],
            'net_change' => $netChange,
            'beginning_cash' => $beginningCash,
            'ending_cash' => $endingCash,
            'equation_difference' => $equationDifference,
            'is_reconciled' => bccomp($equationDifference, '0', 4) === 0
                && bccomp($operatingDifference, '0', 4) === 0
                && bccomp($flows['unclassified']['net'], '0', 4) === 0,
        ];
        $cashComponents = $this->cashComponents($filters, $fromDate, $toDate);
        $rows = $direct
            ? [
                $this->totalRow('operating_receipts', $flows['operating']['receipts'], 'subtotal'),
                $this->totalRow('operating_payments', bcmul($flows['operating']['payments'], '-1', 4), 'subtotal'),
                $this->totalRow('net_operating_cash_flow', $directOperating, 'total'),
                $this->totalRow('investing_receipts', $flows['investing']['receipts'], 'subtotal'),
                $this->totalRow('investing_payments', bcmul($flows['investing']['payments'], '-1', 4), 'subtotal'),
                $this->totalRow('net_investing_cash_flow', $flows['investing']['net'], 'total'),
                $this->totalRow('financing_receipts', $flows['financing']['receipts'], 'subtotal'),
                $this->totalRow('financing_payments', bcmul($flows['financing']['payments'], '-1', 4), 'subtotal'),
                $this->totalRow('net_financing_cash_flow', $flows['financing']['net'], 'total'),
                $this->totalRow('exchange_effect', $flows['exchange_effect']['net'], 'subtotal'),
            ]
            : [
                $this->totalRow('period_result', $periodResult, 'subtotal'),
                $this->totalRow('non_cash_adjustments', $nonCashAdjustments, 'subtotal'),
                $this->totalRow('working_capital_changes', $workingCapitalChanges, 'subtotal'),
                $this->totalRow('net_operating_cash_flow', $indirectOperating, 'total'),
                $this->totalRow('net_investing_cash_flow', $flows['investing']['net'], 'total'),
                $this->totalRow('net_financing_cash_flow', $flows['financing']['net'], 'total'),
                $this->totalRow('exchange_effect', $flows['exchange_effect']['net'], 'subtotal'),
            ];

        if (bccomp($flows['unclassified']['net'], '0', 4) !== 0) {
            $rows[] = $this->totalRow('unclassified_cash_flow', $flows['unclassified']['net'], 'check');
        }

        $rows[] = $this->totalRow('net_cash_change', $netChange, 'grand_total');
        $rows[] = $this->totalRow('beginning_cash', $beginningCash, 'calculated');
        $rows[] = $this->totalRow('ending_cash', $endingCash, 'grand_total');
        $rows[] = $this->totalRow('cash_flow_equation_difference', $equationDifference, 'check');
        $rows[] = $this->totalRow('operating_method_difference', $operatingDifference, 'check');

        return [
            'rows' => $rows,
            'summary' => $summary,
            'cash_components' => $cashComponents,
            'classification_warnings' => array_values(array_unique([
                ...$income['classification_warnings'],
                ...$flows['warnings'],
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function directCashFlows(array $filters, string $fromDate, string $toDate): array
    {
        $cashAccountIds = Account::query()
            ->withTrashed()
            ->forCompany((int) $filters['company_id'])
            ->whereHas('classification', fn ($query) => $query->whereIn('code', self::CashEquivalentClassifications))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $totals = [
            'operating' => $this->zeroFlow(),
            'investing' => $this->zeroFlow(),
            'financing' => $this->zeroFlow(),
            'exchange_effect' => $this->zeroFlow(),
            'unclassified' => $this->zeroFlow(),
        ];
        $warnings = [];

        if ($cashAccountIds === []) {
            return [...$totals, 'warnings' => [__('financial_statements.messages.cash_policy_missing')]];
        }

        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->leftJoin('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $filters['company_id'])
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->whereDate('journal_entries.entry_date', '>=', $fromDate)
            ->whereDate('journal_entries.entry_date', '<=', $toDate)
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId): Builder => $query->where(function (Builder $query) use ($branchId): void {
                $query->where('journal_entry_lines.branch_id', $branchId)
                    ->orWhere(function (Builder $query) use ($branchId): void {
                        $query->whereNull('journal_entry_lines.branch_id')
                            ->where('journal_entries.branch_id', $branchId);
                    });
            }))
            ->when($filters['cost_center_id'] ?? null, fn (Builder $query, int $costCenterId): Builder => $query->where('journal_entry_lines.cost_center_id', $costCenterId))
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.id')
            ->select([
                'journal_entries.id as entry_id',
                'journal_entries.doc_num as entry_doc_num',
                'journal_entries.exchange_rate',
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit_amount',
                'journal_entry_lines.credit_amount',
                'accounts.account_code',
                'accounts.name',
                'accounts.name_en',
                'accounts.account_type',
                'account_classifications.code as classification_code',
            ])
            ->cursor();
        $entryId = null;
        $entry = [];
        $flush = function () use (&$entry, &$totals, &$warnings): void {
            if (($entry['has_cash'] ?? false) !== true) {
                $entry = [];

                return;
            }

            foreach ($entry['counterparts'] ?? [] as $counterpart) {
                $category = $counterpart['category'];
                $amount = $counterpart['amount'];

                if (bccomp($amount, '0', 4) >= 0) {
                    $totals[$category]['receipts'] = bcadd($totals[$category]['receipts'], $amount, 4);
                } else {
                    $totals[$category]['payments'] = bcadd($totals[$category]['payments'], bcmul($amount, '-1', 4), 4);
                }

                if ($category === 'unclassified') {
                    $warnings[] = $counterpart['label'].' / '.($entry['doc_num'] ?? '');
                }
            }

            $entry = [];
        };

        foreach ($rows as $row) {
            if ($entryId !== null && $entryId !== (int) $row->entry_id) {
                $flush();
            }

            $entryId = (int) $row->entry_id;
            $entry['doc_num'] = (string) $row->entry_doc_num;
            $debit = $this->baseAmount($row->debit_amount, $row->exchange_rate);
            $credit = $this->baseAmount($row->credit_amount, $row->exchange_rate);

            if (in_array((int) $row->account_id, $cashAccountIds, true)) {
                $entry['has_cash'] = true;

                continue;
            }

            $entry['counterparts'][] = [
                'category' => $this->cashFlowCategory((string) $row->account_type, (string) ($row->classification_code ?? '')),
                'amount' => bcsub($credit, $debit, 4),
                'label' => Account::codeNameLabelFor($row->account_code, $row->name, $row->name_en),
            ];
        }

        if ($entryId !== null) {
            $flush();
        }

        foreach (array_keys($totals) as $category) {
            $totals[$category]['net'] = bcsub($totals[$category]['receipts'], $totals[$category]['payments'], 4);
        }

        return [...$totals, 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $filters */
    private function nonCashAdjustments(array $filters, string $fromDate, string $toDate): string
    {
        $accounts = $this->accounts($filters, [Account::TypeRevenue, Account::TypeExpense]);
        $movements = $this->movements($filters, $accounts->modelKeys(), $fromDate, $toDate, excludeClosing: true);
        $total = '0.0000';

        foreach ($accounts as $account) {
            $classification = (string) ($account->classification?->code ?? '');
            $movement = $movements[(int) $account->getKey()] ?? $this->zeroMovement();

            if (in_array($classification, ['depreciation_expense', 'factory_depreciation_expense'], true)) {
                $total = bcadd($total, bcsub($movement['debit'], $movement['credit'], 4), 4);
            } elseif (in_array($classification, ['gain_on_asset_disposal', 'foreign_exchange_gain'], true)) {
                $total = bcsub($total, bcsub($movement['credit'], $movement['debit'], 4), 4);
            } elseif (in_array($classification, ['loss_on_asset_disposal', 'foreign_exchange_loss'], true)) {
                $total = bcadd($total, bcsub($movement['debit'], $movement['credit'], 4), 4);
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $filters */
    private function workingCapitalAdjustment(array $filters, string $fromDate, string $toDate): string
    {
        $accounts = $this->accounts($filters, [Account::TypeAsset, Account::TypeLiability])
            ->filter(fn (Account $account): bool => in_array((string) ($account->classification?->code ?? ''), [
                ...self::WorkingCapitalAssetClassifications,
                ...self::WorkingCapitalLiabilityClassifications,
            ], true))
            ->values();
        $opening = $this->movements($filters, $accounts->modelKeys(), null, $this->previousDate($fromDate));
        $ending = $this->movements($filters, $accounts->modelKeys(), null, $toDate);
        $adjustment = '0.0000';

        foreach ($accounts as $account) {
            $accountId = (int) $account->getKey();
            $classification = (string) ($account->classification?->code ?? '');

            if (in_array($classification, self::WorkingCapitalAssetClassifications, true)) {
                $openingBalance = bcsub(($opening[$accountId] ?? $this->zeroMovement())['debit'], ($opening[$accountId] ?? $this->zeroMovement())['credit'], 4);
                $endingBalance = bcsub(($ending[$accountId] ?? $this->zeroMovement())['debit'], ($ending[$accountId] ?? $this->zeroMovement())['credit'], 4);
                $adjustment = bcsub($adjustment, bcsub($endingBalance, $openingBalance, 4), 4);
            } else {
                $openingBalance = $this->creditBalance($opening[$accountId] ?? $this->zeroMovement());
                $endingBalance = $this->creditBalance($ending[$accountId] ?? $this->zeroMovement());
                $adjustment = bcadd($adjustment, bcsub($endingBalance, $openingBalance, 4), 4);
            }
        }

        return $adjustment;
    }

    /** @param array<string, mixed> $filters */
    private function cashBalance(array $filters, string $asOfDate): string
    {
        $accounts = $this->cashAccounts($filters);
        $balances = $this->movements($filters, $accounts->modelKeys(), null, $asOfDate);

        return array_reduce(
            $balances,
            fn (string $total, array $movement): string => bcadd($total, bcsub($movement['debit'], $movement['credit'], 4), 4),
            '0.0000',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{account_doc_num: string, label: string, opening: string, ending: string, change: string}>
     */
    private function cashComponents(array $filters, string $fromDate, string $toDate): array
    {
        $accounts = $this->cashAccounts($filters);
        $opening = $this->movements($filters, $accounts->modelKeys(), null, $this->previousDate($fromDate));
        $ending = $this->movements($filters, $accounts->modelKeys(), null, $toDate);
        $rows = [];

        foreach ($accounts as $account) {
            $accountId = (int) $account->getKey();
            $openingAmount = bcsub(
                ($opening[$accountId] ?? $this->zeroMovement())['debit'],
                ($opening[$accountId] ?? $this->zeroMovement())['credit'],
                4,
            );
            $endingAmount = bcsub(
                ($ending[$accountId] ?? $this->zeroMovement())['debit'],
                ($ending[$accountId] ?? $this->zeroMovement())['credit'],
                4,
            );

            if (bccomp($openingAmount, '0', 4) === 0 && bccomp($endingAmount, '0', 4) === 0) {
                continue;
            }

            $rows[] = [
                'account_doc_num' => (string) $account->doc_num,
                'label' => $account->codeNameLabel(),
                'opening' => $openingAmount,
                'ending' => $endingAmount,
                'change' => bcsub($endingAmount, $openingAmount, 4),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    private function cashAccounts(array $filters): Collection
    {
        return Account::query()
            ->withTrashed()
            ->forCompany((int) $filters['company_id'])
            ->whereHas('classification', fn ($query) => $query->whereIn('code', self::CashEquivalentClassifications))
            ->orderBy('account_code')
            ->get(['id', 'doc_num', 'account_code', 'name', 'name_en']);
    }

    private function cashFlowCategory(string $accountType, string $classification): string
    {
        if (in_array($classification, self::ExchangeEffectClassifications, true)) {
            return 'exchange_effect';
        }

        if (in_array($classification, self::AmbiguousCashFlowClassifications, true)) {
            return 'unclassified';
        }

        if (in_array($classification, [
            ...self::InvestingClassifications,
            'capital_expenditure_payables',
            'gain_on_asset_disposal',
            'loss_on_asset_disposal',
        ], true)) {
            return 'investing';
        }

        if (in_array($classification, self::FinancingClassifications, true)) {
            return 'financing';
        }

        if ($accountType === Account::TypeRevenue
            || $accountType === Account::TypeExpense
            || in_array($classification, [
                ...self::WorkingCapitalAssetClassifications,
                ...self::WorkingCapitalLiabilityClassifications,
            ], true)) {
            return 'operating';
        }

        return 'unclassified';
    }

    /** @return array{receipts: string, payments: string, net: string} */
    private function zeroFlow(): array
    {
        return ['receipts' => '0.0000', 'payments' => '0.0000', 'net' => '0.0000'];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $accountTypes
     * @return Collection<int, Account>
     */
    private function accounts(array $filters, array $accountTypes): Collection
    {
        return Account::query()
            ->withTrashed()
            ->with('classification:id,code')
            ->forCompany((int) $filters['company_id'])
            ->whereIn('account_type', $accountTypes)
            ->orderBy('account_code')
            ->get([
                'id',
                'doc_num',
                'account_code',
                'name',
                'name_en',
                'account_classification_id',
                'account_type',
                'normal_balance',
                'status',
                'deleted_at',
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $accountIds
     * @return array<int, array{debit: string, credit: string}>
     */
    private function movements(
        array $filters,
        array $accountIds,
        ?string $fromDate,
        string $toDate,
        bool $excludeClosing = false,
        string $closingMode = 'all',
    ): array {
        if ($accountIds === []) {
            return [];
        }

        $balances = [];
        $query = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $filters['company_id'])
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->whereDate('journal_entries.entry_date', '<=', $toDate)
            ->when($fromDate !== null, fn (Builder $query): Builder => $query->whereDate('journal_entries.entry_date', '>=', $fromDate))
            ->when($excludeClosing || $closingMode === 'exclude', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                ->whereNull('journal_entries.source_type')
                ->orWhere('journal_entries.source_type', 'not like', 'period_closing%')))
            ->when($closingMode === 'only', fn (Builder $query): Builder => $query->where('journal_entries.source_type', 'like', 'period_closing%'))
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId): Builder => $query->where(function (Builder $query) use ($branchId): void {
                $query->where('journal_entry_lines.branch_id', $branchId)
                    ->orWhere(function (Builder $query) use ($branchId): void {
                        $query->whereNull('journal_entry_lines.branch_id')
                            ->where('journal_entries.branch_id', $branchId);
                    });
            }))
            ->when($filters['cost_center_id'] ?? null, fn (Builder $query, int $costCenterId): Builder => $query->where('journal_entry_lines.cost_center_id', $costCenterId))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->select([
                'journal_entry_lines.account_id',
                'journal_entry_lines.debit_amount',
                'journal_entry_lines.credit_amount',
                'journal_entries.exchange_rate',
            ]);

        foreach ($query->cursor() as $row) {
            $accountId = (int) $row->account_id;
            $balances[$accountId] ??= $this->zeroMovement();
            $balances[$accountId]['debit'] = bcadd(
                $balances[$accountId]['debit'],
                $this->baseAmount($row->debit_amount, $row->exchange_rate),
                4,
            );
            $balances[$accountId]['credit'] = bcadd(
                $balances[$accountId]['credit'],
                $this->baseAmount($row->credit_amount, $row->exchange_rate),
                4,
            );
        }

        return $balances;
    }

    /** @param array{debit: string, credit: string} $movement */
    private function incomeAmount(string $accountType, string $category, array $movement): string
    {
        if ($category === 'sales_returns_discounts') {
            return bcsub($movement['debit'], $movement['credit'], 4);
        }

        return $accountType === Account::TypeRevenue
            ? bcsub($movement['credit'], $movement['debit'], 4)
            : bcsub($movement['debit'], $movement['credit'], 4);
    }

    private function incomeCategory(string $accountType, string $classification): string
    {
        if ($accountType === Account::TypeRevenue) {
            return match (true) {
                in_array($classification, self::GrossRevenueClassifications, true) => 'gross_revenue',
                in_array($classification, self::ContraRevenueClassifications, true) => 'sales_returns_discounts',
                in_array($classification, self::OtherIncomeClassifications, true) => 'other_income',
                default => 'unclassified_revenue',
            };
        }

        return match (true) {
            in_array($classification, self::CostOfSalesClassifications, true) => 'cost_of_sales',
            in_array($classification, self::FinanceCostClassifications, true) => 'finance_costs',
            in_array($classification, self::IncomeTaxClassifications, true) => 'income_tax',
            $classification === '' || $classification === 'expenses' => 'unclassified_expense',
            default => 'operating_expenses',
        };
    }

    /** @return array<string, mixed> */
    private function accountRow(Account $account, string $amount, string $category): array
    {
        return [
            'key' => 'account_'.$account->getKey(),
            'category' => $category,
            'label' => $account->codeNameLabel(),
            'account_doc_num' => (string) $account->doc_num,
            'row_type' => 'account',
            'amount' => $amount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return list<array<string, mixed>>
     */
    private function categoryRows(string $key, array $accounts, string $total, string $viewMode): array
    {
        if ($accounts === [] && bccomp($total, '0', 4) === 0) {
            return [];
        }

        return [
            [
                'key' => $key,
                'label_key' => $key,
                'row_type' => 'subtotal',
                'amount' => $total,
            ],
            ...($viewMode === self::ViewDetailed ? $accounts : []),
        ];
    }

    /** @return array<string, mixed> */
    private function totalRow(string $key, string $amount, string $type = 'total'): array
    {
        return [
            'key' => $key,
            'label_key' => $key,
            'row_type' => $type,
            'amount' => $amount,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumRows(array $rows): string
    {
        return array_reduce(
            $rows,
            fn (string $total, array $row): string => bcadd($total, (string) $row['amount'], 4),
            '0.0000',
        );
    }

    /** @param array{debit: string, credit: string} $movement */
    private function movementHasValue(array $movement): bool
    {
        return bccomp($movement['debit'], '0', 4) !== 0 || bccomp($movement['credit'], '0', 4) !== 0;
    }

    /** @return array{debit: string, credit: string} */
    private function zeroMovement(): array
    {
        return ['debit' => '0.0000', 'credit' => '0.0000'];
    }

    /** @param array{debit: string, credit: string} $movement */
    private function creditBalance(array $movement): string
    {
        return bcsub($movement['credit'], $movement['debit'], 4);
    }

    /** @param array<string, mixed> $filters */
    private function unclosedIncomeResult(array $filters, string $asOfDate): string
    {
        $accounts = $this->accounts($filters, [Account::TypeRevenue, Account::TypeExpense]);
        $balances = $this->movements($filters, $accounts->modelKeys(), null, $asOfDate);
        $result = '0.0000';

        foreach ($accounts as $account) {
            $movement = $balances[(int) $account->getKey()] ?? $this->zeroMovement();
            $contribution = $account->account_type === Account::TypeRevenue
                ? bcsub($movement['credit'], $movement['debit'], 4)
                : bcsub($movement['credit'], $movement['debit'], 4);
            $result = bcadd($result, $contribution, 4);
        }

        return $result;
    }

    private function previousDate(string $date): string
    {
        return date('Y-m-d', strtotime($date.' -1 day'));
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function mergeComparison(array $current, array $comparison): array
    {
        $comparisonRows = collect($comparison['rows'])->keyBy('key');
        $current['rows'] = array_map(function (array $row) use ($comparisonRows): array {
            $comparisonRow = $comparisonRows->get($row['key']);

            return [
                ...$row,
                'comparison_amount' => is_array($comparisonRow) ? ($comparisonRow['amount'] ?? null) : null,
                ...(isset($row['opening']) ? [
                    'comparison_opening' => is_array($comparisonRow) ? ($comparisonRow['opening'] ?? null) : null,
                    'comparison_increases' => is_array($comparisonRow) ? ($comparisonRow['increases'] ?? null) : null,
                    'comparison_decreases' => is_array($comparisonRow) ? ($comparisonRow['decreases'] ?? null) : null,
                    'comparison_period_result' => is_array($comparisonRow) ? ($comparisonRow['period_result'] ?? null) : null,
                ] : []),
            ];
        }, $current['rows']);

        return $current;
    }

    private function baseAmount(mixed $amount, mixed $exchangeRate): string
    {
        $normalizedAmount = $this->numbers->normalizeToScale($amount, 4) ?? '0.0000';
        $rate = $this->numbers->normalizeToScale($exchangeRate, 6) ?? '1.000000';

        return bcmul($normalizedAmount, $rate, 4);
    }
}
