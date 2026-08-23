<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class CompleteProductionOrderRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.work_orders.complete')
            || (bool) $this->user()?->can('sales_orders.production');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.quantity']);
    }

    public function rules(): array
    {
        return [
            'branch_store_uuid' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.production_order_line_public_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
