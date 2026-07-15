<?php

namespace Modules\Inventory\Http\Requests\OpeningStockPricings;

use Illuminate\Validation\Rule;
use Modules\Inventory\Models\OpeningStockPricing;

class UpdateOpeningStockPricingRequest extends StoreOpeningStockPricingRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.opening_stock_pricings.edit');
    }

    public function rules(): array
    {
        $record = $this->currentRecord();
        $rules = parent::rules();
        $rules['doc_number'] = [
            'nullable',
            'integer',
            'min:1',
            Rule::unique('inventory_opening_stock_pricings', 'doc_number')
                ->ignore($record?->getKey())
                ->where(fn ($query) => $query
                    ->where('company_id', $this->input('company_id'))
                    ->where('financial_period_id', $this->input('financial_period_id'))
                    ->whereNull('deleted_at')),
        ];
        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentRecord(): ?OpeningStockPricing
    {
        $docNum = $this->route('openingStockPricing');

        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        return OpeningStockPricing::query()
            ->where('doc_num', $docNum)
            ->where('company_id', $this->input('company_id'))
            ->where('financial_period_id', $this->input('financial_period_id'))
            ->first();
    }
}
