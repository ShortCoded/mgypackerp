<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryTransaction;

class StockBalanceInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'as_of' => ['required', 'date'],
            'branch_doc_num' => ['nullable', 'string', 'max:100'],
            'branch_store_uuid' => ['nullable', 'uuid'],
            'branch_hall_uuid' => ['nullable', 'uuid'],
            'warehouse_location_uuid' => ['nullable', 'uuid'],
            'product_doc_num' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'item_classification' => ['nullable', Rule::in(Product::stockableItemClassifications())],
            'item_category_doc_num' => ['nullable', 'string', 'max:100'],
            'item_group_doc_num' => ['nullable', 'string', 'max:100'],
            'item_model_doc_num' => ['nullable', 'string', 'max:100'],
            'item_size_doc_num' => ['nullable', 'string', 'max:100'],
            'item_color_doc_num' => ['nullable', 'string', 'max:100'],
            'item_decal_doc_num' => ['nullable', 'string', 'max:100'],
            'item_unit_doc_num' => ['nullable', 'string', 'max:100'],
            'item_origin_country_doc_num' => ['nullable', 'string', 'max:100'],
            'stock_status' => ['nullable', Rule::in($this->stockStatuses())],
            'quantity_state' => ['nullable', Rule::in(['positive', 'negative', 'held', 'below_reorder'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'as_of' => $this->input('as_of') ?: today()->toDateString(),
        ]);
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
