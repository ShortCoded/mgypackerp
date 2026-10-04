<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Models\HrEmployee;

final class PayrollCalculationService
{
    public function __construct(
        private readonly PayrollAttendanceEffectService $attendanceEffects,
        private readonly PayrollAccrualService $accruals,
        private readonly PayrollStatutoryCalculationService $statutory,
        private readonly NumericFormatService $numbers,
    ) {}

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
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $branchId = $this->branchId($companyId, $data['branch_doc_num'] ?? null);
            $period = $this->period($companyId, $data['period_start'], $data['period_end'], $branchId);
            $run = $this->run((int) $period->id, $branchId, $period->status !== 'open');

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

            $overlappingScopeExists = DB::table('hr_payslips as slip')
                ->join('hr_payroll_runs as other_run', 'other_run.id', '=', 'slip.payroll_run_id')
                ->where('other_run.payroll_period_id', $period->id)
                ->where('other_run.id', '!=', $run->id)
                ->where('other_run.status', '!=', 'reversed')
                ->whereNull('other_run.deleted_at')
                ->whereIn('slip.employee_id', $employees->pluck('id')->all())
                ->when($branchId === null,
                    fn ($query) => $query->whereNotNull('other_run.branch_id'),
                    fn ($query) => $query->whereNull('other_run.branch_id'))
                ->exists();
            if ($overlappingScopeExists) {
                throw new DomainException(__('hr_payroll.messages.duplicate_employee_scope'));
            }

            DB::table('hr_payslips')->where('payroll_run_id', $run->id)->delete();
            DB::table('hr_payroll_inputs')->where('payroll_run_id', $run->id)->delete();
            DB::table('hr_payroll_attendance_inputs')->where('payroll_run_id', $run->id)->delete();
            DB::table('hr_payroll_run_employees')->where('payroll_run_id', $run->id)->delete();
            DB::table('hr_payroll_statutory_policy_usages')->where('payroll_run_id', $run->id)->delete();

            $adjustments = collect($data['adjustments'] ?? [])->keyBy('employee_doc_num');
            if ($adjustments->keys()->diff($employees->pluck('doc_num'))->isNotEmpty()) {
                throw new DomainException(__('hr_payroll.messages.adjustment_scope_invalid'));
            }
            $grossTotal = '0.0000';
            $deductionTotal = '0.0000';

