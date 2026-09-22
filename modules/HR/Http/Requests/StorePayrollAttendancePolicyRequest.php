<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;

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
        $this->merge([
            'branch_doc_num' => $this->filled('branch_doc_num') ? $this->string('branch_doc_num')->trim()->toString() : null,
            'deduct_absence' => $this->boolean('deduct_absence'),
            'deduct_late' => $this->boolean('deduct_late'),
            'deduct_early_leave' => $this->boolean('deduct_early_leave'),
            'deduct_unpaid_leave' => $this->boolean('deduct_unpaid_leave'),
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
        ];
    }
}
