<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_doc_num' => ['nullable', 'string', 'max:100'],
            'department_doc_num' => ['nullable', 'string', 'max:100'],
            'section_doc_num' => ['nullable', 'string', 'max:100'],
            'job_doc_num' => ['nullable', 'string', 'max:100'],
            'employment_type_doc_num' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
            'hire_from' => ['nullable', 'date_format:Y-m-d'],
            'hire_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:hire_from'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'format' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->except('format'),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
