<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class AllocateCustomerCreditRequest extends FormRequest
{
    use NormalizesNumericInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_credits.allocate');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['amount']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target_invoice_doc_num' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'uuid'],
            'amount' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'allocation_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
