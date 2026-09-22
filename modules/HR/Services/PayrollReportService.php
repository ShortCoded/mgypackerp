<?php

namespace Modules\HR\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingScopeAccessService;

class PayrollReportService
{
    public function __construct(private readonly OperatingScopeAccessService $scope) {}

    /** @param array<string, mixed> $filters @return array{rows: LengthAwarePaginator, totals: list<array{currency_id: int|null, currency_code: string|null, gross: string, deductions: string, net: string}>} */
    public function payroll(int $companyId, User $user, array $filters): array
    {
        $query = $this->payrollQuery($companyId, $user, $filters);
        $totals = (clone $query)
            ->select(['payslip.currency_id', 'currency.code as currency_code'])
            ->selectRaw('SUM(payslip.gross_amount) as gross')
            ->selectRaw('SUM(payslip.deduction_amount) as deductions')
            ->selectRaw('SUM(payslip.net_amount) as net')
            ->groupBy('payslip.currency_id', 'currency.code')
            ->orderBy('currency.code')
            ->get()
            ->map(fn (object $row): array => [
                'currency_id' => $row->currency_id === null ? null : (int) $row->currency_id,
                'currency_code' => $row->currency_code,
                'gross' => bcadd((string) $row->gross, '0', 4),
                'deductions' => bcadd((string) $row->deductions, '0', 4),
                'net' => bcadd((string) $row->net, '0', 4),
            ])
            ->all();

        return [
            'rows' => (clone $query)->orderByDesc('period.period_end')->orderBy('payslip.employee_name')->paginate(30)->withQueryString(),
            'totals' => $totals,
        ];
    }

    /** @param array<string, mixed> $filters @return array{rows: LengthAwarePaginator, totals: array<string, string>, currency_code: string|null} */
    public function payments(int $companyId, User $user, array $filters): array
    {
        $query = $this->paymentQuery($companyId, $user, $filters);

        return [
            'rows' => (clone $query)->orderByDesc('voucher_date')->orderByDesc('id')->paginate(30)->withQueryString(),
            'totals' => [
                'amount' => $this->decimalAggregate(clone $query, 'amount'),
                'approved' => $this->decimalAggregate((clone $query)->where('status', 'approved'), 'amount'),
                'cancelled' => $this->decimalAggregate((clone $query)->where('status', 'cancelled'), 'amount'),
            ],
            'currency_code' => DB::table('currencies')
                ->where('company_id', $companyId)
                ->where('is_main', true)
                ->value('code'),
        ];
    }

    /** @param array<string, mixed> $filters @return Collection<int, object> */
    public function payrollRows(int $companyId, User $user, array $filters): Collection
    {
        return $this->payrollQuery($companyId, $user, $filters)->orderByDesc('period.period_end')->orderBy('payslip.employee_name')->get();
    }

    /** @param array<string, mixed> $filters @return Collection<int, object> */
    public function paymentRows(int $companyId, User $user, array $filters): Collection
    {
        return $this->paymentQuery($companyId, $user, $filters)->orderByDesc('voucher_date')->orderByDesc('id')->get();
    }

    private function decimalAggregate(Builder $query, string $column): string
    {
        $value = $query
            ->select([])
            ->selectRaw("COALESCE(SUM({$column}), 0) as aggregate")
            ->value('aggregate');

        return bcadd((string) $value, '0', 4);
    }

    /** @return array<string, mixed> */
    public function payslipForEmployee(int $payslipId, int $employeeId): array
    {
        $payslip = $this->payslipBaseQuery()
            ->where('payslip.id', $payslipId)
            ->where('payslip.employee_id', $employeeId)
            ->whereIn('payslip.status', ['approved', 'posted'])
            ->first();
        abort_if($payslip === null, 404);

        return $this->payslipPayload($payslip);
    }

