<?php

namespace Modules\Purchases\Http\Requests\PurchaseOrders;

use Modules\Purchases\Models\PurchaseOrder;

class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('purchase_orders.edit');
    }

    protected function currentRecord(): ?PurchaseOrder
    {
        $record = $this->route('purchaseOrder');

        return $record instanceof PurchaseOrder ? $record : null;
    }
}
