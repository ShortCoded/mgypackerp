<?php

namespace Modules\Finance\Http\Requests\OpeningBalances;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOpeningBalanceDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('opening_balances.document_number_settings.update');
    }

    public function rules(): array
    {
        return ['prefix' => ['required', 'string', 'max:30'], 'padding' => ['required', 'integer', 'min:1', 'max:10']];
    }
}
