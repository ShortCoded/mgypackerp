<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class StoreSalesReturnRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_returns.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.quantity']);
    }

    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string'], 'reason_details' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.invoice_line_public_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
