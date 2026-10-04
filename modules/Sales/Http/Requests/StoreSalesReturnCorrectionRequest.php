<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreSalesReturnCorrectionRequest extends FormRequest
{
    use NormalizesNumericInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_returns.correct_prepare');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('posting_date'))) {
            $this->merge(['posting_date' => app(DateFormatService::class)->normalizeForStorage($this->input('posting_date')) ?? $this->input('posting_date')]);
        }
        $this->normalizeNumericInput(['lines.*.quantity']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operation' => ['required', 'in:return,allocation,refund'],
            'source_id' => ['required_if:operation,allocation,refund', 'nullable', 'integer', 'min:1'],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'source_fingerprint' => ['required', 'string', 'size:64'],
            'reason' => ['required', 'string', 'max:3000'],
            'recovery_reference' => ['required_if:operation,refund', 'nullable', 'string', 'max:255'],
            'lines' => ['required_if:operation,return', 'array', 'max:500'],
            'lines.*' => ['array:sales_return_line_id,quantity'],
            'lines.*.sales_return_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'],
        ];
    }
}
