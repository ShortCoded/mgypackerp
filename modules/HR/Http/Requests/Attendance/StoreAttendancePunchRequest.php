<?php

namespace Modules\HR\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\HrAttendanceEvent;

class StoreAttendancePunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', Rule::in(HrAttendanceEvent::types())],
            'idempotency_key' => ['required', 'uuid'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'location_source' => ['nullable', 'string', 'max:30'],
            'client_context' => ['nullable', 'array'],
        ];
    }
}
