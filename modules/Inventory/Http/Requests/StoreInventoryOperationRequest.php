<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryDocument;

class StoreInventoryOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = match ($this->input('document_type')) {
            InventoryDocument::TypeTransfer => 'inventory.documents.transfer',
            InventoryDocument::TypeAdjustmentIn, InventoryDocument::TypeAdjustmentOut => 'inventory.documents.adjust',
            InventoryDocument::TypeDamage, InventoryDocument::TypeScrap => 'inventory.documents.damage_scrap',
            default => null,
        };

        return $permission !== null && (bool) $this->user()?->can($permission);
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
            'lines.*.manufacture_date' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date', 'after_or_equal:document_date'],
            'lines.*.unit_cost' => [
                Rule::requiredIf(fn (): bool => $this->input('document_type') === InventoryDocument::TypeAdjustmentIn),
                'nullable',
                'numeric',
                'gt:0',
            ],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }
}
