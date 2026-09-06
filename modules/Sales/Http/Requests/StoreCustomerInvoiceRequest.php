<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreCustomerInvoiceRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_invoices.create');
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        if (! empty($input['invoice_date'])) {
            $input['invoice_date'] = app(DateFormatService::class)->normalizeForStorage((string) $input['invoice_date']);
        }
        foreach ($input['payment_schedules'] ?? [] as $index => $schedule) {
            if (! empty($schedule['due_date'])) {
                $input['payment_schedules'][$index]['due_date'] = app(DateFormatService::class)->normalizeForStorage((string) $schedule['due_date']);
            }
        }
        $this->replace($input);

        $this->normalizeNumericInput([
            'lines.*.quantity',
            'payment_schedules.*.amount',
        ]);
        $this->merge(['lines' => array_values(array_filter($this->input('lines', []), fn (array $line): bool => filled($line['quantity'] ?? null) && (float) str_replace(',', '', (string) $line['quantity']) > 0))]);
    }

    public function rules(): array
    {
        return [
            'invoice_date' => ['nullable', 'date'],
            'delivery_doc_num' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_public_id' => ['required', 'uuid'], 'lines.*.delivery_line_public_id' => ['nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'payment_schedules' => ['required', 'array', 'min:1'],
            'payment_schedules.*.due_date' => ['required', 'date'], 'payment_schedules.*.amount' => ['required', 'numeric', 'gt:0'],
            'payment_schedules.*.notes' => ['nullable', 'string'],
        ];
    }
}
