<?php

namespace Modules\HR\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

class ResolveAttendanceMapUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.attendance_settings.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'map_url' => ['required', 'url:http,https', 'max:2048'],
        ];
    }
}
