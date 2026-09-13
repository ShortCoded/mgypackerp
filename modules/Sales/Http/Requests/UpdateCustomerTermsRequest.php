<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customers.edit');
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'quotation_terms' => ['nullable', 'string', 'max:100000'],
            'quotation_payment_terms' => ['nullable', 'string', 'max:100000'],
            'quotation_execution_terms' => ['nullable', 'string', 'max:100000'],
            'quotation_warranty_terms' => ['nullable', 'string', 'max:100000'],
            'quotation_delivery_terms' => ['nullable', 'string', 'max:100000'],
            'quotation_technical_notes' => ['nullable', 'string', 'max:100000'],
        ];
    }
}
