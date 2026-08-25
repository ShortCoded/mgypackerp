<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AllocateCustomerCreditRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_credits.allocate');
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
