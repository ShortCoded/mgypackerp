<?php

namespace Modules\Purchases\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class PurchaseDiscountPreviewRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('invoice_doc_num') ? 'purchase_invoices.edit' : 'purchase_invoices.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['header_discount_value', 'freight_amount', 'freight_tax_rate', 'lines.*.quantity', 'lines.*.unit_price', 'lines.*.discount_value', 'lines.*.tax_rate']);
    }

    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'];

        return [
            'invoice_doc_num' => ['nullable', 'string'], 'purchase_order_doc_num' => ['nullable', 'string'],
            'header_discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])], 'header_discount_value' => $money,
            'freight_amount' => $money, 'freight_tax_rate' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/D', 'between:0,100'],
            'inherit_header_discount' => ['boolean'], 'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.public_id' => ['nullable', 'uuid'], 'lines.*.purchase_order_line_public_id' => ['nullable', 'uuid'],
            'lines.*.product_doc_num' => ['required', 'string'], 'lines.*.unit_doc_num' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,8', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'decimal:0,8', 'regex:/^\d{1,14}(?:\.\d{1,8})?$/D', 'min:0'],
            'lines.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])], 'lines.*.discount_value' => $money,
            'lines.*.tax_rate' => ['nullable', 'numeric', 'decimal:0,4', 'between:0,100'], 'lines.*.inherit_source_discount' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ([$this->only(['header_discount_type', 'header_discount_value']), ...$this->input('lines', [])] as $index => $input) {
                $type = $index === 0 ? ($input['header_discount_type'] ?? null) : ($input['discount_type'] ?? null);
                $value = (string) ($index === 0 ? ($input['header_discount_value'] ?? 0) : ($input['discount_value'] ?? 0));
                if (($type === 'percentage' && bccomp($value, '100', 4) > 0) || ($index === 0 && blank($type) && bccomp($value, '0', 4) > 0)) {
                    $validator->errors()->add($index === 0 ? 'header_discount_value' : 'lines.'.($index - 1).'.discount_value', __('purchase_invoices.messages.discount_percentage_invalid'));
                }
            }
        });
    }
}
