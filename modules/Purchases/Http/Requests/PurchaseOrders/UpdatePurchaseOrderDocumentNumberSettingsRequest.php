<?php

namespace Modules\Purchases\Http\Requests\PurchaseOrders;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseOrderDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('purchase_orders.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['nullable', 'string', 'max:20'],
            'padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }
}
