<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreCustomerWithholdingSettlementRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_withholding_settlements.prepare');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['amount']);
        foreach (['certificate_date', 'posting_date'] as $field) {
            $value = $this->string($field)->trim()->toString();
            $this->merge([$field => $value === '' ? null : app(DateFormatService::class)->normalizeForStorage($value)]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'payment_schedule_id' => ['required', 'integer', 'min:1'],
            'customer_receipt_id' => ['required', 'integer', 'min:1'],
            'source_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'certificate_reference' => ['required', 'string', 'max:255'],
            'certificate_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:posting_date'],
            'posting_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'string', 'regex:/^\d{1,16}(?:\.\d{1,4})?$/D', 'gt:0'],
            'attachment_doc_nums' => ['required', 'array', 'size:1'],
            'attachment_doc_nums.*' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:3000'],
        ];
    }
}
