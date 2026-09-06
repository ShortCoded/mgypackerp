<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class ConvertQuotationRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_orders.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.quantity']);
    }

    public function rules(): array
    {
        return ['lines' => ['nullable', 'array', 'min:1'], 'lines.*.public_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'numeric', 'min:0']];
    }
}
