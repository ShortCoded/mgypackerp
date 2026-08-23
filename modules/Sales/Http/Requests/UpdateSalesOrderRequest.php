<?php

namespace Modules\Sales\Http\Requests;

class UpdateSalesOrderRequest extends StoreSalesOrderRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_orders.edit');
    }
}
