<?php

namespace Modules\Accounting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;

final class FinancialAnalyticsReportService
{
    public const ExpenseAnalysis = 'expense_analysis';

    public const FinancialRatios = 'financial_ratios';

    private const CurrentAssetClassifications = [
        'cash', 'bank', 'accounts_receivable', 'allowance_for_doubtful_accounts', 'inventory',
        'raw_material_inventory', 'packaging_material_inventory', 'printing_ink_inventory',
        'spare_parts_inventory', 'operating_supplies_inventory', 'work_in_process_inventory',
        'semi_finished_goods_inventory', 'finished_goods_inventory', 'goods_in_transit_inventory',
        'scrap_waste_inventory', 'quarantine_inventory', 'rework_inventory', 'inventory_allowance',
        'prepaid_expenses', 'other_receivables', 'recoverable_vat', 'withholding_tax_receivable',
        'supplier_advances', 'employee_advances',
    ];

    private const CurrentLiabilityClassifications = [
        'accounts_payable', 'goods_received_not_invoiced', 'customer_advances', 'tax_payable',
        'output_vat_payable', 'withholding_tax_payable', 'payroll_tax_payable',
        'corporate_income_tax_payable', 'payroll_payable', 'accrued_expenses',
        'social_insurance_payable', 'other_current_liabilities', 'current_loans_payable',
        'current_lease_liabilities',
    ];

    private const InventoryClassifications = [
        'inventory', 'raw_material_inventory', 'packaging_material_inventory',
        'printing_ink_inventory', 'spare_parts_inventory', 'operating_supplies_inventory',
        'work_in_process_inventory', 'semi_finished_goods_inventory', 'finished_goods_inventory',
        'goods_in_transit_inventory', 'scrap_waste_inventory', 'quarantine_inventory',
        'rework_inventory', 'inventory_allowance',
    ];

    public function __construct(
        private readonly OperatingContextService $context,
        private readonly DateFormatService $dates,
        private readonly FinancialStatementQueryService $statements,
        private readonly JournalSourceLabelService $sourceLabels,
    ) {}

    /** @return list<string> */
    public static function types(): array
    {
        return [self::ExpenseAnalysis, self::FinancialRatios];
    }

    /** @return array<string, mixed> */
    public function filters(Request $request, string $type): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'], 409, __('operating_context.messages.required'));
        $period = FinancialPeriod::query()->whereKey($context['financial_period_id'])
            ->where('company_id', $context['company_id'])->firstOrFail();
        $filters = $request->only([
            'from_date', 'to_date', 'comparison_from_date', 'comparison_to_date', 'view_mode',
            'account_doc_num', 'classification_code', 'cost_center_doc_num', 'branch_doc_num',
            'source_type', 'source_doc_num', 'currency_doc_num',
        ]);
        foreach (['from_date', 'to_date', 'comparison_from_date', 'comparison_to_date'] as $field) {
            $filters[$field] = $this->dates->normalizeForStorage(trim((string) ($filters[$field] ?? '')));
        }

        $branch = filled($filters['branch_doc_num'] ?? null)
            ? $this->context->allowedBranchForCurrentCompany($request, (string) $filters['branch_doc_num'])
            : null;
        if (filled($filters['branch_doc_num'] ?? null) && (! $branch instanceof Branch || $branch->status !== 'active')) {
            throw ValidationException::withMessages([
                'branch_doc_num' => __('finance_reports.messages.invalid_branch'),
            ]);
        }

