<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Models\HrPayrollAttendancePolicy;

class StorePayrollAttendancePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.payroll_attendance_policies.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);

        return [
            'branch_doc_num' => [
                'nullable',
                'string',
                Rule::exists('branches', 'doc_num')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'deduct_absence' => ['nullable', 'boolean'],
            'deduct_late' => ['nullable', 'boolean'],
            'deduct_early_leave' => ['nullable', 'boolean'],
            'deduct_unpaid_leave' => ['nullable', 'boolean'],
            'monthly_partial_method' => ['nullable', Rule::in([
                HrPayrollAttendancePolicy::MonthlyCalendarDays,
                HrPayrollAttendancePolicy::MonthlyFixedDivisor,
            ])],
            'weekly_accrual_method' => ['nullable', Rule::in([
                HrPayrollAttendancePolicy::WeeklyCalendarDays,
                HrPayrollAttendancePolicy::WeeklyFinalizedAttendance,
                HrPayrollAttendancePolicy::WeeklyFinalizedAttendanceOrPaidLeave,
                HrPayrollAttendancePolicy::WeeklyScheduledWork,
                HrPayrollAttendancePolicy::WeeklyScheduledWorkAndPaidHoliday,
            ])],
            'weekly_work_days' => ['nullable', 'integer', 'min:1', 'max:7'],
            'daily_accrual_method' => ['nullable', Rule::in([
                HrPayrollAttendancePolicy::DailyFinalizedAttendance,
                HrPayrollAttendancePolicy::DailyFinalizedAttendanceOrPaidLeave,
                HrPayrollAttendancePolicy::DailyCalendarDays,
                HrPayrollAttendancePolicy::DailyScheduledWork,
                HrPayrollAttendancePolicy::DailyScheduledWorkAndPaidHoliday,
            ])],
            'hourly_accrual_method' => ['nullable', Rule::in([
                HrPayrollAttendancePolicy::HourlyFinalizedMinutes,
                HrPayrollAttendancePolicy::HourlyFinalizedMinutesOrPaidLeave,
            ])],
            'hourly_rounding_mode' => ['nullable', Rule::in(['none', 'down', 'nearest', 'up'])],
            'hourly_rounding_increment_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'shift_accrual_method' => ['nullable', Rule::in([
                HrPayrollAttendancePolicy::ShiftFinalizedAttendance,
                HrPayrollAttendancePolicy::ShiftFinalizedAttendanceOrPaidLeave,
            ])],
            'piece_accrual_method' => ['nullable', Rule::in([HrPayrollAttendancePolicy::PieceApprovedOutput])],
            'salary_day_divisor' => ['required', 'integer', 'min:1', 'max:366'],
            'standard_day_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'deduction_payroll_item_code' => [
                'nullable',
                'string',
                Rule::exists('hr_payroll_items', 'code')
                    ->where('item_kind', 'deduction')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'deduction_rules' => ['nullable', 'array:absence,late,early_leave,unpaid_leave'],
            'deduction_rules.*' => ['array:method,value,cap,tiers'],
            'deduction_rules.*.method' => ['required', Rule::in(['salary_time', 'fixed', 'percentage', 'tiered'])],
            'deduction_rules.*.value' => ['nullable', 'decimal:0,4', 'min:0'],
            'deduction_rules.*.cap' => ['nullable', 'decimal:0,2', 'min:0'],
            'deduction_rules.*.tiers' => ['nullable', 'array', 'max:10'],
            'deduction_rules.*.tiers.*' => ['array:up_to,amount'],
            'deduction_rules.*.tiers.*.up_to' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'deduction_rules.*.tiers.*.amount' => ['required_with:deduction_rules.*.tiers', 'decimal:0,2', 'min:0'],
            'same_day_late_early_mode' => ['nullable', Rule::in(['sum', 'higher', 'late_first', 'early_first'])],
            'deduction_rounding_mode' => ['nullable', Rule::in(['half_up', 'down', 'up'])],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $enabled = collect(['deduct_absence', 'deduct_late', 'deduct_early_leave', 'deduct_unpaid_leave'])
                ->contains(fn (string $field): bool => $this->boolean($field));

            if ($enabled && blank($this->input('deduction_payroll_item_code'))) {
                $validator->errors()->add('deduction_payroll_item_code', __('hr_payroll_policies.validation.deduction_item_required'));
            }

            if (in_array($this->input('weekly_accrual_method'), [
                HrPayrollAttendancePolicy::WeeklyFinalizedAttendance,
                HrPayrollAttendancePolicy::WeeklyFinalizedAttendanceOrPaidLeave,
                HrPayrollAttendancePolicy::WeeklyScheduledWork,
                HrPayrollAttendancePolicy::WeeklyScheduledWorkAndPaidHoliday,
            ], true)
                && ! $this->filled('weekly_work_days')) {
                $validator->errors()->add('weekly_work_days', __('hr_payroll_policies.validation.weekly_work_days_required'));
            }

            $hourlyRounding = $this->input('hourly_rounding_mode');
            if (in_array($hourlyRounding, ['down', 'nearest', 'up'], true)
                && ! $this->filled('hourly_rounding_increment_minutes')) {
                $validator->errors()->add('hourly_rounding_increment_minutes', __('hr_payroll_policies.validation.hourly_rounding_increment_required'));
            }
            if ($this->filled('hourly_rounding_increment_minutes')
                && ! in_array($hourlyRounding, ['down', 'nearest', 'up'], true)) {
                $validator->errors()->add('hourly_rounding_mode', __('hr_payroll_policies.validation.hourly_rounding_mode_required'));
            }

            foreach ((array) $this->input('deduction_rules', []) as $effectType => $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                $method = $rule['method'] ?? 'salary_time';
                if (in_array($method, ['fixed', 'percentage'], true) && ! isset($rule['value'])) {
                    $validator->errors()->add("deduction_rules.{$effectType}.value", __('hr_payroll_policies.validation.deduction_value_required'));
                }
                if ($method === 'percentage' && isset($rule['value']) && bccomp((string) $rule['value'], '100', 4) > 0) {
                    $validator->errors()->add("deduction_rules.{$effectType}.value", __('hr_payroll_policies.validation.percentage_limit'));
                }
                if ($method !== 'tiered') {
                    continue;
                }
                if (! in_array($effectType, ['late', 'early_leave'], true)) {
                    $validator->errors()->add("deduction_rules.{$effectType}.method", __('hr_payroll_policies.validation.tiers_minutes_only'));

                    continue;
                }
                $tiers = $rule['tiers'] ?? [];
                if (! is_array($tiers) || $tiers === []) {
                    $validator->errors()->add("deduction_rules.{$effectType}.tiers", __('hr_payroll_policies.validation.tiers_required'));

                    continue;
                }
                $previous = 0;
                foreach ($tiers as $index => $tier) {
                    $upTo = $tier['up_to'] ?? null;
                    if ($upTo === null) {
                        if ($index !== array_key_last($tiers)) {
                            $validator->errors()->add("deduction_rules.{$effectType}.tiers.{$index}.up_to", __('hr_payroll_policies.validation.tiers_order'));
                        }

                        continue;
                    }
                    if ((int) $upTo <= $previous) {
                        $validator->errors()->add("deduction_rules.{$effectType}.tiers.{$index}.up_to", __('hr_payroll_policies.validation.tiers_order'));
                    }
                    $previous = (int) $upTo;
                }
                if (end($tiers)['up_to'] ?? null) {
                    $validator->errors()->add("deduction_rules.{$effectType}.tiers", __('hr_payroll_policies.validation.tiers_open_end'));
                }
            }

            if (! $this->filled('branch_doc_num')) {
                if (! app(OperatingScopeAccessService::class)->hasUnrestrictedBranchAccess($this->user())) {
                    $validator->errors()->add('branch_doc_num', __('hr_payroll_policies.validation.company_policy_forbidden'));
                }

                return;
            }

            $company = app(OperatingCompanyContextService::class)->currentCompany($this);
            if ($company === null || ! app(OperatingScopeAccessService::class)
                ->allowedBranchQuery($this->user(), [(string) $company->doc_num])
                ->where('branches.doc_num', $this->string('branch_doc_num')->trim()->toString())
                ->exists()) {
                $validator->errors()->add('branch_doc_num', __('hr_payroll_policies.validation.branch_forbidden'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $number = app(NumericFormatService::class);
        $rules = $this->input('deduction_rules', []);
        if (is_array($rules)) {
            foreach ($rules as &$rule) {
                if (! is_array($rule)) {
                    continue;
                }
                foreach (['value', 'cap'] as $field) {
                    if (array_key_exists($field, $rule)) {
                        $rule[$field] = $number->normalizeForValidation($rule[$field]);
                    }
                }
                if (isset($rule['tiers']) && is_array($rule['tiers'])) {
                    $rule['tiers'] = array_values(array_filter($rule['tiers'], fn (mixed $tier): bool => is_array($tier) && (filled($tier['up_to'] ?? null) || filled($tier['amount'] ?? null))));
                    foreach ($rule['tiers'] as &$tier) {
                        $tier['up_to'] = filled($tier['up_to'] ?? null) ? $number->normalizeForValidation($tier['up_to']) : null;
                        $tier['amount'] = $number->normalizeForValidation($tier['amount'] ?? null);
                    }
                    unset($tier);
                }
            }
            unset($rule);
        }
        $this->merge([
            'deduction_rules' => $rules,
            'branch_doc_num' => $this->filled('branch_doc_num') ? $this->string('branch_doc_num')->trim()->toString() : null,
            'deduct_absence' => $this->boolean('deduct_absence'),
            'deduct_late' => $this->boolean('deduct_late'),
            'deduct_early_leave' => $this->boolean('deduct_early_leave'),
            'deduct_unpaid_leave' => $this->boolean('deduct_unpaid_leave'),
            'monthly_partial_method' => $this->nullableString('monthly_partial_method'),
            'weekly_accrual_method' => $this->nullableString('weekly_accrual_method'),
            'weekly_work_days' => $this->filled('weekly_work_days')
                ? $number->normalizeForValidation($this->input('weekly_work_days'))
                : null,
            'daily_accrual_method' => $this->nullableString('daily_accrual_method'),
            'hourly_accrual_method' => $this->nullableString('hourly_accrual_method'),
            'hourly_rounding_mode' => $this->nullableString('hourly_rounding_mode'),
            'hourly_rounding_increment_minutes' => $this->filled('hourly_rounding_increment_minutes')
                ? $number->normalizeForValidation($this->input('hourly_rounding_increment_minutes'))
                : null,
            'shift_accrual_method' => $this->nullableString('shift_accrual_method'),
            'piece_accrual_method' => $this->nullableString('piece_accrual_method'),
            'deduction_payroll_item_code' => $this->filled('deduction_payroll_item_code')
                ? strtoupper($this->string('deduction_payroll_item_code')->trim()->toString())
                : null,
        ]);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'branch_doc_num' => __('hr_payroll_policies.fields.branch'),
            'effective_from' => __('hr_payroll_policies.fields.effective_from'),
            'deduction_payroll_item_code' => __('hr_payroll_policies.fields.deduction_payroll_item_code'),
            'salary_day_divisor' => __('hr_payroll_policies.fields.salary_day_divisor'),
            'standard_day_minutes' => __('hr_payroll_policies.fields.standard_day_minutes'),
            'monthly_partial_method' => __('hr_payroll_policies.fields.monthly_partial_method'),
            'weekly_accrual_method' => __('hr_payroll_policies.fields.weekly_accrual_method'),
            'weekly_work_days' => __('hr_payroll_policies.fields.weekly_work_days'),
            'daily_accrual_method' => __('hr_payroll_policies.fields.daily_accrual_method'),
            'hourly_accrual_method' => __('hr_payroll_policies.fields.hourly_accrual_method'),
            'hourly_rounding_mode' => __('hr_payroll_policies.fields.hourly_rounding_mode'),
            'hourly_rounding_increment_minutes' => __('hr_payroll_policies.fields.hourly_rounding_increment_minutes'),
            'shift_accrual_method' => __('hr_payroll_policies.fields.shift_accrual_method'),
            'piece_accrual_method' => __('hr_payroll_policies.fields.piece_accrual_method'),
        ];
    }

    private function nullableString(string $key): ?string
    {
        return $this->filled($key) ? $this->string($key)->trim()->toString() : null;
    }
}
