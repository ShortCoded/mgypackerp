<?php

namespace Modules\Purchases\Http\Requests\PurchaseOrders;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeletePurchaseOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('purchase_orders.delete');
    }

    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['required', 'string'],
        ];
    }
}