        return array_filter([
            ...$filters,
            'type' => $type,
            'from_date' => $filters['from_date'] ?? $period->from_date->toDateString(),
            'to_date' => $filters['to_date'] ?? $period->to_date->toDateString(),
            'view_mode' => ($filters['view_mode'] ?? 'summary') === 'detail' ? 'detail' : 'summary',
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'branch_id' => $branch instanceof Branch ? (int) $branch->getKey() : ($context['branch_id'] ? (int) $context['branch_id'] : null),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        return $filters['type'] === self::FinancialRatios
            ? $this->ratios($filters)
            : $this->expenses($filters);
    }

    /** @return array<string, Collection<int, mixed>> */
    public function filterOptions(int $companyId, array $selected = []): array
    {
        $accounts = Account::query()->forCompany($companyId)->active()->where('account_type', Account::TypeExpense)
            ->orderBy('account_code')->get(['id', 'doc_num', 'account_code', 'name', 'name_en']);
        $costCenters = CostCenter::query()->forCompany($companyId)->active()
            ->orderBy('cost_center_code')->get(['id', 'doc_num', 'cost_center_code', 'name']);
        $branches = $this->context->allowedBranchQueryForCurrentCompany(request())->active()
            ->orderBy('name')->get(['id', 'doc_num', 'name']);
        $currencies = Currency::query()->forCompany($companyId)->active()
            ->orderBy('code')->get(['id', 'doc_num', 'code', 'name']);

        if (filled($selected['account_doc_num'] ?? null)) {
            $accounts = $accounts->concat(Account::withTrashed()->forCompany($companyId)
                ->where('doc_num', $selected['account_doc_num'])->get(['id', 'doc_num', 'account_code', 'name', 'name_en']));
        }
        if (filled($selected['cost_center_doc_num'] ?? null)) {
            $costCenters = $costCenters->concat(CostCenter::withTrashed()->forCompany($companyId)
                ->where('doc_num', $selected['cost_center_doc_num'])->get(['id', 'doc_num', 'cost_center_code', 'name']));
        }
        if (filled($selected['currency_doc_num'] ?? null)) {
            $currencies = $currencies->concat(Currency::withTrashed()->forCompany($companyId)
                ->where('doc_num', $selected['currency_doc_num'])->get(['id', 'doc_num', 'code', 'name']));
        }

        return [
            'accounts' => $accounts->unique('id')->sortBy('account_code')->values(),
            'classifications' => DB::table('account_classifications')->whereIn('id', Account::query()->forCompany($companyId)->active()->where('account_type', Account::TypeExpense)->whereNotNull('account_classification_id')->select('account_classification_id'))->where('status', 'active')->orderBy('code')->get(['code', 'name', 'name_en']),
            'cost_centers' => $costCenters->unique('id')->sortBy('cost_center_code')->values(),
            'branches' => $branches->unique('id')->sortBy('name')->values(),
            'currencies' => $currencies->unique('id')->sortBy('code')->values(),
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function expenses(array $filters): array
    {
        $details = $this->expenseRows($filters, $filters['from_date'], $filters['to_date']);
        $rows = $filters['view_mode'] === 'detail' ? $details : $this->summarizeExpenses($details);
        $comparisonTotal = null;
        if (filled($filters['comparison_from_date'] ?? null) && filled($filters['comparison_to_date'] ?? null)) {
            $comparisonTotal = $this->sum($this->expenseRows($filters, $filters['comparison_from_date'], $filters['comparison_to_date']), 'amount_base');
        }

        return [
            'type' => self::ExpenseAnalysis,
            'title' => __('financial_analytics.types.expense_analysis.title'),
            'description' => __('financial_analytics.types.expense_analysis.description'),
            'columns' => $filters['view_mode'] === 'detail'
                ? $this->labels(['date', 'journal', 'source_document', 'account', 'classification', 'cost_center', 'branch', 'currency', 'debit', 'credit', 'amount_base'])
                : $this->labels(['account', 'classification', 'cost_center', 'branch', 'source_type', 'currency', 'amount_base']),
            'numeric_columns' => ['debit', 'credit', 'amount_base'],
            'rows' => $rows,
            'totals' => ['amount_base' => $this->sum($rows, 'amount_base')],
            'comparison_total' => $comparisonTotal,
            'notices' => [__('financial_analytics.notices.expense_posted_base_currency')],
            'filters' => $filters,
        ];
    }

    /** @param array<string, mixed> $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function expenseRows(array $filters, string $fromDate, string $toDate): Collection
    {
        $accountId = $this->scopedId(Account::query()->withTrashed()->forCompany($filters['company_id']), $filters['account_doc_num'] ?? null);
        $costCenterId = $this->scopedId(CostCenter::query()->withTrashed()->forCompany($filters['company_id']), $filters['cost_center_doc_num'] ?? null);
        $branchId = $this->scopedId(Branch::query()->where('company_id', $filters['company_id']), $filters['branch_doc_num'] ?? null) ?: ($filters['branch_id'] ?? null);
        $currencyId = $this->scopedId(Currency::query()->forCompany($filters['company_id']), $filters['currency_doc_num'] ?? null);

        return DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->leftJoin('account_classifications as classifications', 'classifications.id', '=', 'accounts.account_classification_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'lines.cost_center_id')
            ->leftJoin('branches', 'branches.id', '=', 'lines.branch_id')
            ->leftJoin('branches as entry_branches', 'entry_branches.id', '=', 'entries.branch_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'entries.currency_id')
            ->whereNull('entries.deleted_at')->where('entries.company_id', $filters['company_id'])
            ->where('entries.status', JournalEntry::StatusPosted)->where('entries.is_posted', true)
            ->where('accounts.account_type', Account::TypeExpense)
            ->whereDate('entries.entry_date', '>=', $fromDate)->whereDate('entries.entry_date', '<=', $toDate)
            ->where(fn (Builder $query): Builder => $query->whereNull('entries.source_type')->orWhere('entries.source_type', 'not like', 'period_closing%'))
            ->when($accountId, fn (Builder $query, int $id): Builder => $query->where('lines.account_id', $id))
            ->when($costCenterId, fn (Builder $query, int $id): Builder => $query->where('lines.cost_center_id', $id))
            ->when($currencyId, fn (Builder $query, int $id): Builder => $query->where('entries.currency_id', $id))
            ->when($filters['classification_code'] ?? null, fn (Builder $query, string $code): Builder => $query->where('classifications.code', $code))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('entries.source_type', $type))
            ->when($filters['source_doc_num'] ?? null, fn (Builder $query, string $document): Builder => $query->where('entries.source_doc_num', $document))
            ->when($branchId, fn (Builder $query, int $id): Builder => $query->where(fn (Builder $scope): Builder => $scope->where('lines.branch_id', $id)->orWhere(fn (Builder $fallback): Builder => $fallback->whereNull('lines.branch_id')->where('entries.branch_id', $id))))
            ->orderBy('entries.entry_date')->orderBy('entries.doc_number')->orderBy('lines.line_no')
            ->get([
                'entries.entry_date', 'entries.doc_num', 'entries.source_type', 'entries.source_doc_num', 'entries.exchange_rate',
                'lines.debit_amount', 'lines.credit_amount', 'accounts.doc_num as account_doc_num', 'accounts.account_code',
                'accounts.name as account_name', 'accounts.name_en as account_name_en', 'classifications.code as classification_code',
                'classifications.name as classification_name', 'classifications.name_en as classification_name_en',
                'cost_centers.doc_num as cost_center_doc_num', 'cost_centers.name as cost_center_name',
                'branches.doc_num as branch_doc_num', 'branches.name as branch_name',
                'entry_branches.doc_num as entry_branch_doc_num', 'entry_branches.name as entry_branch_name',
                'currencies.code as currency_code',
            ])->map(function (object $row): array {
                $debit = (string) $row->debit_amount;
                $credit = (string) $row->credit_amount;
                $classification = $this->localized($row->classification_code, $row->classification_name, $row->classification_name_en);
                $costCenter = trim(implode(' / ', array_filter([$row->cost_center_doc_num, $row->cost_center_name])));
                $branch = trim(implode(' / ', array_filter([
                    $row->branch_doc_num ?: $row->entry_branch_doc_num,
                    $row->branch_name ?: $row->entry_branch_name,
                ])));

                return [
                    '_url' => route('admin.accounting.journal-entries.show', $row->doc_num),
                    'date' => CarbonImmutable::parse($row->entry_date)->toDateString(), 'journal' => $row->doc_num,
                    'source_document' => $this->sourceLabels->labelWithDocument($row->source_type, $row->source_doc_num),
                    'source_type' => $this->sourceLabels->label($row->source_type),
                    'account' => Account::codeNameLabelFor($row->account_code, $row->account_name, $row->account_name_en),
                    'classification' => $classification !== '' ? $classification : __('financial_analytics.values.unspecified'),
                    'cost_center' => $costCenter !== '' ? $costCenter : __('financial_analytics.values.unspecified'),
                    'branch' => $branch !== '' ? $branch : __('financial_analytics.values.unspecified'),
                    'currency' => $row->currency_code, 'debit' => $debit, 'credit' => $credit,
                    'amount_base' => bcmul(bcsub($debit, $credit, 4), (string) $row->exchange_rate, 4),
                ];
            })->values();
    }

    /** @param Collection<int, array<string, mixed>> $details
     * @return Collection<int, array<string, mixed>>
     */
    private function summarizeExpenses(Collection $details): Collection
    {
        return $details->groupBy(fn (array $row): string => implode('|', [$row['account'], $row['classification'], $row['cost_center'], $row['branch'], $row['source_type'], $row['currency']]))
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'account' => $first['account'], 'classification' => $first['classification'],
                    'cost_center' => $first['cost_center'], 'branch' => $first['branch'],
                    'source_type' => $first['source_type'], 'currency' => $first['currency'],
                    'amount_base' => $this->sum($group, 'amount_base'),
                ];
            })->values();
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function ratios(array $filters): array
    {
        $current = $this->ratioPeriod($filters, $filters['from_date'], $filters['to_date']);
        $comparison = null;
        if (filled($filters['comparison_from_date'] ?? null) && filled($filters['comparison_to_date'] ?? null)) {
            $comparison = $this->ratioPeriod($filters, $filters['comparison_from_date'], $filters['comparison_to_date']);
        }
        $rows = collect($current)->map(function (array $ratio, string $key) use ($comparison): array {
            return [
                'ratio' => __('financial_analytics.ratios.'.$key),
                'value' => $ratio['value'] ?? __('financial_analytics.values.not_calculable'),
                'comparison' => $comparison[$key]['value'] ?? null,
                'formula' => __('financial_analytics.formulas.'.$key),
                'numerator' => $ratio['numerator'] ?? null,
                'denominator' => $ratio['denominator'] ?? null,
                'status' => ($ratio['value'] ?? null) === null ? __('financial_analytics.values.not_calculable') : __('financial_analytics.values.calculable'),
            ];
        })->values();

        return [
            'type' => self::FinancialRatios,
            'title' => __('financial_analytics.types.financial_ratios.title'),
            'description' => __('financial_analytics.types.financial_ratios.description'),
            'columns' => $this->labels(['ratio', 'value', 'comparison', 'formula', 'numerator', 'denominator', 'status']),
            'numeric_columns' => ['numerator', 'denominator'], 'rows' => $rows, 'totals' => [],
            'comparison_total' => null, 'notices' => [__('financial_analytics.notices.ratios_no_benchmark')], 'filters' => $filters,
        ];
    }

    /** @param array<string, mixed> $filters
     * @return array<string, array{value: string|null, numerator: string|null, denominator: string|null}>
     */
    private function ratioPeriod(array $filters, string $fromDate, string $toDate): array
    {
        $statementFilters = [
            'company_id' => $filters['company_id'], 'branch_id' => $filters['branch_id'] ?? null,
            'cost_center_id' => null, 'from_date' => $fromDate, 'to_date' => $toDate,
            'view_mode' => FinancialStatementQueryService::ViewSummary,
        ];
        $income = $this->statements->report([...$statementFilters, 'statement_type' => FinancialStatementQueryService::IncomeStatement]);
        $incomeComplete = $income['classification_complete'];
        $currentAssets = $this->classifiedBalance($filters, self::CurrentAssetClassifications, $toDate, Account::TypeAsset);
        $currentLiabilities = $this->classifiedBalance($filters, self::CurrentLiabilityClassifications, $toDate, Account::TypeLiability);
        $endingInventory = $this->classifiedBalance($filters, self::InventoryClassifications, $toDate, Account::TypeAsset);
        $openingInventory = $this->classifiedBalance($filters, self::InventoryClassifications, CarbonImmutable::parse($fromDate)->subDay()->toDateString(), Account::TypeAsset);
        $averageInventory = bcdiv(bcadd($openingInventory['amount'], $endingInventory['amount'], 4), '2', 4);
        $netRevenue = (string) $income['summary']['net_revenue'];
        $grossProfit = (string) $income['summary']['gross_profit'];
        $netProfit = (string) $income['summary']['period_result'];
        $costOfSales = (string) $income['summary']['cost_of_sales'];

        return [
            'current_ratio' => $this->ratio($currentAssets['amount'], $currentLiabilities['amount'], $currentAssets['mapped'] && $currentLiabilities['mapped']),
            'gross_profit_margin' => $this->ratio($grossProfit, $netRevenue, $incomeComplete, true),
            'net_profit_margin' => $this->ratio($netProfit, $netRevenue, $incomeComplete, true),
            'inventory_turnover' => $this->ratio($costOfSales, $averageInventory, $endingInventory['mapped'] || $openingInventory['mapped']),
        ];
    }

    /** @param array<string, mixed> $filters
     * @param  list<string>  $codes
     * @return array{amount: string, mapped: bool}
     */
    private function classifiedBalance(array $filters, array $codes, string $toDate, string $accountType): array
    {
        $rows = DB::table('journal_entry_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')->join('account_classifications as classifications', 'classifications.id', '=', 'accounts.account_classification_id')
            ->whereNull('entries.deleted_at')->where('entries.company_id', $filters['company_id'])->where('entries.status', JournalEntry::StatusPosted)->where('entries.is_posted', true)
            ->where('accounts.account_type', $accountType)->whereIn('classifications.code', $codes)->whereDate('entries.entry_date', '<=', $toDate)
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $id): Builder => $query->where(fn (Builder $scope): Builder => $scope->where('lines.branch_id', $id)->orWhere(fn (Builder $fallback): Builder => $fallback->whereNull('lines.branch_id')->where('entries.branch_id', $id))))
            ->get(['lines.debit_amount', 'lines.credit_amount', 'entries.exchange_rate']);
        $amount = '0.0000';
        foreach ($rows as $row) {
            $signed = $accountType === Account::TypeAsset
                ? bcsub((string) $row->debit_amount, (string) $row->credit_amount, 4)
                : bcsub((string) $row->credit_amount, (string) $row->debit_amount, 4);
            $amount = bcadd($amount, bcmul($signed, (string) $row->exchange_rate, 4), 4);
        }
        $mapped = Account::query()->withTrashed()->forCompany($filters['company_id'])->where('account_type', $accountType)->whereHas('classification', fn ($query) => $query->whereIn('code', $codes))->exists();

