<?php

namespace Modules\Purchases\Http\Requests;

use Modules\Purchases\Models\PurchaseInvoice;

class UpdatePurchaseInvoiceRequest extends StorePurchaseInvoiceRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('purchase_invoices.edit') && $this->isAdministrativeBranchContext();
    }

    protected function currentRecord(): ?PurchaseInvoice
    {
        $record = $this->route('purchaseInvoice');

        return $record instanceof PurchaseInvoice ? $record : null;
    }
}
