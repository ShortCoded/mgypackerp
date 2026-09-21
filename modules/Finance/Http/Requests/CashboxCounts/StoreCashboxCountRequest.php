<?php

namespace Modules\Finance\Http\Requests\CashboxCounts;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

final class StoreCashboxCountRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.cashbox_count.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['actual_amount']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'count_date' => ['required', 'date'],
            'cashbox_doc_num' => ['required', 'string', 'max:100'],
            'currency_doc_num' => ['required', 'string', 'max:100'],
            'actual_amount' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
