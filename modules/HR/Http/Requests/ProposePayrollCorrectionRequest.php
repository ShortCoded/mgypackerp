<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Services\PayrollCorrectionService;

class ProposePayrollCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.payroll_approval.correct');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reversal_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'correction_mode' => ['sometimes', 'required', Rule::in([PayrollCorrectionService::ModeOriginalPeriod, PayrollCorrectionService::ModeLaterPeriod])],
        ];
    }
}
