<?php

namespace Modules\Finance\Http\Requests\Cashboxes;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashboxGroupRequest extends FormRequest
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
            'name' => __('cashboxes.attributes.account_group_name'),
            'notes' => __('cashboxes.attributes.notes'),
        ];
    }
}
