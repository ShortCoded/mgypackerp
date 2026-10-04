<?php

namespace Modules\Sales\Http\Requests;

class UpdateSalesOrderRequest extends StoreSalesOrderRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'amendment_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D'],
        ];
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_orders.edit');
    }
}
