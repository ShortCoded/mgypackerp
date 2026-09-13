<?php

namespace Modules\HR\Http\Requests\SelfService;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Models\HrEmployeeServiceRequest;

class ReviewEmployeeServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.hr_requests.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in([HrEmployeeServiceRequest::StatusApproved, HrEmployeeServiceRequest::StatusRejected])],
            'resolution_notes' => ['nullable', 'string', 'max:3000', Rule::requiredIf(fn (): bool => $this->input('decision') === HrEmployeeServiceRequest::StatusRejected)],
        ];
    }
}
