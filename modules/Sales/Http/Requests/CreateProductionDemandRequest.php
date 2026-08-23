<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class CreateProductionDemandRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_orders.production');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.quantity']);
    }

    public function rules(): array
    {
        return ['lines' => ['required', 'array', 'min:1'], 'lines.*.sales_order_line_public_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0']];
    }
}
