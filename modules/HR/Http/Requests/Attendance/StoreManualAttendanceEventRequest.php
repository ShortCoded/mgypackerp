<?php

namespace Modules\HR\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrAttendanceEvent;

class StoreManualAttendanceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employee_attendance.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);

        return [
            'employee_doc_num' => [
                'required',
                'string',
                Rule::exists('hr_employees', 'doc_num')
                    ->where('company_id', $companyId ?? 0)
                    ->whereNull('deleted_at'),
            ],
            'event_type' => ['required', 'string', Rule::in(HrAttendanceEvent::types())],
            'occurred_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => ['required', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
