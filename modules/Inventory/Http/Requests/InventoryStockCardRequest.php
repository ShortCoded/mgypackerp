<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

class InventoryStockCardRequest extends StockBalanceInquiryRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'product_doc_num' => ['required', 'string', 'max:100'],
            'from' => ['nullable', 'date', 'before_or_equal:as_of'],
            'movement_page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'quantity_state' => ['prohibited']];
    }
}
