<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\HrEmployeeServiceRequest;

class LeaveRequestReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee' => ['nullable', 'string', 'max:150'],
            'branch_doc_num' => ['nullable', 'string', 'max:100'],
            'department_doc_num' => ['nullable', 'string', 'max:100'],
            'request_type' => ['nullable', Rule::in(HrEmployeeServiceRequest::types())],
            'leave_type_code' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                HrEmployeeServiceRequest::StatusSubmitted,
                HrEmployeeServiceRequest::StatusApproved,
                HrEmployeeServiceRequest::StatusRejected,
                HrEmployeeServiceRequest::StatusCancelled,
            ])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'approver_id' => ['nullable', 'integer', 'min:1'],
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