        return ['amount' => $amount, 'mapped' => $mapped];
    }

    /** @return array{value: string|null, numerator: string|null, denominator: string|null} */
    private function ratio(string $numerator, string $denominator, bool $componentsAvailable, bool $percentage = false): array
    {
        if (! $componentsAvailable || bccomp($denominator, '0', 4) === 0) {
            return ['value' => null, 'numerator' => $componentsAvailable ? $numerator : null, 'denominator' => $componentsAvailable ? $denominator : null];
        }
        $value = bcdiv($numerator, $denominator, 6);
        if ($percentage) {
            $value = bcmul($value, '100', 2).'%';
        }

        return ['value' => $value, 'numerator' => $numerator, 'denominator' => $denominator];
    }

    private function scopedId(mixed $query, mixed $docNum): ?int
    {
        return filled($docNum) ? ($query->where('doc_num', $docNum)->value('id') ?: -1) : null;
    }

    private function localized(mixed $code, mixed $name, mixed $nameEn): string
    {
        $label = app()->getLocale() === 'en' && filled($nameEn) ? $nameEn : $name;

        return trim(implode(' / ', array_filter([$code, $label])));
    }

    /** @param list<string> $keys
     * @return array<string, string>
     */
    private function labels(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => __('financial_analytics.columns.'.$key)])->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function sum(Collection $rows, string $key): string
    {
        return $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, is_numeric($row[$key] ?? null) ? (string) $row[$key] : '0', 4), '0.0000');
    }
}
