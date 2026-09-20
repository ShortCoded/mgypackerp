<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\PriceListLine;

class StorePriceListRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('price_lists.create');
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();
        foreach (['price_list_date', 'valid_from', 'valid_until'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = app(DateFormatService::class)->normalizeForStorage((string) $data[$field]);
            }
        }
        $data['customer_doc_num'] = filled($data['customer_doc_num'] ?? null) ? trim((string) $data['customer_doc_num']) : null;
        $data['is_print_only'] = array_key_exists('is_print_only', $data) ? $data['is_print_only'] : false;
        $data['notes'] = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;
        $data['lines'] = collect($data['lines'] ?? [])->filter(fn (mixed $line): bool => is_array($line) && filled($line['product_doc_num'] ?? null))->values()->all();
        $this->replace($data);
        $this->normalizeNumericInput(['lines.*.unit_price', 'lines.*.allowed_discount_value']);
    }

    public function rules(): array
    {
        return [
            'customer_doc_num' => ['nullable', 'string'], 'currency_doc_num' => ['required', 'string'],
            'price_list_date' => ['required', 'date'], 'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'], 'notes' => ['nullable', 'string', 'max:4000'],
            'is_print_only' => ['required', 'boolean'],
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new'])],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_doc_num' => ['required', 'string', 'distinct'],
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'lines.*.allowed_discount_type' => ['nullable', Rule::in(PriceListLine::DiscountTypes)],
            'lines.*.allowed_discount_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);
            if ($this->filled('customer_doc_num') && ! Customer::query()->forCompany($companyId)->active()->where('doc_num', $this->input('customer_doc_num'))->exists()) {
                $validator->errors()->add('customer_doc_num', __('price_lists.validation.customer'));
            }
            if (! Currency::query()->forCompany($companyId)->active()->where('doc_num', $this->input('currency_doc_num'))->exists()) {
                $validator->errors()->add('currency_doc_num', __('price_lists.validation.currency'));
            }
            foreach ($this->input('lines', []) as $index => $line) {
                $product = Product::query()->forCompany($companyId)->active()->where('doc_num', $line['product_doc_num'] ?? null)->first();
                if (! $product || ! $product->isSalesEligible()) {
                    $validator->errors()->add("lines.{$index}.product_doc_num", __('price_lists.validation.product'));
                }
                if (($line['allowed_discount_type'] ?? null) === PriceListLine::DiscountPercentage && (float) ($line['allowed_discount_value'] ?? 0) > 100) {
                    $validator->errors()->add("lines.{$index}.allowed_discount_value", __('price_lists.validation.percentage'));
                }
            }
        });
    }
}
