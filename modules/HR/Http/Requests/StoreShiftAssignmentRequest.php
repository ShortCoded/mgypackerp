<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;

class StoreShiftAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.shift_assignments.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);
        $company = app(OperatingCompanyContextService::class)->currentCompany($this);
        $branchIds = $company === null || $this->user() === null
            ? []
            : app(OperatingScopeAccessService::class)
                ->allowedBranchQuery($this->user(), [(string) $company->doc_num])
                ->pluck('branches.id')
                ->all();

        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('hr_employees', 'id')
                    ->where('company_id', $companyId)
                    ->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0])
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'shift_doc_num' => [
                'required',
                'string',
                Rule::exists('hr_shifts', 'doc_num')->where('status', 'active')->whereNull('deleted_at'),
            ],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
