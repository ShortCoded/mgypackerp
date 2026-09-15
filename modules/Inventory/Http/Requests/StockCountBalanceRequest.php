<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;

class StockCountBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAny([
            'inventory.stock_counts.view',
            'inventory.stock_counts.create',
            'inventory.stock_counts.edit',
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'branch_store_id' => [
                'required',
                'integer',
                Rule::exists('branch_stores', 'id')->where(fn ($query) => $query
                    ->where('branch_id', $context['branch_id'])
                    ->whereNull('deleted_at')),
            ],
            'warehouse_location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')->where(fn ($query) => $query
                    ->where('branch_store_id', $this->input('branch_store_id'))
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'product_doc_num' => [
                'required',
                'string',
                Rule::exists('products', 'doc_num')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('status', 'active')
                    ->where('item_classification', '<>', Product::ClassificationService)
                    ->whereNull('deleted_at')),
            ],
            'stock_status' => ['required', Rule::in([
                InventoryTransaction::StatusAvailable,
                InventoryTransaction::StatusProductionStaging,
                InventoryTransaction::StatusQcHold,
                InventoryTransaction::StatusQuarantine,
                InventoryTransaction::StatusDamaged,
            ])],
            'batch_lot' => ['nullable', 'string', 'max:100'],
        ];
    }
}
