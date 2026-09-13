<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreSalesOrderRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_orders.create');
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $dates = app(DateFormatService::class);
        foreach (['order_date', 'expected_delivery_date'] as $field) {
            if (! empty($input[$field])) {
                $input[$field] = $dates->normalizeForStorage((string) $input[$field]);
            }
        }
        foreach ($input['lines'] ?? [] as $index => $line) {
            if (! empty($line['requested_date'])) {
                $input['lines'][$index]['requested_date'] = $dates->normalizeForStorage((string) $line['requested_date']);
            }
        }
        foreach ($input['payment_schedules'] ?? [] as $index => $schedule) {
            if (! empty($schedule['due_date'])) {
                $input['payment_schedules'][$index]['due_date'] = $dates->normalizeForStorage((string) $schedule['due_date']);
            }
        }
        $this->replace($input);

        $this->normalizeNumericInput([
            'lines.*.quantity',
            'lines.*.unit_price',
            'lines.*.discount_amount',
            'lines.*.tax_amount',
            'payment_schedules.*.amount',
        ]);
    }

    public function rules(): array
    {
        return [
            'source_request_doc_num' => ['nullable', 'string'],
            'customer_doc_num' => ['required', 'string'], 'branch_store_uuid' => ['nullable', 'uuid'],
            'currency_doc_num' => ['required', 'string'], 'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['required', 'date', 'after_or_equal:order_date'],
            'customer_reference' => ['nullable', 'string', 'max:160'],
            'sales_employee_doc_num' => ['nullable', 'string', 'exists:hr_employees,doc_num'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_doc_num' => ['required', 'string'], 'lines.*.unit_doc_num' => ['nullable', 'string'],
            'lines.*.source_request_line_public_id' => ['nullable', 'uuid', 'distinct'],
            'lines.*.description' => ['nullable', 'string'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'gt:0'], 'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'], 'lines.*.requested_date' => ['nullable', 'date'],
            'lines.*.specifications' => ['nullable', 'array'], 'lines.*.warehouse_notes' => ['nullable', 'string'],
            'lines.*.production_notes' => ['nullable', 'string'],
            'payment_schedules' => ['nullable', 'array'], 'payment_schedules.*.amount' => ['required', 'numeric', 'gt:0'],
            'payment_schedules.*.due_date' => ['required', 'date'], 'payment_schedules.*.title' => ['nullable', 'string'],
        ];
    }
}
