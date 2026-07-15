<?php

namespace Modules\Inventory\Http\Requests\OpeningStocks;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Models\OpeningStock;

class UpdateOpeningStockRequest extends StoreOpeningStockRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.opening_stocks.edit');
    }

    public function rules(): array
    {
        $openingStock = $this->currentRecord();
        $rules = parent::rules();
        $rules['doc_number'] = [
            'nullable',
            'integer',
            'min:1',
            Rule::unique('inventory_opening_stocks', 'doc_number')
                ->ignore($openingStock?->getKey())
                ->where(fn ($query) => $query
                    ->where('company_id', $this->input('company_id'))
                    ->where('financial_period_id', $this->input('financial_period_id'))
                    ->whereNull('deleted_at')),
        ];
        unset($rules['clone_source_token']);

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateBusiness($validator, $this->currentRecord());
        });
    }

    private function currentRecord(): ?OpeningStock
    {
        $docNum = $this->route('openingStock');

        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        return OpeningStock::query()
            ->where('doc_num', $docNum)
            ->where('company_id', $this->input('company_id'))
            ->where('financial_period_id', $this->input('financial_period_id'))
            ->where('branch_id', $this->input('branch_id'))
            ->first();
    }
}
