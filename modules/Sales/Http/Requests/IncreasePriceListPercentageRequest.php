<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IncreasePriceListPercentageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('price_lists.edit');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('percentage'))) {
            $this->merge(['percentage' => trim($this->input('percentage'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'percentage' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,4})?$/', 'numeric', 'gt:0', 'lte:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'percentage.regex' => __('price_lists.validation.increase_percentage'),
            'percentage.gt' => __('price_lists.validation.increase_percentage'),
            'percentage.lte' => __('price_lists.validation.increase_percentage_max'),
        ];
    }
}
