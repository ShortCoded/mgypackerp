<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShiftAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.shift_assignments.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_to' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
