<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class CreateDeliveryRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_deliveries.create');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('document_date')) {
            $this->merge(['document_date' => app(DateFormatService::class)->normalizeForStorage((string) $this->input('document_date'))]);
        }
        $this->normalizeNumericInput(['lines.*.quantity']);
        $this->merge(['lines' => array_values(array_filter($this->input('lines', []), fn (array $line): bool => filled($line['quantity'] ?? null) && (float) str_replace(',', '', (string) $line['quantity']) > 0))]);
    }

    public function rules(): array
    {
        return [
            'document_date' => ['required', 'date'], 'recipient_name' => ['nullable', 'string', 'max:160'],
            'branch_store_uuid' => ['required', 'uuid'],
            'recipient_phone' => ['nullable', 'string', 'max:80'], 'vehicle_number' => ['nullable', 'string', 'max:80'],
            'driver_name' => ['nullable', 'string', 'max:160'], 'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.invoice_line_public_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
