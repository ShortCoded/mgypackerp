<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class ReserveSalesStockRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_orders.reserve');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['quantity']);
    }

    public function rules(): array
    {
        return ['sales_order_line_public_id' => ['required', 'uuid'], 'quantity' => ['required', 'numeric', 'gt:0']];
    }
}
