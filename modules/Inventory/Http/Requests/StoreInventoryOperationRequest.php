<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryDocument;

class StoreInventoryOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_store_id' => ['required', 'integer', 'exists:branch_stores,id'],
            'destination_branch_store_id' => ['nullable', 'integer', 'exists:branch_stores,id'],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'destination_warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'document_type' => ['required', Rule::in([
                InventoryDocument::TypeTransfer,
                InventoryDocument::TypeAdjustmentIn,
                InventoryDocument::TypeAdjustmentOut,
                InventoryDocument::TypeDamage,
                InventoryDocument::TypeScrap,
            ])],
            'document_date' => ['required', 'date'],
            'movement_reason' => ['required', 'string', 'max:255'],
            'source_stock_status' => ['nullable', 'string', 'max:30'],
            'destination_stock_status' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'lines.*.destination_warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'lines.*.batch_lot' => ['nullable', 'string', 'max:100'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }
}
