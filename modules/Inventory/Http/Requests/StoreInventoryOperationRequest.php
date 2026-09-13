<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;

class StoreInventoryOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = match ($this->input('document_type')) {
            InventoryDocument::TypeReceipt => 'inventory.documents.receive',
            InventoryDocument::TypeIssue => 'inventory.documents.issue',
            InventoryDocument::TypeReturn => 'inventory.documents.return',
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
            'destination_branch_store_id' => [
                Rule::requiredIf(fn (): bool => $this->input('document_type') === InventoryDocument::TypeTransfer),
                'nullable',
                'integer',
                'different:branch_store_id',
                'exists:branch_stores,id',
            ],
            'warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'destination_warehouse_location_id' => ['nullable', 'integer', 'exists:warehouse_locations,id'],
            'document_type' => ['required', Rule::in(InventoryDocument::manualMovementTypes())],
            'document_date' => ['required', 'date'],
            'movement_reason' => ['required', 'string', 'max:255'],
            'source_stock_status' => ['nullable', Rule::in($this->stockStatuses())],
            'destination_stock_status' => ['nullable', Rule::in($this->stockStatuses())],
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
                Rule::requiredIf(fn (): bool => in_array($this->input('document_type'), [
                    InventoryDocument::TypeReceipt,
                    InventoryDocument::TypeReturn,
                    InventoryDocument::TypeAdjustmentIn,
                ], true)),
                'nullable',
                'numeric',
                'gt:0',
            ],
            'lines.*.notes' => ['nullable', 'string'],
        ];
    }

    /** @return list<string> */
    private function stockStatuses(): array
    {
        return [
            InventoryTransaction::StatusAvailable,
            InventoryTransaction::StatusReserved,
            InventoryTransaction::StatusQcHold,
            InventoryTransaction::StatusQuarantine,
            InventoryTransaction::StatusRework,
            InventoryTransaction::StatusProductionStaging,
            InventoryTransaction::StatusWip,
            InventoryTransaction::StatusRejected,
            InventoryTransaction::StatusDamaged,
            InventoryTransaction::StatusScrap,
            InventoryTransaction::StatusInTransit,
        ];
    }
}
