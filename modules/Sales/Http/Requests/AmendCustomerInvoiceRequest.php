<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class AmendCustomerInvoiceRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_invoices.edit');
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        foreach ($input['payment_schedules'] ?? [] as $index => $schedule) {
            if (! empty($schedule['due_date'])) {
                $input['payment_schedules'][$index]['due_date'] = app(DateFormatService::class)->normalizeForStorage((string) $schedule['due_date']);
            }
        }
        $this->replace($input);

        $this->normalizeNumericInput(['lines.*.quantity', 'payment_schedules.*.amount']);
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_line_public_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'payment_schedules' => ['required', 'array', 'min:1'],
            'payment_schedules.*.due_date' => ['required', 'date'],
            'payment_schedules.*.amount' => ['required', 'numeric', 'gt:0'],
            'payment_schedules.*.notes' => ['nullable', 'string'],
        ];
    }
}
