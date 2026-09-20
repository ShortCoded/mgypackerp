<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;

class CalculatePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.payroll_preparation.calculate');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);

        return [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'branch_doc_num' => [
                'nullable',
                'string',
                Rule::exists('branches', 'doc_num')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'adjustments' => ['nullable', 'array'],
            'adjustments.*' => ['array:employee_doc_num,deductions,advance_applications'],
            'adjustments.*.employee_doc_num' => [
                'required',
                'string',
                'distinct',
                Rule::exists('hr_employees', 'doc_num')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'adjustments.*.deductions' => ['nullable', 'array'],
            'adjustments.*.deductions.*' => ['array:payroll_item_code,amount,reference'],
            'adjustments.*.deductions.*.payroll_item_code' => [
                'required',
                'string',
                Rule::exists('hr_payroll_items', 'code')->where('item_kind', 'deduction')->where('status', 'active')->whereNull('deleted_at'),
            ],
            'adjustments.*.deductions.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'adjustments.*.deductions.*.reference' => ['nullable', 'string', 'max:255'],
            'adjustments.*.advance_applications' => ['nullable', 'array'],
            'adjustments.*.advance_applications.*' => ['array:salary_advance_id,payroll_item_code,amount'],
            'adjustments.*.advance_applications.*.salary_advance_id' => ['required', 'integer', 'distinct', 'exists:hr_salary_advances,id'],
            'adjustments.*.advance_applications.*.payroll_item_code' => [
                'required',
                'string',
                Rule::exists('hr_payroll_items', 'code')->where('item_kind', 'deduction')->where('status', 'active')->whereNull('deleted_at'),
            ],
            'adjustments.*.advance_applications.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $company = app(OperatingCompanyContextService::class)->currentCompany($this);
            if ($company === null) {
                return;
            }

            $scope = app(OperatingScopeAccessService::class);
            $branchDocNum = $this->string('branch_doc_num')->trim()->toString();
            $branchId = null;
            if ($branchDocNum === '') {
                if (! $scope->hasUnrestrictedBranchAccess($this->user())) {
                    $validator->errors()->add('branch_doc_num', __('operating_context.validation.branch_invalid'));
                }
            } else {
                $branchId = $scope->allowedBranchQuery($this->user(), [(string) $company->doc_num])
                    ->where('branches.doc_num', $branchDocNum)
                    ->value('branches.id');
                if ($branchId === null) {
                    $validator->errors()->add('branch_doc_num', __('operating_context.validation.branch_invalid'));
                }
            }

            if (! $scope->hasUnrestrictedFinancialPeriodAccess($this->user())
                && ! $scope->allowedFinancialPeriodQuery($this->user(), [(string) $company->doc_num])
                    ->whereDate('financial_periods.from_date', '<=', $this->string('period_start')->toString())
                    ->whereDate('financial_periods.to_date', '>=', $this->string('period_end')->toString())
                    ->exists()) {
                $validator->errors()->add('period_start', __('operating_context.validation.financial_period_invalid'));
            }

            $adjustments = collect($this->input('adjustments', []));
            $employeeDocNums = $adjustments->pluck('employee_doc_num')->filter()->unique()->values();
            $employees = DB::table('hr_employees')
                ->where('company_id', $company->getKey())
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->whereNull('deleted_at')
                ->whereIn('doc_num', $employeeDocNums)
                ->pluck('id', 'doc_num');
            if ($employees->count() !== $employeeDocNums->count()) {
                $validator->errors()->add('adjustments', __('hr_payroll.messages.adjustment_scope_invalid'));
            }

            foreach ($adjustments as $index => $adjustment) {
                $employeeId = $employees->get($adjustment['employee_doc_num'] ?? '');
                $advanceIds = collect($adjustment['advance_applications'] ?? [])->pluck('salary_advance_id')->filter()->unique()->values();
                if ($advanceIds->isEmpty()) {
                    continue;
                }

                $validAdvances = DB::table('hr_salary_advances')
                    ->where('employee_id', $employeeId ?? 0)
                    ->whereIn('id', $advanceIds)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->count();
                if ($validAdvances !== $advanceIds->count()) {
                    $validator->errors()->add("adjustments.{$index}.advance_applications", __('hr_payroll.messages.advance_scope_invalid'));
                }
            }
        }];
    }
}
