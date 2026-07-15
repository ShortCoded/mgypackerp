<?php

namespace Modules\HR\Http\Requests\Employees;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteHrEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employees.delete');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'string',
                Rule::exists('hr_employees', 'doc_num')
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
