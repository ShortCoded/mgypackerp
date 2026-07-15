<?php

namespace Modules\Core\Http\Requests\Currencies;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('currencies.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:30'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