            foreach ($employees as $employee) {
                if ($employee->payroll_currency_id !== null && (int) $employee->payroll_currency_id !== (int) $currency->getKey()) {
                    throw new DomainException(__('hr_payroll.messages.functional_currency_required', ['employee' => $employee->doc_num]));
                }

                $segments = $this->salarySegments($employee, $data['period_start'], $data['period_end'], $branchId);
                if ($segments->isEmpty()) {
                    throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required', ['employee' => $employee->doc_num]));
                }
                $assignment = $segments->first()['assignment'];
                $components = $segments->first()['components'];
                $accrual = $segments->first()['accrual'];
                $effects = $this->combinedEffects($employee, $segments, $data['period_start'], $data['period_end']);
                $this->storeAttendanceSnapshot((int) $run->id, $employee, $effects, $segments);
                $employeeAdjustments = $adjustments->get($employee->doc_num, []);
                $payslipId = DB::table('hr_payslips')->insertGetId([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->getKey(),
                    'company_id' => $companyId,
                    'branch_id' => $branchId ?? $segments->first()['branch_id'],
                    'department_id' => $segments->first()['department_id'],
                    'currency_id' => $currency->getKey(),
                    'employee_doc_num' => $employee->doc_num,
                    'employee_name' => $employee->full_name ?: $employee->name,
                    'status' => 'calculated',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $gross = '0.0000';
                $deductions = '0.0000';
                $datedEarnings = [];
                foreach ($segments as $segment) {
                    $segmentAssignment = $segment['assignment'];
                    $segmentAccrual = $segment['accrual'];
                    $basic = $this->money($segmentAccrual['amount']);
                    $this->addItem($payslipId, 'BASIC', $basic, 'earning', $segmentAssignment->source_type, $segmentAssignment->source_id, [
                        'effective_from' => $segmentAssignment->effective_from,
                        'effective_to' => $segmentAssignment->effective_to,
                        'segment_from' => $segment['from'],
                        'segment_to' => $segment['to'],
                        'organization_assignment_id' => $segment['organization_assignment_id'],
                        'branch_id' => $segment['branch_id'],
                        'department_id' => $segment['department_id'],
                        'cost_center_id' => $segment['cost_center_id'],
                        'pay_basis' => $segmentAccrual['pay_basis'],
                        'base_rate' => $segmentAccrual['rate'],
                        'accrual' => $segmentAccrual,
                    ]);
                    $gross = bcadd($gross, $basic, 4);
                    $basicByDate = $segmentAccrual['evidence']['earning_by_date'] ?? [];
                    $this->mergeDatedEarnings($datedEarnings, $basicByDate);

                    foreach ($segment['components']['items'] as $component) {
                        $direction = ($component['direction'] ?? 'earning') === 'deduction' ? 'deduction' : 'earning';
                        $amount = $this->positiveMoney(
                            bcmul((string) ($component['amount'] ?? 0), (string) $segmentAccrual['component_factor'], 4),
                            __('hr_payroll.messages.component_amount_invalid'),
                        );
                        $this->addItem(
                            $payslipId,
                            (string) ($component['payroll_item_code'] ?? ''),
                            $amount,
                            $direction,
                            $segmentAssignment->source_type,
                            $segmentAssignment->source_id,
                            [
                                ...$component,
                                'segment_from' => $segment['from'],
                                'segment_to' => $segment['to'],
                                'organization_assignment_id' => $segment['organization_assignment_id'],
                                'branch_id' => $segment['branch_id'],
                                'department_id' => $segment['department_id'],
                                'cost_center_id' => $segment['cost_center_id'],
                                'component_factor' => $segmentAccrual['component_factor'],
                            ],
                        );
                        if ($direction === 'earning') {
                            $gross = bcadd($gross, $amount, 4);
                            $this->mergeDatedEarnings($datedEarnings, $this->distributeByWeights($amount, $basicByDate));
                        } else {
                            $deductions = bcadd($deductions, $amount, 4);
                        }
                    }
                }

                $overtimeMinutes = $effects['overtime']['minutes'];
                if ($overtimeMinutes > 0) {
                    $overtimeAmount = $this->positiveMoney($effects['overtime']['amount'], __('hr_payroll.messages.overtime_rate_required'));
                    if ($segments->count() === 1) {
                        $this->addItem($payslipId, 'OVERTIME', $overtimeAmount, 'earning', 'approved_overtime', null, [
                            'request_ids' => $effects['overtime']['request_ids'],
                            'approved_minutes' => $overtimeMinutes,
                            'hourly_rate' => $this->positiveMoney($effects['overtime']['hourly_rate'], __('hr_payroll.messages.overtime_rate_required')),
                            'organization_assignment_id' => $segments->first()['organization_assignment_id'],
                            'branch_id' => $segments->first()['branch_id'],
                            'department_id' => $segments->first()['department_id'],
                            'cost_center_id' => $segments->first()['cost_center_id'],
                        ]);
                        $this->mergeDatedEarnings($datedEarnings, $this->overtimeByDate(
                            $overtimeAmount, $overtimeMinutes, $effects['attendance']['finalized_records'],
                            $segments->first()['from'], $segments->first()['to'],
                        ));
                    } else {
                        foreach ($effects['overtime']['rate_segments'] as $rateSegment) {
                            if (bccomp($rateSegment['amount'], '0.0000', 4) <= 0) {
                                continue;
                            }
                            $this->addItem($payslipId, 'OVERTIME', $rateSegment['amount'], 'earning', 'approved_overtime', null, [
                                'request_ids' => $rateSegment['request_ids'],
                                'approved_minutes' => $rateSegment['minutes'],
                                'hourly_rate' => $rateSegment['hourly_rate'],
                                'segment_from' => $rateSegment['from'],
                                'segment_to' => $rateSegment['to'],
                                'organization_assignment_id' => $rateSegment['organization_assignment_id'],
                                'branch_id' => $rateSegment['branch_id'],
                                'department_id' => $rateSegment['department_id'],
                                'cost_center_id' => $rateSegment['cost_center_id'],
                            ]);
                            $this->mergeDatedEarnings($datedEarnings, $this->overtimeByDate(
                                $rateSegment['amount'], $rateSegment['minutes'], $effects['attendance']['finalized_records'],
                                $rateSegment['from'], $rateSegment['to'],
                            ));
                        }
                    }
                    $gross = bcadd($gross, $overtimeAmount, 4);
                }

                foreach ($effects['deductions'] as $attendanceDeduction) {
                    $amount = $this->positiveMoney($attendanceDeduction['amount'], __('hr_payroll.messages.deduction_amount_invalid'));
                    $this->addItem(
                        $payslipId,
                        $attendanceDeduction['payroll_item_code'],
                        $amount,
                        'deduction',
                        'attendance_policy',
                        $attendanceDeduction['policy_id'],
                        $attendanceDeduction['snapshot'],
                    );
                    $deductions = bcadd($deductions, $amount, 4);
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

                $statutory = $this->statutory->calculate($companyId, $employee, $data['period_start'], $data['period_end'], $gross, $datedEarnings);
                foreach (['insurance' => 'insurance_sources', 'tax' => 'tax_sources'] as $type => $sourceKey) {
                    foreach ($statutory[$sourceKey] as $source) {
                        DB::table('hr_payroll_statutory_policy_usages')->insert([
                            'company_id' => $companyId,
                            'payroll_run_id' => $run->id,
                            'employee_id' => $employee->getKey(),
                            'policy_type' => $type,
                            'policy_id' => $source['policy_id'],
                            'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
                foreach ($employeeAdjustments['deductions'] ?? [] as $manualDeduction) {
                    if (($manualDeduction['payroll_item_code'] ?? null) === 'SOCIAL-INSURANCE' && $statutory['insurance_sources'] !== []) {
                        throw new DomainException(__('hr_payroll.messages.statutory_manual_duplicate', ['item' => 'SOCIAL-INSURANCE']));
                    }
                    if (($manualDeduction['payroll_item_code'] ?? null) === 'PAYROLL-TAX' && $statutory['tax_sources'] !== []) {
                        throw new DomainException(__('hr_payroll.messages.statutory_manual_duplicate', ['item' => 'PAYROLL-TAX']));
                    }
                }
                foreach ($statutory['insurance_sources'] as $source) {
                    if (bccomp($source['employee_amount'], '0', 4) > 0) {
                        $this->addItem($payslipId, 'SOCIAL-INSURANCE', $source['employee_amount'], 'deduction', 'statutory_insurance_employee', (int) $source['policy_id'], $source);
                    }
                    if (bccomp($source['employer_amount'], '0', 4) > 0) {
                        $organizationSlices = [];
                        foreach ($segments as $segment) {
                            $sliceFrom = max($source['from'], $segment['from']);
                            $sliceTo = min($source['to'], $segment['to']);
                            if ($sliceFrom <= $sliceTo) {
                                $organizationSlices[] = [
                                    'segment' => $segment,
                                    'from' => $sliceFrom,
                                    'to' => $sliceTo,
                                    'days' => (int) CarbonImmutable::parse($sliceFrom)->diffInDays(CarbonImmutable::parse($sliceTo)) + 1,
                                ];
                            }
                        }
                        $weights = array_column($organizationSlices, 'days');
                        if (array_sum($weights) !== $source['covered_days']) {
                            throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required', ['employee' => $employee->doc_num]));
                        }
                        foreach ($this->distributeByWeights($source['employer_amount'], $weights) as $index => $amount) {
                            $slice = $organizationSlices[$index];
                            $segment = $slice['segment'];
                            if (bccomp($amount, '0', 4) <= 0) {
                                continue;
                            }
                            $this->addItem($payslipId, 'EMPLOYER-INSURANCE', $amount, 'employer', 'statutory_insurance_employer', (int) $source['policy_id'], [
                                ...$source,
                                'organization_slice_from' => $slice['from'],
                                'organization_slice_to' => $slice['to'],
                                'organization_assignment_id' => $segment['organization_assignment_id'],
                                'branch_id' => $segment['branch_id'],
                                'department_id' => $segment['department_id'],
                                'cost_center_id' => $segment['cost_center_id'],
                                'organization_amount' => $amount,
                            ]);
                        }
                    }
                }
                foreach ($statutory['tax_sources'] as $source) {
                    if (bccomp($source['amount'], '0', 4) > 0) {
                        $this->addItem($payslipId, 'PAYROLL-TAX', $source['amount'], 'deduction', 'statutory_employment_tax', (int) $source['policy_id'], $source);
                    }
                }
                $deductions = bcadd($deductions, bcadd($statutory['employee_insurance'], $statutory['tax'], 4), 4);

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
                $branchPayables = $segments->pluck('branch_id')->unique()->count() > 1
                    ? $this->splitPayslipByBranch($payslipId, $segments, $datedEarnings, $gross, $deductions)
                    : [[
                        'branch_id' => (int) $segments->first()['branch_id'],
                        'gross' => $gross,
                        'deductions' => $deductions,
                        'net' => $net,
                    ]];
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
                            'branch_id' => $branchId ?? $segments->first()['branch_id'],
                            'department_id' => $segments->first()['department_id'],
                        ],
                        'salary_source' => $segments->count() === 1 ? [
                            'type' => $assignment->source_type,
                            'id' => $assignment->source_id,
                        ] : null,
                        'salary_sources' => $segments->map(fn (array $segment): array => [
                            'type' => $segment['assignment']->source_type,
                            'id' => $segment['assignment']->source_id,
                            'from' => $segment['from'],
                            'to' => $segment['to'],
                            'organization_assignment_id' => $segment['organization_assignment_id'],
                            'branch_id' => $segment['branch_id'],
                            'department_id' => $segment['department_id'],
                            'cost_center_id' => $segment['cost_center_id'],
                            'components' => $segment['components'],
                            'accrual' => $segment['accrual'],
                        ])->all(),
                        'salary_components' => $segments->count() === 1 ? $components : null,
                        'accrual' => $segments->count() === 1 ? $accrual : null,
                        'accrual_segments' => $segments->map(fn (array $segment): array => $segment['accrual'])->all(),
                        'approved_request_ids' => $effects['approved_request_ids'],
                        'canonical_leave_request_ids' => $effects['canonical_leave_request_ids'],
                        'payroll_attendance_effects' => [
                            'policies' => $effects['policy_snapshots'],
                            'summary' => $effects['summary'],
                            'deductions' => $effects['deductions'],
                            'overtime' => $effects['overtime'],
                        ],
                        'manual_adjustments' => $employeeAdjustments,
                        'dated_earnings' => $datedEarnings,
                        'statutory' => $statutory,
                        'branch_payables' => $branchPayables,
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

    /**
     * @param  Collection<int, array<string, mixed>>  $segments
     * @param  array<string, string>  $datedEarnings
     * @return list<array{branch_id: int, gross: string, deductions: string, net: string}>
     */
    private function splitPayslipByBranch(int $payslipId, Collection $segments, array $datedEarnings, string $gross, string $deductions): array
    {
        $originalSlip = DB::table('hr_payslips')->where('id', $payslipId)->first();
        if ($originalSlip === null) {
            throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required'));
        }

        $slipIds = [(int) $originalSlip->branch_id => $payslipId];
        $branchGross = [];
        $branchDeductions = [];
        foreach ($segments->groupBy('branch_id') as $branchId => $branchSegments) {
            $branchId = (int) $branchId;
            $branchGross[$branchId] = '0.0000';
            $branchDeductions[$branchId] = '0.0000';
            if (isset($slipIds[$branchId])) {
                continue;
            }

            $slipIds[$branchId] = DB::table('hr_payslips')->insertGetId([
                'payroll_run_id' => $originalSlip->payroll_run_id,
                'employee_id' => $originalSlip->employee_id,
                'company_id' => $originalSlip->company_id,
                'branch_id' => $branchId,
                'department_id' => $branchSegments->first()['department_id'],
                'currency_id' => $originalSlip->currency_id,
                'employee_doc_num' => $originalSlip->employee_doc_num,
                'employee_name' => $originalSlip->employee_name,
                'status' => 'calculated',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $items = DB::table('hr_payslip_items')->where('payslip_id', $payslipId)->orderBy('id')->get();
        foreach ($items->where('direction', 'earning') as $item) {
            $snapshot = json_decode((string) $item->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $branchId = (int) ($snapshot['branch_id'] ?? 0);
            if (! isset($branchGross[$branchId])) {
                throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required', ['employee' => $originalSlip->employee_doc_num]));
            }
            $branchGross[$branchId] = bcadd($branchGross[$branchId], (string) $item->amount, 4);
        }

        $orderedItems = $items->sortBy(fn (object $item): int => in_array($item->source_type, ['manual_deduction', 'salary_advance'], true) ? 1 : 0)->values();
        foreach ($orderedItems as $item) {
            $snapshot = json_decode((string) $item->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $weights = $this->branchWeightsForItem($item, $snapshot, $segments, $datedEarnings, $branchGross, $branchDeductions, $items);
            $parts = $this->distributeBranchAmount((string) $item->amount, $weights);
            $advance = $item->source_type === 'salary_advance'
                ? DB::table('hr_payroll_advance_applications')->where('payslip_item_id', $item->id)->first()
                : null;
            if ($item->source_type === 'salary_advance'
                && ($advance === null || bccomp((string) $advance->amount, (string) $item->amount, 4) !== 0)) {
                throw new DomainException(__('hr_payroll.messages.advance_unavailable', ['employee' => $originalSlip->employee_doc_num]));
            }

            $firstPart = true;
            foreach ($parts as $branchId => $amount) {
                if (bccomp($amount, '0.0000', 4) === 0 && bccomp((string) $item->amount, '0.0000', 4) !== 0) {
                    continue;
                }
                $branchId = (int) $branchId;
                $allocatedSnapshot = [
                    ...$snapshot,
                    'payroll_branch_allocation' => [
                        'branch_id' => $branchId,
                        'source_item_amount' => (string) $item->amount,
                        'basis' => in_array($item->source_type, ['manual_deduction', 'salary_advance'], true)
                            ? 'remaining_branch_entitlement'
                            : 'dated_source',
                    ],
                ];
                $itemData = [
                    'payslip_id' => $slipIds[$branchId],
                    'amount' => $amount,
                    'source_snapshot' => json_encode($allocatedSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ];
                if ($firstPart) {
                    DB::table('hr_payslip_items')->where('id', $item->id)->update($itemData);
                    $allocatedItemId = (int) $item->id;
                    $firstPart = false;
                } else {
                    $allocatedItemId = DB::table('hr_payslip_items')->insertGetId([
                        ...$itemData,
                        'payroll_item_id' => $item->payroll_item_id,
                        'direction' => $item->direction,
                        'source_type' => $item->source_type,
                        'source_id' => $item->source_id,
                        'created_at' => now(),
                    ]);
                }
                if ($advance !== null) {
                    $applicationData = [
                        'payslip_id' => $slipIds[$branchId],
                        'payslip_item_id' => $allocatedItemId,
                        'amount' => $amount,
                        'updated_at' => now(),
                    ];
                    if ($allocatedItemId === (int) $item->id) {
                        DB::table('hr_payroll_advance_applications')->where('id', $advance->id)->update($applicationData);
                    } else {
                        DB::table('hr_payroll_advance_applications')->insert([
                            ...$applicationData,
                            'payroll_run_id' => $advance->payroll_run_id,
                            'salary_advance_id' => $advance->salary_advance_id,
                            'created_at' => now(),
                        ]);
                    }
                }
                if ($item->direction === 'deduction') {
                    $branchDeductions[$branchId] = bcadd($branchDeductions[$branchId], $amount, 4);
                }
            }
        }

        $allocatedGross = '0.0000';
        $allocatedDeductions = '0.0000';
        $result = [];
        foreach ($slipIds as $branchId => $splitSlipId) {
            $net = bcsub($branchGross[$branchId], $branchDeductions[$branchId], 4);
            if (bccomp($net, '0.0000', 4) < 0) {
                throw new DomainException(__('hr_payroll.messages.negative_branch_payable'));
            }
            DB::table('hr_payslips')->where('id', $splitSlipId)->update([
                'gross_amount' => $branchGross[$branchId],
                'deduction_amount' => $branchDeductions[$branchId],
                'net_amount' => $net,
                'updated_at' => now(),
            ]);
            $allocatedGross = bcadd($allocatedGross, $branchGross[$branchId], 4);
            $allocatedDeductions = bcadd($allocatedDeductions, $branchDeductions[$branchId], 4);
            $result[] = [
                'branch_id' => $branchId,
                'gross' => $branchGross[$branchId],
                'deductions' => $branchDeductions[$branchId],
                'net' => $net,
            ];
        }
        if (bccomp($allocatedGross, $gross, 4) !== 0 || bccomp($allocatedDeductions, $deductions, 4) !== 0) {
            throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required', ['employee' => $originalSlip->employee_doc_num]));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  Collection<int, array<string, mixed>>  $segments
     * @param  array<string, string>  $datedEarnings
     * @param  array<int, string>  $branchGross
     * @param  array<int, string>  $branchDeductions
     * @param  Collection<int, object>  $sourceItems
     * @return array<int, string>
     */
    private function branchWeightsForItem(object $item, array $snapshot, Collection $segments, array $datedEarnings, array $branchGross, array $branchDeductions, Collection $sourceItems): array
    {
        $directBranchId = (int) ($snapshot['branch_id'] ?? 0);
        if (isset($branchGross[$directBranchId])) {
            return [$directBranchId => '1'];
        }
        $weights = array_fill_keys(array_keys($branchGross), '0.00000000');
        if ($item->source_type === 'attendance_policy') {
            foreach ($snapshot['rate_segments'] ?? [$snapshot] as $source) {
                $dates = $source['dates'] ?? [];
                if ($dates === []) {
                    throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required'));
                }
                $branchId = null;
                foreach ($dates as $date) {
                    $datedBranchId = $this->branchForDateInSegments((string) $date, $segments);
                    if ($branchId !== null && $branchId !== $datedBranchId) {
                        throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required'));
                    }
                    $branchId = $datedBranchId;
                }
                $weights[$branchId] = bcadd($weights[$branchId], (string) ($source['unrounded_amount'] ?? $source['amount'] ?? '0'), 8);
            }
        } elseif ($item->source_type === 'statutory_insurance_employee') {
            foreach ($segments as $segment) {
                $from = max((string) $snapshot['from'], (string) $segment['from']);
                $to = min((string) $snapshot['to'], (string) $segment['to']);
                if ($from <= $to) {
                    $days = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
                    $branchId = (int) $segment['branch_id'];
                    $weights[$branchId] = bcadd($weights[$branchId], (string) $days, 8);
                }
            }
        } elseif ($item->source_type === 'statutory_employment_tax') {
            foreach ($datedEarnings as $date => $amount) {
                if ($date >= $snapshot['from'] && $date <= $snapshot['to']) {
                    $branchId = $this->branchForDateInSegments($date, $segments);
                    $weights[$branchId] = bcadd($weights[$branchId], $amount, 12);
                }
            }
            if (($snapshot['taxable_basis'] ?? 'gross') === 'gross_after_employee_insurance') {
                foreach ($sourceItems->where('source_type', 'statutory_insurance_employee') as $insuranceItem) {
                    $insurance = json_decode((string) $insuranceItem->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
                    if (! isset($insurance['from'], $insurance['to'], $insurance['covered_days']) || (int) $insurance['covered_days'] <= 0) {
                        throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required'));
                    }
                    foreach ($segments as $segment) {
                        $from = max((string) $snapshot['from'], (string) $insurance['from'], (string) $segment['from']);
                        $to = min((string) $snapshot['to'], (string) $insurance['to'], (string) $segment['to']);
                        if ($from > $to) {
                            continue;
                        }
                        $days = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
                        $insuranceAmount = bcdiv(
                            bcmul((string) $insuranceItem->amount, (string) $days, 12),
                            (string) $insurance['covered_days'],
                            12,
                        );
                        $branchId = (int) $segment['branch_id'];
                        $weights[$branchId] = bcsub($weights[$branchId], $insuranceAmount, 12);
                    }
                }
                foreach ($weights as $branchId => $weight) {
                    if (bccomp($weight, '0', 12) < 0) {
                        $weights[$branchId] = '0.000000000000';
                    }
                }
            }
        } elseif (in_array($item->source_type, ['manual_deduction', 'salary_advance'], true)) {
            foreach ($branchGross as $branchId => $amount) {
                $available = bcsub($amount, $branchDeductions[$branchId], 4);
                $weights[$branchId] = bccomp($available, '0.0000', 4) > 0 ? $available : '0.0000';
            }
        } else {
            throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required'));
        }

        return $weights;
    }

    /** @param Collection<int, array<string, mixed>> $segments */
    private function branchForDateInSegments(string $date, Collection $segments): int
    {
        $matching = $segments->filter(fn (array $segment): bool => $date >= $segment['from'] && $date <= $segment['to']);
        if ($matching->count() !== 1) {
            throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required'));
        }

        return (int) $matching->first()['branch_id'];
    }

    /** @param array<int, string> $weights @return array<int, string> */
    private function distributeBranchAmount(string $amount, array $weights): array
    {
        $totalWeight = array_reduce($weights, fn (string $total, string $weight): string => bcadd($total, $weight, 8), '0.00000000');
        if (bccomp($totalWeight, '0.00000000', 8) <= 0) {
            throw new DomainException(__('hr_payroll.messages.dated_branch_allocation_required'));
        }

        $result = [];
        $remaining = $amount;
        $lastBranchId = array_key_last(array_filter(
            $weights,
            fn (string $weight): bool => bccomp($weight, '0.00000000', 8) > 0,
        ));
        foreach ($weights as $branchId => $weight) {
            if (bccomp($weight, '0.00000000', 8) <= 0) {
                $result[(int) $branchId] = '0.0000';

                continue;
            }
            $part = $branchId === $lastBranchId
                ? $remaining
                : bcadd(bcdiv(bcmul($amount, $weight, 12), $totalWeight, 12), '0.00005', 4);
            if (bccomp($part, $remaining, 4) > 0) {
                $part = $remaining;
            }
            $result[(int) $branchId] = $part;
            $remaining = bcsub($remaining, $part, 4);
        }

        return $result;
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

    private function period(int $companyId, string $start, string $end, ?int $branchId): object
    {
        $period = DB::table('hr_payroll_periods')
            ->where('company_id', $companyId)
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if ($period === null) {
            if (DB::table('hr_payroll_periods')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereDate('period_start', '<=', $end)
                ->whereDate('period_end', '>=', $start)
                ->exists()) {
                throw new DomainException(__('hr_payroll.messages.overlapping_period'));
            }

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

        return $period;
    }

    private function run(int $periodId, ?int $branchId, bool $periodClosed): object
    {
        $query = DB::table('hr_payroll_runs')
            ->where('payroll_period_id', $periodId)
            ->where('status', '!=', 'reversed')
            ->whereNull('deleted_at')
            ->when($branchId === null, fn ($builder) => $builder->whereNull('branch_id'), fn ($builder) => $builder->where('branch_id', $branchId));
        $run = $query->lockForUpdate()->first();

        if ($run !== null) {
            if ($run->correction_of_run_id !== null) {
                $this->assertReplacementCorrection((int) $run->correction_of_run_id, $periodClosed);
            } elseif ($periodClosed) {
                throw new DomainException(__('hr_payroll.messages.period_closed'));
            }

            return $run;
        }

        $original = DB::table('hr_payroll_runs')->where('payroll_period_id', $periodId)
            ->where('status', 'reversed')->whereNull('deleted_at')
            ->when($branchId === null, fn ($builder) => $builder->whereNull('branch_id'), fn ($builder) => $builder->where('branch_id', $branchId))
            ->orderByDesc('id')->first();
        if ($original !== null) {
            $this->assertReplacementCorrection((int) $original->id, $periodClosed);
        } elseif ($periodClosed) {
            throw new DomainException(__('hr_payroll.messages.period_closed'));
        }
        $id = DB::table('hr_payroll_runs')->insertGetId([
            'payroll_period_id' => $periodId,
            'branch_id' => $branchId,
            'correction_of_run_id' => $original?->id,
            'posting_date' => $original?->reversal_date,
            'posting_financial_period_id' => $original === null ? null : DB::table('journal_entries')->where('id', $original->reversal_journal_entry_id)->value('financial_period_id'),
            'status' => 'draft',
            'created_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('hr_payroll_runs')->where('id', $id)->first();
    }

    private function assertReplacementCorrection(int $originalId, bool $required): void
    {
        $correction = DB::table('hr_payroll_corrections')->where('payroll_run_id', $originalId)->where('status', 'approved')->orderByDesc('id')->first();
        if ($correction === null) {
            if ($required) {
                throw new DomainException(__('hr_payroll.messages.period_closed'));
            }

            return;
        }
        $this->assertReplacementPostingPeriod($correction);
    }

    private function assertReplacementPostingPeriod(object $correction): void
    {
        $periodId = (int) session(OperatingContextService::FinancialPeriodIdKey);
        $companyDocNum = Company::query()->whereKey($correction->company_id)->value('doc_num');
        abort_unless(app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery(auth()->user(), [$companyDocNum])
            ->where('financial_periods.id', $periodId)->exists(), 403);
        $period = FinancialPeriod::query()->whereKey($periodId)->where('company_id', $correction->company_id)->where('is_closed', false)
            ->whereDate('from_date', '<=', $correction->reversal_date)->whereDate('to_date', '>=', $correction->reversal_date)->lockForUpdate()->first();
        if ($period === null || (int) $period->id !== (int) $correction->financial_period_id) {
            throw new DomainException(__('hr_payroll_correction.period_changed'));
        }
    }

    private function employees(int $companyId, ?int $branchId, string $start, string $end): Collection
    {
        return HrEmployee::query()
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->assignedToPayrollBranchesDuring([$branchId], $start, $end))
            ->eligibleForPayrollPeriod($start, $end)
            ->orderBy('doc_num')
            ->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function salarySegments(HrEmployee $employee, string $start, string $end, ?int $branchId): Collection
    {
        $assignments = DB::table('hr_employee_salary_assignments')
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->whereNull('deleted_at')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($assignments->isEmpty()) {
            $assignments = collect([(object) [
                'id' => null,
                'source_type' => 'employee_master',
                'source_id' => (int) $employee->getKey(),
                'effective_from' => $employee->hire_date?->toDateString(),
                'effective_to' => $employee->contract_end_date?->toDateString(),
                'basic_salary' => $employee->basic_salary,
                'pay_basis' => null,
                'components' => null,
            ]]);
        }

        if ($assignments->count() > 1
            && $assignments->contains(fn (object $assignment): bool => $assignment->pay_basis === null)
            && ($employee->pay_basis !== 'monthly_salary'
                || $assignments->contains(fn (object $assignment): bool => $assignment->pay_basis !== null && $assignment->pay_basis !== 'monthly_salary'))) {
            throw new DomainException(__('hr_payroll.messages.multiple_salary_assignments_require_split', [
                'employee' => $employee->doc_num,
            ]));
        }

        $organizations = $this->organizationSegments($employee, $start, $end);
        $segments = collect();
        $previousEnd = null;

        foreach ($assignments as $assignment) {
            $payBasis = (string) ($assignment->pay_basis ?? $employee->pay_basis);
            if ($assignment->id !== null) {
                $assignment->source_type = 'salary_assignment';
                $assignment->source_id = (int) $assignment->id;
            }
            $salaryFrom = max($start, (string) ($assignment->effective_from ?? $start));
            $salaryTo = min($end, (string) ($assignment->effective_to ?? $end));
            if ($previousEnd !== null && $salaryFrom !== CarbonImmutable::parse($previousEnd)->addDay()->toDateString()) {
                throw new DomainException(__('hr_payroll.messages.multiple_salary_assignments_require_split', [
                    'employee' => $employee->doc_num,
                ]));
            }
            $previousEnd = $salaryTo;

            $components = $this->components($assignment->components);
            if ($payBasis !== 'monthly_salary' && $components['items'] !== []) {
                throw new DomainException(__('hr_payroll.messages.accrual_components_unsupported', [
                    'employee' => $employee->doc_num,
                    'pay_basis' => $payBasis,
                ]));
            }

            foreach ($organizations as $organization) {
                $from = max($salaryFrom, (string) $organization->effective_from);
                $to = min($salaryTo, (string) ($organization->effective_to ?? $end));
                if ($from > $to || ($branchId !== null && (int) $organization->branch_id !== $branchId)) {
                    continue;
                }

                $datedEmployee = clone $employee;
                $datedEmployee->forceFill([
                    'branch_id' => $organization->branch_id,
                    'department_id' => $organization->department_id,
                    'pay_basis' => $payBasis,
                ]);
                $payRate = $this->accruals->rate($datedEmployee, $assignment, $payBasis);
                $effects = $this->attendanceEffects->calculate(
                    $datedEmployee,
                    $from,
                    $to,
                    $payRate,
                    $components['overtime_hourly_rate'] ?: ($payBasis === 'hourly_wage' ? $payRate : $employee->hourly_wage),
                    $payBasis,
                );

                $segments->push([
                    'assignment' => $assignment,
                    'organization_assignment_id' => $organization->id,
                    'branch_id' => (int) $organization->branch_id,
                    'department_id' => $organization->department_id,
                    'cost_center_id' => $organization->cost_center_id,
                    'employee' => $datedEmployee,
                    'components' => $components,
                    'accrual' => $this->accruals->calculate($datedEmployee, $assignment, $from, $to, $effects),
                    'effects' => $effects,
                    'from' => $from,
                    'to' => $to,
                ]);
            }
        }

        return $segments->sortBy('from')->values();
    }

    /** @return Collection<int, object> */
    private function organizationSegments(HrEmployee $employee, string $start, string $end): Collection
    {
        $assignments = DB::table('hr_employee_organization_assignments')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($assignments->isEmpty()) {
            if (DB::table('hr_employee_organization_assignments')->where('employee_id', $employee->getKey())->exists()) {
                throw new DomainException(__('hr_payroll.messages.organization_assignment_gap', ['employee' => $employee->doc_num]));
            }

            return collect([(object) [
                'id' => null,
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id,
                'cost_center_id' => null,
                'effective_from' => $start,
                'effective_to' => $end,
            ]]);
        }

        $coverageStart = max(array_filter([
            $start,
            $employee->hire_date?->toDateString(),
            $employee->contract_start_date?->toDateString(),
        ]));
        $coverageEnd = min(array_filter([
            $end,
            $employee->contract_end_date?->toDateString(),
            $employee->termination_date?->toDateString(),
        ]));
        $nextDate = $coverageStart;
        foreach ($assignments as $assignment) {
            $from = max($coverageStart, (string) $assignment->effective_from);
            $to = min($coverageEnd, (string) ($assignment->effective_to ?? $coverageEnd));
            if ($from > $to) {
                continue;
            }
            if ($from !== $nextDate) {
                throw new DomainException(__('hr_payroll.messages.organization_assignment_gap', ['employee' => $employee->doc_num]));
            }
            $nextDate = CarbonImmutable::parse($to)->addDay()->toDateString();
        }
        if ($nextDate <= $coverageEnd) {
            throw new DomainException(__('hr_payroll.messages.organization_assignment_gap', ['employee' => $employee->doc_num]));
        }

        return $assignments;
    }

    /** @param Collection<int, array<string, mixed>> $segments @return array<string, mixed> */
    private function combinedEffects(HrEmployee $employee, Collection $segments, string $start, string $end): array
    {
        if ($segments->count() === 1) {
            return $segments->first()['effects'];
        }

        $first = $segments->first();
        $fullPeriodEffects = $this->attendanceEffects->calculate(
            $first['employee'],
            $start,
            $end,
            $first['accrual']['rate'],
            $first['components']['overtime_hourly_rate'] ?: ($first['accrual']['pay_basis'] === 'hourly_wage' ? $first['accrual']['rate'] : $employee->hourly_wage),
            (string) $first['accrual']['pay_basis'],
        );
        if ($segments->sum(fn (array $segment): int => $segment['effects']['overtime']['minutes'])
            > $fullPeriodEffects['overtime']['minutes']) {
            throw new DomainException(__('hr_payroll.messages.overtime_approval_requires_dated_allocation', [
                'employee' => $employee->doc_num,
            ]));
        }
        $remainingOvertimeMinutes = $fullPeriodEffects['overtime']['minutes'];
        $rawOvertimeTotal = '0.000000000000';
        $effects = $first['effects'];
        foreach (['record_ids', 'effect_record_ids', 'finalized_records'] as $key) {
            $effects['attendance'][$key] = [];
        }
        foreach (['finalized_days', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'recorded_overtime_minutes'] as $key) {
            $effects['attendance'][$key] = 0;
        }
        foreach (['approved_request_ids', 'canonical_leave_request_ids', 'canonical_leave_sources', 'paid_leave_days', 'policy_snapshots', 'deductions'] as $key) {
            $effects[$key] = [];
        }
        foreach ($effects['summary'] as $key => $value) {
            $effects['summary'][$key] = 0;
        }
        $effects['overtime']['minutes'] = 0;
        $effects['overtime']['amount'] = '0.0000';
        $effects['overtime']['request_ids'] = [];
        $effects['overtime']['rate_segments'] = [];
        $effects['attendance']['salary_segments'] = [];

        foreach ($segments as $segment) {
            $part = $segment['effects'];
            foreach (['record_ids', 'effect_record_ids', 'finalized_records'] as $key) {
                $effects['attendance'][$key] = array_merge($effects['attendance'][$key], $part['attendance'][$key]);
            }
            foreach (['finalized_days', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'recorded_overtime_minutes'] as $key) {
                $effects['attendance'][$key] += $part['attendance'][$key];
            }
            foreach (['approved_request_ids', 'canonical_leave_request_ids', 'canonical_leave_sources', 'paid_leave_days', 'policy_snapshots', 'deductions'] as $key) {
                $effects[$key] = array_merge($effects[$key], $part[$key]);
            }
            foreach ($part['summary'] as $key => $value) {
                $effects['summary'][$key] += $value;
            }
            $minutes = min($remainingOvertimeMinutes, $part['overtime']['minutes']);
            $remainingOvertimeMinutes -= $minutes;
            $rawAmount = bcmul(bcdiv((string) $minutes, '60', 12), (string) $part['overtime']['hourly_rate'], 12);
            $rawOvertimeTotal = bcadd($rawOvertimeTotal, $rawAmount, 12);
            $effects['overtime']['minutes'] += $minutes;
            $effects['overtime']['request_ids'] = array_merge($effects['overtime']['request_ids'], $part['overtime']['request_ids']);
            $effects['overtime']['rate_segments'][] = [
                'from' => $segment['from'],
                'to' => $segment['to'],
                'minutes' => $minutes,
                'hourly_rate' => $part['overtime']['hourly_rate'],
                'raw_amount' => $rawAmount,
                'request_ids' => $part['overtime']['request_ids'],
                'organization_assignment_id' => $segment['organization_assignment_id'],
                'branch_id' => $segment['branch_id'],
                'department_id' => $segment['department_id'],
                'cost_center_id' => $segment['cost_center_id'],
            ];
            $effects['attendance']['salary_segments'][] = [
                'from' => $segment['from'],
                'to' => $segment['to'],
                'assignment_id' => $segment['assignment']->source_id,
            ];
        }

        $effects['approved_request_ids'] = array_values(array_unique($effects['approved_request_ids']));
        $effects['canonical_leave_request_ids'] = array_values(array_unique($effects['canonical_leave_request_ids']));
        $effects['canonical_leave_sources'] = collect($effects['canonical_leave_sources'])->unique('leave_day_id')->sortBy('leave_day_id')->values()->all();
        $effects['overtime']['request_ids'] = array_values(array_unique($effects['overtime']['request_ids']));
        $effects['policy_snapshots'] = collect($effects['policy_snapshots'])->unique('id')->values()->all();
        $effects['deductions'] = collect($effects['deductions'])
            ->groupBy(fn (array $deduction): string => implode(':', [
                $deduction['policy_id'],
                $deduction['effect_type'],
                $deduction['payroll_item_code'],
            ]))
            ->map(function (Collection $group): array {
                $deduction = $group->first();
                $unrounded = $group->reduce(
                    fn (string $total, array $part): string => bcadd($total, (string) $part['snapshot']['unrounded_amount'], 8),
                    '0.00000000',
                );
                $deduction['amount'] = $this->attendanceEffects->finalizeDeductionAmount($unrounded, $deduction['snapshot']['policy'], $deduction['effect_type']);
                $deduction['snapshot']['amount'] = $deduction['amount'];
                $deduction['snapshot']['unrounded_amount'] = $unrounded;
                $deduction['snapshot']['rate_segments'] = $group->map(fn (array $part): array => $part['snapshot'])->values()->all();

                return $deduction;
            })
            ->filter(fn (array $deduction): bool => bccomp($deduction['amount'], '0.0000', 4) > 0)
            ->values()
            ->all();
        $effects['overtime']['amount'] = bcadd($rawOvertimeTotal, '0', 4);
        $allocated = '0.0000';
        $lastIndex = 0;
        foreach ($effects['overtime']['rate_segments'] as $index => $rateSegment) {
            if ($rateSegment['minutes'] > 0) {
                $lastIndex = $index;
            }
        }
        foreach ($effects['overtime']['rate_segments'] as $index => &$rateSegment) {
            $rateSegment['amount'] = $index === $lastIndex
                ? bcsub($effects['overtime']['amount'], $allocated, 4)
                : bcadd($rateSegment['raw_amount'], '0', 4);
            $allocated = bcadd($allocated, $rateSegment['amount'], 4);
        }
        unset($rateSegment);

        return $effects;
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

    /** @param array<string, mixed> $effects @param Collection<int, array<string, mixed>> $segments */
    private function storeAttendanceSnapshot(int $runId, HrEmployee $employee, array $effects, Collection $segments): void
    {
        $branchSummaries = [];
        foreach ($segments as $segment) {
            $branchId = (int) $segment['branch_id'];
            $part = $segment['effects'];
            $branchSummaries[$branchId] ??= [
                'record_ids' => [],
                'effect_record_ids' => [],
                'finalized_records' => [],
                'finalized_days' => 0,
                'worked_minutes' => 0,
                'late_minutes' => 0,
                'early_leave_minutes' => 0,
                'recorded_overtime_minutes' => 0,
                'salary_segments' => [],
                'approved_request_ids' => [],
                'canonical_leave_request_ids' => [],
                'paid_leave_days' => [],
                'policy_snapshots' => [],
                'summary' => [],
            ];
            foreach (['record_ids', 'effect_record_ids', 'finalized_records'] as $key) {
                $branchSummaries[$branchId][$key] = array_merge($branchSummaries[$branchId][$key], $part['attendance'][$key] ?? []);
            }
            foreach (['finalized_days', 'worked_minutes', 'late_minutes', 'early_leave_minutes', 'recorded_overtime_minutes'] as $key) {
                $branchSummaries[$branchId][$key] += (int) ($part['attendance'][$key] ?? 0);
            }
            foreach (['approved_request_ids', 'canonical_leave_request_ids', 'paid_leave_days', 'policy_snapshots'] as $key) {
                $branchSummaries[$branchId][$key] = array_merge($branchSummaries[$branchId][$key], $part[$key] ?? []);
            }
            foreach ($part['summary'] ?? [] as $key => $value) {
                $branchSummaries[$branchId]['summary'][$key] = ($branchSummaries[$branchId]['summary'][$key] ?? 0) + $value;
            }
            $branchSummaries[$branchId]['salary_segments'][] = [
                'from' => $segment['from'],
                'to' => $segment['to'],
                'assignment_id' => $segment['assignment']->source_id,
                'organization_assignment_id' => $segment['organization_assignment_id'],
            ];
        }
        foreach ($branchSummaries as &$branchSummary) {
            foreach (['record_ids', 'effect_record_ids', 'approved_request_ids', 'canonical_leave_request_ids'] as $key) {
                $branchSummary[$key] = array_values(array_unique($branchSummary[$key]));
            }
            $branchSummary['policy_snapshots'] = collect($branchSummary['policy_snapshots'])->unique('id')->values()->all();
        }
        unset($branchSummary);
        DB::table('hr_payroll_attendance_inputs')->insert([
            'payroll_run_id' => $runId,
            'employee_id' => $employee->getKey(),
            'payload' => json_encode([
                ...$effects['attendance'],
                'policy_snapshots' => $effects['policy_snapshots'],
                'summary' => $effects['summary'],
                'canonical_leave_request_ids' => $effects['canonical_leave_request_ids'],
                'canonical_leave_sources' => $effects['canonical_leave_sources'],
                'paid_leave_days' => $effects['paid_leave_days'],
                'branch_summaries' => $branchSummaries,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, string> $target @param array<string, string> $source */
    private function mergeDatedEarnings(array &$target, array $source): void
    {
        foreach ($source as $date => $amount) {
            $target[$date] = bcadd($target[$date] ?? '0.0000', $amount, 4);
        }
    }

    /** @param array<int|string, int|string> $weights @return array<int|string, string> */
    private function distributeByWeights(string $amount, array $weights): array
    {
        $totalWeight = array_reduce($weights, fn (string $total, int|string $weight): string => bcadd($total, (string) $weight, 12), '0.000000000000');
        if ($weights === [] || bccomp($totalWeight, '0', 12) <= 0) {
            throw new DomainException(__('hr_payroll.messages.statutory_earning_dates_required'));
        }

        $distributed = [];
        $remaining = $amount;
        $lastKey = array_key_last($weights);
        foreach ($weights as $key => $weight) {
            $part = $key === $lastKey
                ? $remaining
                : bcadd(bcdiv(bcmul($amount, (string) $weight, 12), $totalWeight, 12), '0.00005', 4);
            $distributed[$key] = $part;
            $remaining = bcsub($remaining, $part, 4);
        }

        return $distributed;
    }

    /** @param list<array<string, mixed>> $records @return array<string, string> */
    private function overtimeByDate(string $amount, int $approvedMinutes, array $records, string $from, string $to): array
    {
        $remainingMinutes = $approvedMinutes;
        $weights = [];
        foreach ($records as $record) {
            if ($record['work_date'] < $from || $record['work_date'] > $to || $remainingMinutes <= 0) {
                continue;
            }
            $minutes = min($remainingMinutes, (int) $record['overtime_minutes']);
            if ($minutes <= 0) {
                continue;
            }
            $weights[$record['work_date']] = ($weights[$record['work_date']] ?? 0) + $minutes;
            $remainingMinutes -= $minutes;
        }
        if ($remainingMinutes !== 0) {
            throw new DomainException(__('hr_payroll.messages.statutory_earning_dates_required'));
        }

        return $this->distributeByWeights($amount, $weights);
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
        return $this->numbers->normalizeToScale($value, 4) ?? '0.0000';
    }
}
