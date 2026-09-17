<?php

namespace Modules\HR\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;

final class PayrollCalculationService
{
    /**
     * @param  array{
     *     period_start: string,
     *     period_end: string,
     *     branch_doc_num?: string|null,
     *     adjustments?: list<array{
     *         employee_doc_num: string,
     *         deductions?: list<array{payroll_item_code: string, amount: mixed, reference?: string|null}>,
     *         advance_applications?: list<array{salary_advance_id: int, payroll_item_code: string, amount: mixed}>
     *     }>
     * }  $data
     * @return array{run_id: int, employee_count: int, gross: string, deductions: string, payable: string}
     */
    public function calculate(int $companyId, array $data): array
    {
        return DB::transaction(function () use ($companyId, $data): array {
            $branchId = $this->branchId($companyId, $data['branch_doc_num'] ?? null);
            $period = $this->period($companyId, $data['period_start'], $data['period_end']);
            $run = $this->run((int) $period->id, $branchId);

            if (! in_array($run->status, ['draft', 'calculated'], true)) {
                throw new DomainException(__('hr_payroll.messages.calculation_locked'));
            }

            $currency = Currency::query()
                ->forCompany($companyId)
                ->active()
                ->where('is_main', true)
                ->firstOrFail();
            $employees = $this->employees($companyId, $branchId, $data['period_start'], $data['period_end']);

            if ($employees->isEmpty()) {
                throw new DomainException(__('hr_payroll.messages.no_eligible_employees'));
            }

            DB::table('hr_payslips')->where('payroll_run_id', $run->id)->delete();
            DB::table('hr_payroll_inputs')->where('payroll_run_id', $run->id)->delete();
            DB::table('hr_payroll_attendance_inputs')->where('payroll_run_id', $run->id)->delete();
            DB::table('hr_payroll_run_employees')->where('payroll_run_id', $run->id)->delete();

            $adjustments = collect($data['adjustments'] ?? [])->keyBy('employee_doc_num');
            $grossTotal = '0.0000';
            $deductionTotal = '0.0000';

            foreach ($employees as $employee) {
                if ($employee->payroll_currency_id !== null && (int) $employee->payroll_currency_id !== (int) $currency->getKey()) {
                    throw new DomainException(__('hr_payroll.messages.functional_currency_required', ['employee' => $employee->doc_num]));
                }

                $assignment = $this->salaryAssignment($employee, $data['period_start'], $data['period_end']);
                $components = $this->components($assignment->components);
                $attendance = $this->attendance((int) $run->id, $employee, $data['period_start'], $data['period_end']);
                $requests = $this->approvedRequests($employee, $data['period_start'], $data['period_end']);
                $employeeAdjustments = $adjustments->get($employee->doc_num, []);
                $payslipId = DB::table('hr_payslips')->insertGetId([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->getKey(),
                    'company_id' => $companyId,
                    'branch_id' => $employee->branch_id,
                    'department_id' => $employee->department_id,
                    'currency_id' => $currency->getKey(),
                    'employee_doc_num' => $employee->doc_num,
                    'employee_name' => $employee->full_name ?: $employee->name,
                    'status' => 'calculated',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $gross = '0.0000';
                $deductions = '0.0000';
                $basic = $this->money($assignment->basic_salary);
                $this->addItem($payslipId, 'BASIC', $basic, 'earning', $assignment->source_type, $assignment->source_id, [
                    'effective_from' => $assignment->effective_from,
                    'effective_to' => $assignment->effective_to,
                    'basic_salary' => $basic,
                ]);
                $gross = bcadd($gross, $basic, 4);

                foreach ($components['items'] as $component) {
                    $direction = ($component['direction'] ?? 'earning') === 'deduction' ? 'deduction' : 'earning';
                    $amount = $this->positiveMoney($component['amount'] ?? 0, __('hr_payroll.messages.component_amount_invalid'));
                    $this->addItem(
                        $payslipId,
                        (string) ($component['payroll_item_code'] ?? ''),
                        $amount,
                        $direction,
                        $assignment->source_type,
                        $assignment->source_id,
                        $component,
                    );
                    if ($direction === 'earning') {
                        $gross = bcadd($gross, $amount, 4);
                    } else {
                        $deductions = bcadd($deductions, $amount, 4);
                    }
                }

                $approvedOvertimeMinutes = (int) $requests->where('request_type', 'overtime')->sum('requested_minutes');
                $overtimeMinutes = min($attendance['recorded_overtime_minutes'], $approvedOvertimeMinutes);
                if ($overtimeMinutes > 0) {
                    $hourlyRate = $this->positiveMoney(
                        $components['overtime_hourly_rate'] ?: $employee->hourly_wage,
                        __('hr_payroll.messages.overtime_rate_required'),
                    );
                    $overtimeAmount = bcmul(bcdiv((string) $overtimeMinutes, '60', 8), $hourlyRate, 4);
                    $this->addItem($payslipId, 'OVERTIME', $overtimeAmount, 'earning', 'approved_overtime', null, [
                        'request_ids' => $requests->where('request_type', 'overtime')->pluck('id')->values()->all(),
                        'approved_minutes' => $overtimeMinutes,
                        'hourly_rate' => $hourlyRate,
                    ]);
                    $gross = bcadd($gross, $overtimeAmount, 4);
                }

                foreach ($employeeAdjustments['deductions'] ?? [] as $deduction) {
                    $amount = $this->positiveMoney($deduction['amount'] ?? 0, __('hr_payroll.messages.deduction_amount_invalid'));
                    $this->addItem(
                        $payslipId,
                        (string) ($deduction['payroll_item_code'] ?? ''),
                        $amount,
                        'deduction',
                        'manual_deduction',
                        null,
                        ['reference' => $deduction['reference'] ?? null],
                    );
                    $deductions = bcadd($deductions, $amount, 4);
                }

                foreach ($employeeAdjustments['advance_applications'] ?? [] as $advanceApplication) {
                    $amount = $this->positiveMoney($advanceApplication['amount'] ?? 0, __('hr_payroll.messages.advance_amount_invalid'));
                    $advance = DB::table('hr_salary_advances')
                        ->where('id', $advanceApplication['salary_advance_id'])
                        ->where('employee_id', $employee->getKey())
                        ->where('status', 'active')
                        ->whereNull('deleted_at')
                        ->lockForUpdate()
                        ->first();

                    if ($advance === null || bccomp((string) $advance->balance, $amount, 4) < 0) {
                        throw new DomainException(__('hr_payroll.messages.advance_unavailable', ['employee' => $employee->doc_num]));
                    }

                    $itemId = $this->addItem(
                        $payslipId,
                        (string) ($advanceApplication['payroll_item_code'] ?? ''),
                        $amount,
                        'deduction',
                        'salary_advance',
                        (int) $advance->id,
                        ['principal' => (string) $advance->principal, 'balance' => (string) $advance->balance],
                    );
                    DB::table('hr_payroll_advance_applications')->insert([
                        'payroll_run_id' => $run->id,
                        'payslip_id' => $payslipId,
                        'payslip_item_id' => $itemId,
                        'salary_advance_id' => $advance->id,
                        'amount' => $amount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $deductions = bcadd($deductions, $amount, 4);
                }

                $net = bcsub($gross, $deductions, 4);
                if (bccomp($net, '0.0000', 4) < 0) {
                    throw new DomainException(__('hr_payroll.messages.negative_net_pay', ['employee' => $employee->doc_num]));
                }

                DB::table('hr_payslips')->where('id', $payslipId)->update([
                    'gross_amount' => $gross,
                    'deduction_amount' => $deductions,
                    'net_amount' => $net,
                    'updated_at' => now(),
                ]);
                DB::table('hr_payroll_run_employees')->insert([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->getKey(),
                    'status' => 'calculated',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('hr_payroll_inputs')->insert([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->getKey(),
                    'payload' => json_encode([
                        'employee' => [
                            'doc_num' => $employee->doc_num,
                            'name' => $employee->full_name ?: $employee->name,
                            'status' => $employee->status,
                            'hire_date' => $employee->hire_date?->toDateString(),
                            'contract_start_date' => $employee->contract_start_date?->toDateString(),
                            'contract_end_date' => $employee->contract_end_date?->toDateString(),
                            'company_id' => $employee->company_id,
                            'branch_id' => $employee->branch_id,
                            'department_id' => $employee->department_id,
                        ],
                        'salary_source' => [
                            'type' => $assignment->source_type,
                            'id' => $assignment->source_id,
                        ],
                        'salary_components' => $components,
                        'approved_request_ids' => $requests->pluck('id')->values()->all(),
                        'manual_adjustments' => $employeeAdjustments,
                        'gross' => $gross,
                        'deductions' => $deductions,
                        'net' => $net,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $grossTotal = bcadd($grossTotal, $gross, 4);
                $deductionTotal = bcadd($deductionTotal, $deductions, 4);
            }

            DB::table('hr_payroll_runs')->where('id', $run->id)->update([
                'status' => 'calculated',
                'calculated_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
                'approved_at' => null,
                'approved_by' => null,
                'posted_at' => null,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);

            return [
                'run_id' => (int) $run->id,
                'employee_count' => $employees->count(),
                'gross' => $grossTotal,
                'deductions' => $deductionTotal,
                'payable' => bcsub($grossTotal, $deductionTotal, 4),
            ];
        }, attempts: 3);
    }

    private function branchId(int $companyId, ?string $docNum): ?int
    {
        if (! filled($docNum)) {
            return null;
        }

        return Branch::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $docNum)
            ->value('id')
            ?? throw new DomainException(__('hr_payroll.messages.branch_not_found'));
    }

    private function period(int $companyId, string $start, string $end): object
    {
        $period = DB::table('hr_payroll_periods')
            ->where('company_id', $companyId)
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if ($period === null) {
            $id = DB::table('hr_payroll_periods')->insertGetId([
                'company_id' => $companyId,
                'period_start' => $start,
                'period_end' => $end,
                'status' => 'open',
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $period = DB::table('hr_payroll_periods')->where('id', $id)->first();
        }

        if ($period->status !== 'open') {
            throw new DomainException(__('hr_payroll.messages.period_closed'));
        }

        return $period;
    }

    private function run(int $periodId, ?int $branchId): object
    {
        $query = DB::table('hr_payroll_runs')
            ->where('payroll_period_id', $periodId)
            ->whereNull('deleted_at')
            ->when($branchId === null, fn ($builder) => $builder->whereNull('branch_id'), fn ($builder) => $builder->where('branch_id', $branchId));
        $run = $query->lockForUpdate()->first();

        if ($run !== null) {
            return $run;
        }

        $id = DB::table('hr_payroll_runs')->insertGetId([
            'payroll_period_id' => $periodId,
            'branch_id' => $branchId,
            'status' => 'draft',
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('hr_payroll_runs')->where('id', $id)->first();
    }

    private function employees(int $companyId, ?int $branchId, string $start, string $end): \Illuminate\Support\Collection
    {
        return HrEmployee::query()
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('hire_date')->orWhereDate('hire_date', '<=', $end))
            ->where(fn ($query) => $query->whereNull('contract_start_date')->orWhereDate('contract_start_date', '<=', $end))
            ->where(fn ($query) => $query->whereNull('contract_end_date')->orWhereDate('contract_end_date', '>=', $start))
            ->orderBy('doc_num')
            ->get();
    }

    private function salaryAssignment(HrEmployee $employee, string $start, string $end): object
    {
        $assignment = DB::table('hr_employee_salary_assignments')
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $end))
            ->whereNull('deleted_at')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($assignment !== null) {
            $assignment->source_type = 'salary_assignment';
            $assignment->source_id = (int) $assignment->id;

            return $assignment;
        }

        if ($employee->pay_basis !== 'monthly_salary') {
            throw new DomainException(__('hr_payroll.messages.unsupported_pay_basis', [
                'employee' => $employee->doc_num,
                'pay_basis' => $employee->pay_basis,
            ]));
        }

        return (object) [
            'id' => null,
            'source_type' => 'employee_master',
            'source_id' => (int) $employee->getKey(),
            'effective_from' => $employee->hire_date?->toDateString(),
            'effective_to' => $employee->contract_end_date?->toDateString(),
            'basic_salary' => $employee->basic_salary,
            'components' => null,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, overtime_hourly_rate: mixed} */
    private function components(?string $json): array
    {
        $decoded = $json === null ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return ['items' => [], 'overtime_hourly_rate' => 0];
        }

        if (array_is_list($decoded)) {
            return ['items' => $decoded, 'overtime_hourly_rate' => 0];
        }

        return [
            'items' => is_array($decoded['items'] ?? null) ? array_values($decoded['items']) : [],
            'overtime_hourly_rate' => $decoded['overtime_hourly_rate'] ?? 0,
        ];
    }

    private function attendance(int $runId, HrEmployee $employee, string $start, string $end): array
    {
        $records = DB::table('hr_attendance_daily_records')
            ->where('employee_id', $employee->getKey())
            ->whereBetween('work_date', [$start, $end])
            ->where('status', 'present')
            ->whereNotNull('check_out_at')
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $employee->company_id))
            ->when($employee->branch_id !== null, fn ($query) => $query->where(fn ($branch) => $branch->whereNull('branch_id')->orWhere('branch_id', $employee->branch_id)))
            ->orderBy('work_date')
            ->get(['id', 'work_date', 'branch_id', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'status']);
        $snapshot = [
            'record_ids' => $records->pluck('id')->all(),
            'finalized_days' => $records->count(),
            'worked_minutes' => (int) $records->sum('worked_minutes'),
            'late_minutes' => (int) $records->sum('late_minutes'),
            'early_leave_minutes' => (int) $records->sum('early_leave_minutes'),
            'recorded_overtime_minutes' => (int) $records->sum('overtime_minutes'),
        ];
        DB::table('hr_payroll_attendance_inputs')->insert([
            'payroll_run_id' => $runId,
            'employee_id' => $employee->getKey(),
            'payload' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $snapshot;
    }

    private function approvedRequests(HrEmployee $employee, string $start, string $end): \Illuminate\Support\Collection
    {
        return HrEmployeeServiceRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('company_id', $employee->company_id)
            ->when($employee->branch_id !== null, fn ($query) => $query->where('branch_id', $employee->branch_id))
            ->where('status', HrEmployeeServiceRequest::StatusApproved)
            ->whereIn('request_type', ['leave', 'overtime'])
            ->whereDate('requested_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('requested_to')->orWhereDate('requested_to', '>=', $start))
            ->get();
    }

    private function addItem(
        int $payslipId,
        string $payrollItemCode,
        string $amount,
        string $direction,
        string $sourceType,
        ?int $sourceId,
        array $snapshot,
    ): int {
        $items = DB::table('hr_payroll_items')
            ->where('code', trim($payrollItemCode))
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get(['id', 'item_kind']);

        if ($items->count() !== 1 || $items->first()->item_kind !== $direction) {
            throw new DomainException(__('hr_payroll.messages.payroll_item_mapping_invalid', ['item' => $payrollItemCode]));
        }

        return DB::table('hr_payslip_items')->insertGetId([
            'payslip_id' => $payslipId,
            'payroll_item_id' => $items->first()->id,
            'amount' => $amount,
            'direction' => $direction,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function positiveMoney(mixed $value, string $message): string
    {
        $amount = $this->money($value);
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw new DomainException($message);
        }

        return $amount;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
