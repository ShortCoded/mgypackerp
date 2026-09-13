<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.expenses.create');
    }

    public function rules(): array
    {
        return [
            'production_run_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_id' => ['required', 'integer'],
            'payment_channel' => ['required', Rule::in(['cashbox', 'bank'])],
            'cashbox_id' => ['nullable', 'integer', 'required_if:payment_channel,cashbox'],
            'bank_account_id' => ['nullable', 'integer', 'required_if:payment_channel,bank'],
            'expense_account_id' => ['nullable', 'integer', 'required_if:payment_channel,cashbox'],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
