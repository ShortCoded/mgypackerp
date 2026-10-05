<?php

namespace Modules\Purchases\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelPurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->routeIs('admin.purchases.purchase-invoices.reverse') ? 'reverse' : 'cancel';

        return (bool) $this->user()?->can('purchase_invoices.'.$ability);
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'cancel_reason' => __('purchase_invoices.attributes.cancel_reason'),
        ];
    }
}
