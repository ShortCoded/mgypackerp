<?php

namespace Modules\Finance\Http\Requests\CashboxCounts;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

final class UpdateCashboxCountRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.cashbox_count.edit');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['actual_amount']);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'actual_amount' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
