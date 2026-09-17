<?php

namespace Modules\Finance\Http\Requests\CashboxCounts;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCashboxCountRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->can('finance.cashbox_count.edit'); }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'actual_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
