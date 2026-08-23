<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class InspectSalesReturnRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_returns.inspect');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'results.*.saleable_quantity',
            'results.*.quarantine_quantity',
            'results.*.rework_quantity',
            'results.*.scrap_quantity',
        ]);
    }

    public function rules(): array
    {
        return [
            'results' => ['required', 'array', 'min:1'], 'results.*.sales_return_line_public_id' => ['required', 'uuid'],
            'results.*.saleable_quantity' => ['nullable', 'numeric', 'min:0'], 'results.*.quarantine_quantity' => ['nullable', 'numeric', 'min:0'],
            'results.*.rework_quantity' => ['nullable', 'numeric', 'min:0'], 'results.*.scrap_quantity' => ['nullable', 'numeric', 'min:0'],
            'results.*.notes' => ['nullable', 'string'],
        ];
    }
}
