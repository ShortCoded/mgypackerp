<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class CreateDeliveryRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_deliveries.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.quantity']);
    }

    public function rules(): array
    {
        return [
            'document_date' => ['required', 'date'], 'recipient_name' => ['nullable', 'string', 'max:160'],
            'recipient_phone' => ['nullable', 'string', 'max:80'], 'vehicle_number' => ['nullable', 'string', 'max:80'],
            'driver_name' => ['nullable', 'string', 'max:160'], 'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.sales_order_line_public_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
