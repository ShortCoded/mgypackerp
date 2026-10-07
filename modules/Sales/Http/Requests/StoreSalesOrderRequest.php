<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;
use Modules\Sales\Services\SalesWithholdingService;

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

        $this->normalizeNumericInput(['withholding_rate',
            'discount_value',
            'lines.*.discount_value',
            'lines.*.quantity',
            'lines.*.unit_price',
            'lines.*.discount_amount',
            'lines.*.tax_rate',
            'payment_schedules.*.amount',
        ]);
    }

    public function rules(): array
    {
        return [
            'withholding_basis' => ['nullable', Rule::in([SalesWithholdingService::GrossIncludingTax, SalesWithholdingService::EtaNetExcludingTax])],
            'withholding_rate' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/D', 'between:0,100'],
            'source_request_doc_num' => ['nullable', 'string'],
            'customer_doc_num' => ['required', 'string'], 'branch_store_uuid' => ['nullable', 'uuid'],
            'currency_doc_num' => ['required', 'string'], 'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['required', 'date', 'after_or_equal:order_date'],
            'customer_reference' => ['nullable', 'string', 'max:160'],
            'sales_employee_doc_num' => ['nullable', 'string', 'exists:hr_employees,doc_num'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'],
            'discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,16}(?:\.\d{1,4})?$/D', 'min:0'],
            'lines.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,16}(?:\.\d{1,4})?$/D', 'min:0'],
            'lines.*.product_doc_num' => ['required', 'string'], 'lines.*.unit_doc_num' => ['nullable', 'string'],
            'lines.*.public_id' => ['nullable', 'uuid', 'distinct'],
            'lines.*.source_request_line_public_id' => ['nullable', 'uuid', 'distinct'],
            'lines.*.description' => ['nullable', 'string'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'decimal:0,8', 'regex:/^\d{1,16}(?:\.\d{1,8})?$/D', 'gt:0'], 'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/D', 'between:0,100'], 'lines.*.requested_date' => ['nullable', 'date'],
            'lines.*.specifications' => ['nullable', 'array'], 'lines.*.warehouse_notes' => ['nullable', 'string'],
            'lines.*.production_notes' => ['nullable', 'string'],
            'payment_schedules' => ['nullable', 'array'], 'payment_schedules.*.amount' => ['required', 'numeric', 'gt:0'],
            'payment_schedules.*.due_date' => ['required', 'date'], 'payment_schedules.*.title' => ['nullable', 'string'],
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
