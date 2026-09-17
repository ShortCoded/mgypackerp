<?php

namespace Modules\HR\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\CostCenterHierarchyRegistry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\HR\Models\HrEmployee;

final class PayrollCostAllocationService
{
    /** @var list<string> */
    public const AllocationTypes = ['direct', 'indirect', 'administrative', 'sales', 'commission'];

    public function __construct(
        private readonly CostCenterHierarchyRegistry $costCenters,
        private readonly JournalEntryService $journalEntries,
    ) {}

    /**
     * @param  list<array{cost_center_doc_num: string, percentage: mixed, allocation_type?: string|null}>  $allocations
     */
    public function syncAllocations(int $payslipItemId, array $allocations): void
    {
        $allocations = array_values($allocations);

        DB::transaction(function () use ($payslipItemId, $allocations): void {
            $item = $this->payslipItem($payslipItemId, lock: true);

            if ($allocations === []) {
                throw new DomainException(__('At least one payroll cost allocation is required.'));
            }

            foreach ($allocations as $allocation) {
                $percentage = $allocation['percentage'] ?? null;

                if (! is_numeric($percentage)
                    || bccomp((string) $percentage, '0', 4) <= 0
                    || bccomp((string) $percentage, '100', 4) > 0) {
                    throw new DomainException(__('Each payroll allocation percentage must be greater than zero and at most 100%.'));
                }
            }

            $percentageTotal = collect($allocations)->reduce(
                fn (string $total, array $allocation): string => bcadd($total, (string) ($allocation['percentage'] ?? 0), 4),
                '0.0000',
            );

            if (bccomp($percentageTotal, '100.0000', 4) !== 0) {
                throw new DomainException(__('Payroll allocation percentages must total exactly 100%.'));
            }

            $employee = HrEmployee::query()->findOrFail($item->employee_id);
            $allocatedAmount = '0.00';
            $rows = [];

            foreach ($allocations as $index => $allocation) {
                $costCenter = CostCenter::query()
                    ->forCompany((int) $item->company_id)
                    ->active()
                    ->where('is_group', false)
                    ->where('doc_num', trim($allocation['cost_center_doc_num']))
                    ->first();

                if (! $costCenter instanceof CostCenter) {
                    throw new DomainException(__('Payroll allocations require an active posting cost center in the payroll company.'));
                }

                $type = $this->allocationType(
                    $allocation['allocation_type'] ?? null,
                    $costCenter,
                    (string) ($item->payroll_item_code ?? ''),
                );
                $classification = $this->classificationFor($type);
                $account = $this->accountFor($item, $classification);
                $amount = $index === array_key_last($allocations)
                    ? bcsub((string) $item->amount, $allocatedAmount, 2)
                    : bcmul((string) $item->amount, bcdiv((string) $allocation['percentage'], '100', 8), 2);
                $allocatedAmount = bcadd($allocatedAmount, $amount, 2);
                $rows[] = [
                    'payslip_item_id' => $payslipItemId,
                    'department_id' => $employee->department_id,
                    'cost_center_id' => $costCenter->getKey(),
                    'account_id' => $account->getKey(),
                    'account_classification_id' => $classification->getKey(),
                    'allocation_type' => $type,
                    'percentage' => number_format((float) $allocation['percentage'], 4, '.', ''),
                    'amount' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('hr_payroll_cost_allocations')->where('payslip_item_id', $payslipItemId)->delete();
            DB::table('hr_payroll_cost_allocations')->insert($rows);
        });
    }

    /** @return array{run: object, lines: list<array<string, mixed>>, errors: list<string>} */
    public function previewRun(int $payrollRunId): array
    {
        $run = $this->payrollRun($payrollRunId);
        $items = $this->runItems($payrollRunId);
        $stored = $this->storedAllocations($items->pluck('payslip_item_id')->all())->groupBy('payslip_item_id');
        $lines = [];
        $errors = [];

        foreach ($items as $item) {
            if ($item->direction !== 'earning') {
                continue;
            }

            $allocations = $stored->get($item->payslip_item_id, collect());

            if ($allocations->isEmpty()) {
                try {
                    $allocations = collect([$this->proposedAllocation($item)]);
                } catch (DomainException $exception) {
                    $errors[] = 'payslip_item:'.$item->payslip_item_id.':'.$exception->getMessage();

                    continue;
                }
            }

            foreach ($allocations as $allocation) {
                $lines[] = [
                    'payslip_item_id' => (int) $item->payslip_item_id,
                    'employee_id' => (int) $item->employee_id,
                    'employee' => (string) $item->employee_name,
                    'branch_id' => $item->branch_id === null ? null : (int) $item->branch_id,
                    'payroll_item' => (string) ($item->payroll_item_name ?? $item->payroll_item_code ?? 'Payroll'),
                    'direction' => (string) $item->direction,
                    'account_id' => (int) $allocation->account_id,
                    'account' => trim((string) $allocation->account_code.' / '.(string) $allocation->account_name),
                    'classification' => (string) $allocation->classification_code,
                    'department_id' => $allocation->department_id === null ? null : (int) $allocation->department_id,
                    'department' => (string) ($allocation->department_name ?? ''),
                    'cost_center_id' => (int) $allocation->cost_center_id,
                    'cost_center' => trim((string) $allocation->cost_center_code.' / '.(string) $allocation->cost_center_name),
                    'allocation_type' => (string) $allocation->allocation_type,
                    'percentage' => (string) $allocation->percentage,
                    'amount' => (string) $allocation->amount,
                    'stored' => (bool) ($allocation->stored ?? true),
                ];
            }
        }

        return ['run' => $run, 'lines' => $lines, 'errors' => array_values(array_unique($errors))];
    }

    public function postRun(int $payrollRunId): int
    {
        return DB::transaction(function () use ($payrollRunId): int {
            $run = $this->payrollRun($payrollRunId, lock: true);

            if ($run->status === 'posted') {
                $journalEntryId = DB::table('hr_payroll_postings')->where('payroll_run_id', $payrollRunId)->value('journal_entry_id');
                if ($journalEntryId === null) {
                    throw new DomainException(__('hr_payroll.messages.posting_reference_missing'));
                }

                return (int) $journalEntryId;
            }

            if ($run->status !== 'approved') {
                throw new DomainException(__('hr_payroll.messages.approval_required_before_posting'));
            }

            foreach ($this->runItems($payrollRunId)->where('direction', 'earning') as $item) {
                if (! DB::table('hr_payroll_cost_allocations')->where('payslip_item_id', $item->payslip_item_id)->exists()) {
                    $proposal = $this->proposedAllocation($item);
                    $this->syncAllocations((int) $item->payslip_item_id, [[
                        'cost_center_doc_num' => (string) $proposal->cost_center_doc_num,
                        'percentage' => '100.0000',
                        'allocation_type' => (string) $proposal->allocation_type,
                    ]]);
                }
            }

            $preview = $this->previewRun($payrollRunId);

            if ($preview['errors'] !== []) {
                throw new DomainException(implode(' ', $preview['errors']));
            }

            $expenseLines = collect($preview['lines'])->where('direction', 'earning')->values();

            if ($expenseLines->isEmpty()) {
                throw new DomainException(__('Payroll posting requires at least one allocated earning line.'));
            }

            $lines = $expenseLines->map(fn (array $line): array => [
                'account_id' => $line['account_id'],
                'debit_amount' => $line['amount'],
                'credit_amount' => '0.0000',
                'description' => __('hr_payroll.journal.payroll_cost', ['item' => $line['payroll_item']]),
                'employee_id' => $line['employee_id'],
                'department_id' => $line['department_id'],
                'cost_center_id' => $line['cost_center_id'],
                'branch_id' => $line['branch_id'],
            ])->all();
            $deductionLines = $this->deductionLines($payrollRunId, (int) $run->company_id);
            foreach ($deductionLines as $deductionLine) {
                $lines[] = $deductionLine;
            }

            $payableClassification = AccountClassification::query()->where('code', 'payroll_payable')->firstOrFail();
            $payable = $this->accountForCompanyClassification((int) $run->company_id, $payableClassification);

            $branches = $expenseLines->pluck('branch_id')->merge(collect($deductionLines)->pluck('branch_id'))->unique()->values();
            foreach ($branches as $branchId) {
                $gross = $expenseLines
                    ->where('branch_id', $branchId)
                    ->reduce(fn (string $total, array $line): string => bcadd($total, $line['amount'], 4), '0.0000');
                $deductions = collect($deductionLines)
                    ->where('branch_id', $branchId)
                    ->reduce(fn (string $total, array $line): string => bcadd($total, (string) $line['credit_amount'], 4), '0.0000');
                $net = bcsub($gross, $deductions, 4);

                if (bccomp($net, '0.0000', 4) < 0) {
                    throw new DomainException(__('hr_payroll.messages.negative_branch_payable'));
                }

                if (bccomp($net, '0.0000', 4) === 0) {
                    continue;
                }

                $lines[] = [
                    'account_id' => $payable->getKey(),
                    'debit_amount' => '0.0000',
                    'credit_amount' => $net,
                    'description' => __('hr_payroll.journal.payroll_payable'),
                    'branch_id' => $branchId,
                ];
            }

            $financialPeriod = FinancialPeriod::query()
                ->forCompany((int) $run->company_id)
                ->whereDate('from_date', '<=', $run->period_end)
                ->whereDate('to_date', '>=', $run->period_end)
                ->where('is_closed', false)
                ->firstOrFail();
            $currency = Currency::query()->forCompany((int) $run->company_id)->active()->where('is_main', true)->firstOrFail();
            $journal = $this->journalEntries->createPostedFromSource([
                'entry_date' => $run->period_end,
                'company_id' => (int) $run->company_id,
                'financial_period_id' => $financialPeriod->getKey(),
                'currency_id' => $currency->getKey(),
                'exchange_rate' => 1,
                'branch_id' => $run->branch_id,
                'description' => __('hr_payroll.journal.run_description', ['run' => $payrollRunId]),
                'source_type' => 'hr_payroll_run',
                'source_id' => $payrollRunId,
                'source_doc_num' => 'PAYRUN-'.$payrollRunId,
            ], $lines);

            DB::table('hr_payroll_postings')->updateOrInsert(
                ['payroll_run_id' => $payrollRunId],
                [
                    'posting_reference' => $journal->doc_num,
                    'status' => 'posted',
                    'journal_entry_id' => $journal->getKey(),
                    'gl_lines' => json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            DB::table('hr_payroll_runs')->where('id', $payrollRunId)->update(['status' => 'posted', 'posted_at' => now(), 'updated_at' => now()]);
            DB::table('hr_payslips')->where('payroll_run_id', $payrollRunId)->update(['status' => 'posted', 'updated_at' => now()]);
            DB::table('hr_payroll_run_employees')->where('payroll_run_id', $payrollRunId)->update(['status' => 'posted', 'updated_at' => now()]);

            return (int) $journal->getKey();
        });
    }

    private function allocationType(?string $requested, CostCenter $costCenter, string $payrollItemCode): string
    {
        if (str_contains(strtolower($payrollItemCode), 'commission')) {
            return 'commission';
        }

        if (is_string($requested) && in_array($requested, self::AllocationTypes, true)) {
            return $requested;
        }

        return $this->costCenters->allocationTypeForCode((string) $costCenter->cost_center_code)
            ?? throw new DomainException(__('The cost center labor nature is not configured; choose an explicit allocation type.'));
    }

    private function classificationFor(string $allocationType): AccountClassification
    {
        $code = match ($allocationType) {
            'direct' => 'direct_labor_cost',
            'indirect' => 'indirect_labor_cost',
            'sales' => 'selling_marketing_expense',
            'commission' => 'sales_commissions_expense',
            default => 'salary_expense',
        };

        return AccountClassification::query()->where('code', $code)->where('status', 'active')->firstOrFail();
    }

    private function accountFor(object $item, AccountClassification $classification): Account
    {
        if ($item->account_id !== null) {
            $account = Account::query()
                ->forCompany((int) $item->company_id)
                ->eligibleForDirectPosting()
                ->whereKey($item->account_id)
                ->first();

            if ($account instanceof Account && (int) $account->account_classification_id === (int) $classification->getKey()) {
                return $account;
            }
        }

        return $this->accountForCompanyClassification((int) $item->company_id, $classification);
    }

    private function accountForCompanyClassification(int $companyId, AccountClassification $classification): Account
    {
        return Account::query()
            ->forCompany($companyId)
            ->eligibleForDirectPosting()
            ->where('account_classification_id', $classification->getKey())
            ->orderBy('account_code')
            ->first()
            ?? throw new DomainException(__('No active posting account is mapped to classification :classification.', ['classification' => $classification->code]));
    }

    /**
     * @return list<array{account_id: int, debit_amount: string, credit_amount: string, description: string, employee_id: int, branch_id: int|null}>
     */
    private function deductionLines(int $payrollRunId, int $companyId): array
    {
        return $this->runItems($payrollRunId)
            ->where('direction', 'deduction')
            ->map(function (object $item) use ($companyId): array {
                if ($item->account_classification_id === null && $item->account_id === null) {
                    throw new DomainException(__('hr_payroll.messages.deduction_account_mapping_missing', [
                        'item' => $item->payroll_item_code,
                    ]));
                }

                $classification = $item->account_classification_id === null
                    ? null
                    : AccountClassification::query()->whereKey($item->account_classification_id)->where('status', 'active')->first();
                $account = $item->account_id === null
                    ? null
                    : Account::query()->forCompany($companyId)->eligibleForDirectPosting()->whereKey($item->account_id)->first();

                if (! $account instanceof Account && $classification instanceof AccountClassification) {
                    $account = $this->accountForCompanyClassification($companyId, $classification);
                }

                if (! $account instanceof Account
                    || ($classification instanceof AccountClassification
                        && (int) $account->account_classification_id !== (int) $classification->getKey())) {
                    throw new DomainException(__('hr_payroll.messages.deduction_account_mapping_missing', [
                        'item' => $item->payroll_item_code,
                    ]));
                }

                if ($item->source_type === 'salary_advance'
                    && $classification?->code !== 'employee_advances'
                    && $account->classification?->code !== 'employee_advances') {
                    throw new DomainException(__('hr_payroll.messages.advance_account_mapping_invalid'));
                }

                return [
                    'account_id' => (int) $account->getKey(),
                    'debit_amount' => '0.0000',
                    'credit_amount' => number_format((float) $item->amount, 4, '.', ''),
                    'description' => __('hr_payroll.journal.deduction', ['item' => $item->payroll_item_name ?: $item->payroll_item_code]),
                    'employee_id' => (int) $item->employee_id,
                    'branch_id' => $item->branch_id === null ? null : (int) $item->branch_id,
                ];
            })
            ->values()
            ->all();
    }

    private function payslipItem(int $payslipItemId, bool $lock = false): object
    {
        return DB::table('hr_payslip_items as item')
            ->join('hr_payslips as payslip', 'payslip.id', '=', 'item.payslip_id')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->leftJoin('hr_payroll_items as payroll_item', 'payroll_item.id', '=', 'item.payroll_item_id')
            ->where('item.id', $payslipItemId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first([
                'item.id', 'item.amount', 'item.direction', 'payslip.employee_id', 'period.company_id',
                'payroll_item.code as payroll_item_code', 'payroll_item.account_classification_id', 'payroll_item.account_id',
            ]) ?? throw new DomainException(__('Payroll item not found.'));
    }

    private function payrollRun(int $payrollRunId, bool $lock = false): object
    {
        return DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('run.id', $payrollRunId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first(['run.id', 'run.status', 'run.branch_id', 'period.company_id', 'period.period_start', 'period.period_end'])
            ?? throw new DomainException(__('Payroll run not found.'));
    }

    /** @return Collection<int, object> */
    private function runItems(int $payrollRunId): Collection
    {
        return DB::table('hr_payslip_items as item')
            ->join('hr_payslips as payslip', 'payslip.id', '=', 'item.payslip_id')
            ->join('hr_employees as employee', 'employee.id', '=', 'payslip.employee_id')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->leftJoin('hr_payroll_items as payroll_item', 'payroll_item.id', '=', 'item.payroll_item_id')
            ->where('run.id', $payrollRunId)
            ->orderBy('employee.full_name')
            ->orderBy('item.id')
            ->get([
                'item.id as payslip_item_id', 'item.amount', 'item.direction', 'employee.id as employee_id',
                'payslip.employee_name', DB::raw('COALESCE(payslip.branch_id, employee.branch_id) as branch_id'),
                DB::raw('COALESCE(payslip.department_id, employee.department_id) as department_id'),
                'period.company_id', 'payroll_item.code as payroll_item_code', 'payroll_item.name as payroll_item_name',
                'payroll_item.account_classification_id', 'payroll_item.account_id', 'item.source_type', 'item.source_id',
            ]);
    }

    /** @param list<int> $payslipItemIds @return Collection<int, object> */
    private function storedAllocations(array $payslipItemIds): Collection
    {
        if ($payslipItemIds === []) {
            return collect();
        }

        return DB::table('hr_payroll_cost_allocations as allocation')
            ->join('accounts as account', 'account.id', '=', 'allocation.account_id')
            ->join('account_classifications as classification', 'classification.id', '=', 'allocation.account_classification_id')
            ->join('cost_centers as cost_center', 'cost_center.id', '=', 'allocation.cost_center_id')
            ->leftJoin('hr_departments as department', 'department.id', '=', 'allocation.department_id')
            ->whereIn('allocation.payslip_item_id', $payslipItemIds)
            ->orderBy('allocation.id')
            ->get([
                'allocation.*', 'account.account_code', 'account.name as account_name',
                'classification.code as classification_code', 'department.name as department_name',
                'cost_center.doc_num as cost_center_doc_num', 'cost_center.cost_center_code', 'cost_center.name as cost_center_name',
            ]);
    }

    private function proposedAllocation(object $item): object
    {
        $employee = HrEmployee::query()->with('departmentModel')->findOrFail($item->employee_id);
        $costCenter = $employee->departmentModel?->defaultCostCenterForCompany((int) $item->company_id);

        if (! $costCenter instanceof CostCenter
            || (int) $costCenter->company_id !== (int) $item->company_id
            || $costCenter->trashed()
            || $costCenter->status !== 'active'
            || $costCenter->is_group) {
            throw new DomainException(__('Employee department has no active posting cost center default for the payroll company.'));
        }

        $type = $this->allocationType(null, $costCenter, (string) ($item->payroll_item_code ?? ''));
        $classification = $this->classificationFor($type);
        $account = $this->accountFor($item, $classification);

        return (object) [
            'account_id' => $account->getKey(),
            'account_code' => $account->account_code,
            'account_name' => $account->displayName(),
            'classification_code' => $classification->code,
            'department_id' => $employee->department_id,
            'department_name' => $employee->departmentModel?->name,
            'cost_center_id' => $costCenter->getKey(),
            'cost_center_doc_num' => $costCenter->doc_num,
            'cost_center_code' => $costCenter->cost_center_code,
            'cost_center_name' => $costCenter->displayName(),
            'allocation_type' => $type,
            'percentage' => '100.0000',
            'amount' => (string) $item->amount,
            'stored' => false,
        ];
    }
}
