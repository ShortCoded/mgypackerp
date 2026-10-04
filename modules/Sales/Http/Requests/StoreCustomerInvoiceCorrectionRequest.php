<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreCustomerInvoiceCorrectionRequest extends FormRequest
{
    use NormalizesNumericInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_invoices.correct_prepare');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('posting_date'))) {
            $this->merge(['posting_date' => app(DateFormatService::class)->normalizeForStorage($this->input('posting_date')) ?? $this->input('posting_date')]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'source_fingerprint' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:3000'],
            'recovery_reference' => ['required', 'string', 'max:255'],
        ];
    }
}
