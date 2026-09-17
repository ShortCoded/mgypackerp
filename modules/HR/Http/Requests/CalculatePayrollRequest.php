<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

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
}
