<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\PostedInvoiceLineCorrectionService;

class StorePostedInvoiceLineCorrectionRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        app(PostedInvoiceLineCorrectionService::class)->authorize((string) $this->route('kind'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (filled($this->input('posting_date'))) {
            $this->merge(['posting_date' => app(DateFormatService::class)->normalizeForStorage((string) $this->input('posting_date'))]);
        }
        $this->normalizeNumericInput(['lines.*.quantity', 'lines.*.unit_price', 'lines.*.discount_amount', 'lines.*.tax_amount', 'lines.*.discount_value', 'lines.*.tax_rate']);
    }

    public function rules(): array
    {
        $companyId = app(OperatingContextService::class)->snapshot($this)['company_id'];

        return [
            'source_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D'],
            'posting_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.original_line_public_id' => ['nullable', 'uuid', 'distinct'],
            'lines.*.source_line_public_id' => ['nullable', 'uuid'],
            'lines.*.receipt_line_public_id' => ['nullable', 'uuid'],
            'lines.*.product_doc_num' => ['required', 'string', Rule::exists('products', 'doc_num')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')],
            'lines.*.unit_doc_num' => ['required', 'string', Rule::exists('item_units', 'doc_num')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => [Rule::requiredIf($this->route('kind') === 'purchase'), 'nullable', 'numeric', 'gte:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'gte:0'], 'lines.*.tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.discount_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'gte:0'], 'lines.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return ['posting_date' => __('sales_return_plan.posting_date'), 'reason' => __('sales_return_plan.reason'),
            'lines.*.product_doc_num' => __('Product'), 'lines.*.unit_doc_num' => __('Unit'),
            'lines.*.quantity' => __('Quantity'), 'lines.*.unit_price' => __('Unit price'),
            'lines.*.source_line_public_id' => __('posted_invoice_correction.source_line'),
            'lines.*.receipt_line_public_id' => __('posted_invoice_correction.receipt_line')];
    }
}
