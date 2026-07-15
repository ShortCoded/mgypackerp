<?php

namespace Modules\Inventory\Http\Requests\OpeningStocks;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOpeningStockDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.opening_stocks.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:30'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
