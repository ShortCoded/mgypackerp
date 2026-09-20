<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.attendance_settings.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attendance_latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:attendance_longitude'],
            'attendance_longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:attendance_latitude'],
            'attendance_radius_meters' => ['required', 'integer', 'min:10', 'max:10000'],
            'attendance_max_accuracy_meters' => ['required', 'integer', 'min:5', 'max:5000'],
            'attendance_location_policy' => ['required', 'string', Rule::in(['allow', 'warn', 'reject'])],
        ];
    }
}
