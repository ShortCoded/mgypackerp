<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Inventory\Models\StockCount;

class UpdateStockCountRequest extends StoreStockCountRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.stock_counts.edit');
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateBusiness($validator);
            $record = $this->route('stockCount');

            if (! $record instanceof StockCount || ! $record->isEditable()) {
                $validator->errors()->add('document', __('inventory.stock_counts.messages.approved_edit_forbidden'));
            }
        }];
    }
}
