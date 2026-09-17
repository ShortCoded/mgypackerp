<?php

namespace Modules\Finance\Http\Requests\CashboxCounts;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCashboxCountRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->can('finance.cashbox_count.create'); }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'count_date' => ['required', 'date'],
            'cashbox_doc_num' => ['required', 'string', 'max:100'],
            'currency_doc_num' => ['required', 'string', 'max:100'],
            'actual_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
