<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayrollReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'branch_doc_num' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
            'employee' => ['nullable', 'string', 'max:150'],
            'run_id' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_filter($this->validated(), static fn (mixed $value, string $key): bool => $key !== 'format' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);
    }
}
