<?php

namespace Modules\HR\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\HrAttendanceSession;

class HrAttendanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employee_attendance.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee' => ['nullable', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in([HrAttendanceSession::StatusOpen, HrAttendanceSession::StatusClosed])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
