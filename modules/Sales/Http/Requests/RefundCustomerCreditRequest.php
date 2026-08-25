<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Models\CustomerCreditRefund;

class RefundCustomerCreditRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_credits.refund');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'idempotency_key' => ['nullable', 'uuid'],
            'refund_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::in([CustomerCreditRefund::MethodCash, CustomerCreditRefund::MethodBank])],
            'cashbox_doc_num' => ['nullable', 'required_if:payment_method,cash', 'string', 'max:100'],
            'bank_account_doc_num' => ['nullable', 'required_if:payment_method,bank', 'string', 'max:100'],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