    /** @return array<string, mixed> */
    public function payslipForAdmin(int $payslipId, int $companyId, User $user): array
    {
        $query = $this->payslipBaseQuery()->where('payslip.id', $payslipId)->where('payslip.company_id', $companyId);
        $this->applyBranchScope($query, $companyId, $user, 'payslip.branch_id');
        $this->applyFinancialPeriodRangeScope($query, $companyId, $user, 'period.period_start', 'period.period_end');
        $payslip = $query->first();
        abort_if($payslip === null, 404);

        return $this->payslipPayload($payslip, $user, $companyId);
    }

    /** @param array<string, mixed> $filters */
    private function payrollQuery(int $companyId, User $user, array $filters): Builder
    {
        $query = $this->payslipBaseQuery()->where('payslip.company_id', $companyId);
        $this->applyBranchScope($query, $companyId, $user, 'payslip.branch_id');
        $this->applyFinancialPeriodRangeScope($query, $companyId, $user, 'period.period_start', 'period.period_end');

        return $query
            ->when(isset($filters['period_from']), fn (Builder $query): Builder => $query->where('period.period_end', '>=', $filters['period_from']))
            ->when(isset($filters['period_to']), fn (Builder $query): Builder => $query->where('period.period_start', '<=', $filters['period_to']))
            ->when(isset($filters['branch_doc_num']), fn (Builder $query): Builder => $query->where('branch.doc_num', $filters['branch_doc_num']))
            ->when(isset($filters['status']), fn (Builder $query): Builder => $query->where('payslip.status', $filters['status']))
            ->when(isset($filters['run_id']), fn (Builder $query): Builder => $query->where('run.id', $filters['run_id']))
            ->when(isset($filters['employee']), function (Builder $query) use ($filters): Builder {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['employee']).'%';

                return $query->where(fn (Builder $search): Builder => $search
                    ->where('payslip.employee_name', 'like', $term)
                    ->orWhere('payslip.employee_doc_num', 'like', $term));
            });
    }

    /** @param array<string, mixed> $filters */
    private function paymentQuery(int $companyId, User $user, array $filters): Builder
    {
        $allowedPeriodIds = $this->allowedFinancialPeriodIds($companyId, $user);
        $effectiveStatusSql = $allowedPeriodIds === null
            ? 'payment.status'
            : "CASE WHEN payment.reversal_journal_entry_id IS NOT NULL AND reversal.id IS NULL THEN 'approved' ELSE payment.status END";
        $effectiveCancelledAtSql = $allowedPeriodIds === null
            ? 'payment.cancelled_at'
            : 'CASE WHEN payment.reversal_journal_entry_id IS NOT NULL AND reversal.id IS NULL THEN NULL ELSE payment.cancelled_at END';
        $query = DB::table('hr_payroll_payments as payment')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payment.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->join('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
            ->leftJoin('hr_payslips as payslip', 'payslip.id', '=', 'payment.payslip_id')
            ->leftJoin('branches as branch', 'branch.id', '=', 'payment.branch_id')
            ->leftJoin('journal_entries as journal', function ($join) use ($allowedPeriodIds): void {
                $join->on('journal.id', '=', 'payment.journal_entry_id');
                if ($allowedPeriodIds !== null) {
                    $join->whereIn('journal.financial_period_id', $allowedPeriodIds !== [] ? $allowedPeriodIds : [0]);
                }
            })
            ->leftJoin('journal_entries as reversal', function ($join) use ($allowedPeriodIds): void {
                $join->on('reversal.id', '=', 'payment.reversal_journal_entry_id');
                if ($allowedPeriodIds !== null) {
                    $join->whereIn('reversal.financial_period_id', $allowedPeriodIds !== [] ? $allowedPeriodIds : [0]);
                }
            })
            ->leftJoin('currencies as currency', function ($join): void {
                $join->on('currency.company_id', '=', 'payment.company_id')->where('currency.is_main', true);
            })
            ->where('payment.company_id', $companyId)
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->select([
                'payment.id', 'payment.payroll_run_id', 'payment.payslip_id', 'payment.branch_id', 'payment.amount',
                'payment.approved_at', 'period.period_start', 'period.period_end',
                'payslip.employee_doc_num', 'payslip.employee_name',
                'branch.doc_num as branch_doc_num', 'branch.name as branch_name',
                'voucher.doc_num as voucher_doc_num', 'voucher.voucher_date',
                'journal.doc_num as journal_doc_num', 'reversal.doc_num as reversal_journal_doc_num',
                'currency.code as currency_code',
            ])
            ->selectRaw("{$effectiveStatusSql} as status")
            ->selectRaw("{$effectiveCancelledAtSql} as cancelled_at");
        $this->applyBranchScope($query, $companyId, $user, 'payment.branch_id');
        $this->applyFinancialPeriodRangeScope($query, $companyId, $user, 'period.period_start', 'period.period_end');
        if ($allowedPeriodIds !== null) {
            $query->whereIn('payment.financial_period_id', $allowedPeriodIds !== [] ? $allowedPeriodIds : [0]);
        }

        $query
            ->when(isset($filters['period_from']), fn (Builder $query): Builder => $query->where('period.period_end', '>=', $filters['period_from']))
            ->when(isset($filters['period_to']), fn (Builder $query): Builder => $query->where('period.period_start', '<=', $filters['period_to']))
            ->when(isset($filters['branch_doc_num']), fn (Builder $query): Builder => $query->where('branch.doc_num', $filters['branch_doc_num']))
            ->when(isset($filters['run_id']), fn (Builder $query): Builder => $query->where('run.id', $filters['run_id']));

        if (isset($filters['employee'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['employee']).'%';
            $query->where(fn (Builder $employeeQuery): Builder => $employeeQuery
                ->where('payslip.employee_name', 'like', $term)
                ->orWhere('payslip.employee_doc_num', 'like', $term));
        }

        return DB::query()
            ->fromSub($query, 'payment_report')
            ->when(isset($filters['status']), fn (Builder $query): Builder => $query->where('status', $filters['status']));
    }

    private function payslipBaseQuery(): Builder
    {
        return DB::table('hr_payslips as payslip')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->leftJoin('branches as branch', 'branch.id', '=', 'payslip.branch_id')
            ->leftJoin('currencies as currency', 'currency.id', '=', 'payslip.currency_id')
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->select([
                'payslip.id', 'payslip.payroll_run_id', 'payslip.employee_id', 'payslip.company_id', 'payslip.branch_id', 'payslip.currency_id',
                'payslip.employee_doc_num', 'payslip.employee_name', 'payslip.gross_amount', 'payslip.deduction_amount',
                'payslip.net_amount', 'payslip.status', 'payslip.created_at', 'period.period_start', 'period.period_end',
                'branch.doc_num as branch_doc_num', 'branch.name as branch_name', 'currency.code as currency_code',
            ]);
    }

    private function applyBranchScope(Builder $query, int $companyId, User $user, string $column): void
    {
        if ($this->scope->hasUnrestrictedBranchAccess($user)) {
            return;
        }

        $company = Company::query()->findOrFail($companyId);
        $branchIds = $this->scope->allowedBranchQuery($user, [(string) $company->doc_num])->pluck('branches.id')->all();
        $query->whereIn($column, $branchIds !== [] ? $branchIds : [0]);
    }

    private function applyFinancialPeriodRangeScope(
        Builder $query,
        int $companyId,
        User $user,
        string $startColumn,
        string $endColumn,
    ): void {
        if ($this->scope->hasUnrestrictedFinancialPeriodAccess($user)) {
            return;
        }

        $company = Company::query()->findOrFail($companyId);
        $periodIds = $this->scope->allowedFinancialPeriodQuery($user, [(string) $company->doc_num])
            ->pluck('financial_periods.id')
            ->all();

        $query->whereExists(fn (Builder $period): Builder => $period
            ->selectRaw('1')
            ->from('financial_periods as access_period')
            ->whereIn('access_period.id', $periodIds !== [] ? $periodIds : [0])
            ->whereColumn('access_period.from_date', '<=', $startColumn)
            ->whereColumn('access_period.to_date', '>=', $endColumn)
            ->whereNull('access_period.deleted_at'));
    }

    /** @return array<string, mixed> */
    private function payslipPayload(object $payslip, ?User $admin = null, ?int $companyId = null): array
    {
        $items = DB::table('hr_payslip_items as item')
            ->leftJoin('hr_payroll_items as payroll_item', 'payroll_item.id', '=', 'item.payroll_item_id')
            ->where('item.payslip_id', $payslip->id)
            ->orderBy('item.direction')
            ->orderBy('item.id')
            ->get(['item.id', 'item.amount', 'item.direction', 'item.source_type', 'item.source_id', 'item.source_snapshot', 'payroll_item.code', 'payroll_item.name']);
        $input = DB::table('hr_payroll_inputs')->where('payroll_run_id', $payslip->payroll_run_id)->where('employee_id', $payslip->employee_id)->value('payload');
        $attendance = DB::table('hr_payroll_attendance_inputs')->where('payroll_run_id', $payslip->payroll_run_id)->where('employee_id', $payslip->employee_id)->value('payload');
        $payments = collect();
        if ($admin instanceof User && $companyId !== null) {
            $allowedPeriodIds = $this->allowedFinancialPeriodIds($companyId, $admin);
            $effectiveStatusSql = $allowedPeriodIds === null
                ? 'payment.status'
                : "CASE WHEN payment.reversal_journal_entry_id IS NOT NULL AND reversal.id IS NULL THEN 'approved' ELSE payment.status END";
            $paymentsQuery = DB::table('hr_payroll_payments as payment')
                ->join('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
                ->leftJoin('journal_entries as journal', function ($join) use ($allowedPeriodIds): void {
                    $join->on('journal.id', '=', 'payment.journal_entry_id');
                    if ($allowedPeriodIds !== null) {
                        $join->whereIn('journal.financial_period_id', $allowedPeriodIds !== [] ? $allowedPeriodIds : [0]);
                    }
                })
                ->leftJoin('journal_entries as reversal', function ($join) use ($allowedPeriodIds): void {
                    $join->on('reversal.id', '=', 'payment.reversal_journal_entry_id');
                    if ($allowedPeriodIds !== null) {
                        $join->whereIn('reversal.financial_period_id', $allowedPeriodIds !== [] ? $allowedPeriodIds : [0]);
                    }
                })
                ->leftJoin('currencies as currency', function ($join): void {
                    $join->on('currency.company_id', '=', 'payment.company_id')->where('currency.is_main', true);
                })
                ->where('payment.payroll_run_id', $payslip->payroll_run_id)
                ->where('payment.payslip_id', $payslip->id)
                ->orderBy('payment.id');
            if ($allowedPeriodIds !== null) {
                $paymentsQuery->whereIn('payment.financial_period_id', $allowedPeriodIds !== [] ? $allowedPeriodIds : [0]);
            }
            $payments = $paymentsQuery
                ->select([
                    'payment.id', 'payment.amount', 'voucher.doc_num as voucher_doc_num',
                    'voucher.voucher_date', 'journal.doc_num as journal_doc_num',
                    'reversal.doc_num as reversal_journal_doc_num', 'currency.code as currency_code',
                ])
                ->selectRaw("{$effectiveStatusSql} as status")
                ->get();
        }

        return [
            'payslip' => $payslip,
            'items' => $items->map(function (object $item): object {
                $item->source_snapshot = $this->decodeJson($item->source_snapshot);
                $translationKey = 'hr_payroll_reports.item_names.'.$item->code;
                $item->display_name = __($translationKey) !== $translationKey
                    ? __($translationKey)
                    : ($item->name ?: __('hr_payroll_reports.payslip.default_item'));

                return $item;
            }),
            'input' => $this->decodeJson($input),
            'attendance' => $this->decodeJson($attendance),
            'payments' => $payments,
            'showRunPayments' => $admin instanceof User,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<int>|null */
    private function allowedFinancialPeriodIds(int $companyId, User $user): ?array
    {
        if ($this->scope->hasUnrestrictedFinancialPeriodAccess($user)) {
            return null;
        }

        $company = Company::query()->findOrFail($companyId);

        return $this->scope->allowedFinancialPeriodQuery($user, [(string) $company->doc_num])
            ->pluck('financial_periods.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
