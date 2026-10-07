<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;
use Modules\Sales\Services\SalesWithholdingService;

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

        $this->normalizeNumericInput(['withholding_rate', 'discount_value', 'lines.*.discount_value', 'lines.*.discount_amount', 'lines.*.quantity', 'lines.*.tax_rate', 'payment_schedules.*.amount']);
    }

    public function rules(): array
    {
        return [
            'discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,16}(?:\.\d{1,4})?$/D', 'min:0'],
            'lines.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,16}(?:\.\d{1,4})?$/D', 'min:0'],
            'withholding_basis' => ['nullable', Rule::in([SalesWithholdingService::GrossIncludingTax, SalesWithholdingService::EtaNetExcludingTax])],
            'withholding_rate' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/D', 'between:0,100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_line_public_id' => ['required', 'uuid'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,16}(?:\.\d{1,4})?$/D', 'min:0'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/D', 'between:0,100'],
            'payment_schedules' => ['required', 'array', 'min:1'],
            'payment_schedules.*.due_date' => ['required', 'date'],
            'payment_schedules.*.amount' => ['required', 'numeric', 'gt:0'],
            'payment_schedules.*.notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $inputs = ['discount_value' => [$this->input('discount_type'), $this->input('discount_value')]];
            foreach ($this->input('lines', []) as $index => $line) {
                $inputs["lines.{$index}.discount_value"] = [$line['discount_type'] ?? null, $line['discount_value'] ?? null];
            }
            foreach ($inputs as $field => [$type, $value]) {
                if (! is_scalar($value) || ! preg_match('/^\d{1,16}(?:\.\d{1,4})?$/D', (string) $value)) {
                    continue;
                }
                if ((blank($type) && bccomp((string) $value, '0', 4) > 0) || ($type === 'percentage' && bccomp((string) $value, '100', 4) > 0)) {
                    $validator->errors()->add($field, __('sales_ui.discount_invalid'));
                }
            }
        });
    }

    public function attributes(): array
    {
        return ['withholding_rate' => __('sales_ui.withholding_rate'), 'discount_type' => __('quotations.attributes.discount_type'), 'discount_value' => __('quotations.attributes.discount_value'),
            'lines.*.discount_type' => __('quotations.attributes.discount_type'), 'lines.*.discount_value' => __('quotations.attributes.discount_value')];
    }
}
