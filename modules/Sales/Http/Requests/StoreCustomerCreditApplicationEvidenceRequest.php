<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class StoreCustomerCreditApplicationEvidenceRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_credits.prepare_application_evidence');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('schedules')) {
            $this->merge(['schedules' => []]);
        }
        $this->normalizeNumericInput(['schedules.*.amount']);
    }

    /** @return array<string, array<mixed>|string> */
    public function rules(): array
    {
        return [
            'source_fingerprint' => ['required', 'string', 'size:64'],
            'source_reference' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:3000'],
            'schedules' => ['present', 'array', 'max:500'],
            'schedules.*' => ['array:schedule_id,amount'],
            'schedules.*.schedule_id' => ['required', 'integer', 'min:1', 'distinct'],
            'schedules.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,4})?$/D'],
        ];
    }
}
