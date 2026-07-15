<?php

namespace Modules\Finance\Http\Requests\BankAccounts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBankAccountDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('bank_accounts.document_number_settings.update');
    }

    public function rules(): array
    {
        return ['prefix' => ['required', 'string', 'max:30'], 'padding' => ['required', 'integer', 'min:1', 'max:10']];
    }
}
