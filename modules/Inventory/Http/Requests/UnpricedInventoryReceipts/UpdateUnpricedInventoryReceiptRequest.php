<?php

namespace Modules\Inventory\Http\Requests\UnpricedInventoryReceipts;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\UnpricedInventoryReceipt;

class UpdateUnpricedInventoryReceiptRequest extends StoreUnpricedInventoryReceiptRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.unpriced_inventory_receipts.edit');
    }

    public function rules(): array
    {
        $record = $this->currentRecord();
        $rules = parent::rules();
        $rules['doc_number'] = [
            'nullable',
            'integer',
            'min:1',
            Rule::unique('unpriced_inventory_receipts', 'doc_number')
                ->ignore($record?->getKey())
                ->where(fn ($query) => $query
                    ->where('company_id', $this->input('company_id'))
                    ->where('financial_period_id', $this->input('financial_period_id'))
                    ->whereNull('deleted_at')),
        ];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBusiness($validator, $this->currentRecord());
        });
    }

    private function currentRecord(): ?UnpricedInventoryReceipt
    {
        $docNum = $this->route('unpricedInventoryReceipt');

        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        return UnpricedInventoryReceipt::query()
            ->where('doc_num', $docNum)
            ->where('company_id', $this->input('company_id'))
            ->where('financial_period_id', $this->input('financial_period_id'))
            ->first();
    }
}
