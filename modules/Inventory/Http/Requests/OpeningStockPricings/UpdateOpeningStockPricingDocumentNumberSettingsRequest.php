<?php

namespace Modules\Inventory\Http\Requests\OpeningStockPricings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOpeningStockPricingDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.opening_stock_pricings.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:30'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
