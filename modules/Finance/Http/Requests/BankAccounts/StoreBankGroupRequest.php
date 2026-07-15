<?php

namespace Modules\Finance\Http\Requests\BankAccounts;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('accounts.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'notes' => trim((string) $this->input('notes')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('bank_accounts.attributes.bank_name'),
            'notes' => __('bank_accounts.attributes.notes'),
        ];
    }
}
