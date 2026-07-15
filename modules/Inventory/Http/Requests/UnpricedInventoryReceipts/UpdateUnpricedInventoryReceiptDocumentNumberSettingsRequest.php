<?php

namespace Modules\Inventory\Http\Requests\UnpricedInventoryReceipts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnpricedInventoryReceiptDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.unpriced_inventory_receipts.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:30'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
