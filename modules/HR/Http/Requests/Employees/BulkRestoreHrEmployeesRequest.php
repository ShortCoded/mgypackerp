<?php

namespace Modules\HR\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

class BulkRestoreHrEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employees.restore');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);

        return [
            'public_uuids' => ['required', 'array', 'min:1'],
            'public_uuids.*' => [
                'uuid',
                Rule::exists('hr_employees', 'public_uuid')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->whereNotNull('deleted_at'),
            ],
        ];
    }
}
