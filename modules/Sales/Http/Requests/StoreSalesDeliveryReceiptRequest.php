<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesDeliveryReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_deliveries.receive');
    }

    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:160'],
            'recipient_phone' => ['nullable', 'string', 'max:80'],
            'signature' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
