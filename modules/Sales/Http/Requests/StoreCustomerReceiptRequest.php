<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreCustomerReceiptRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customer_receipts.create');
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $dates = app(DateFormatService::class);
        foreach (['receipt_date', 'cheque_due_date'] as $field) {
            if (! empty($input[$field])) {
                $input[$field] = $dates->normalizeForStorage((string) $input[$field]);
            }
        }
        $this->replace($input);

        $this->normalizeNumericInput([
            'amount',
            'allocations.*.amount',
        ]);
    }

    public function rules(): array
    {
        return [
            'customer_doc_num' => ['required', 'string'], 'sales_order_doc_num' => ['nullable', 'string'],
            'received_by_employee_doc_num' => ['required', 'string'],
            'receipt_date' => ['required', 'date'], 'currency_doc_num' => ['required', 'string'],
            'payment_method' => ['required', 'string', 'in:cash,bank,cheque,transfer'],
            'cashbox_doc_num' => ['nullable', 'string', 'required_without:bank_account_doc_num'],
            'bank_account_doc_num' => ['nullable', 'string', 'required_without:cashbox_doc_num'],
            'amount' => ['required', 'numeric', 'gt:0'], 'receipt_type' => ['required', 'string', 'in:advance,collection'],
            'reference_no' => ['nullable', 'string', 'required_if:payment_method,cheque'],
            'cheque_due_date' => ['nullable', 'date', 'required_if:payment_method,cheque'],
            'external_bank_name' => ['nullable', 'string', 'max:255', 'required_if:payment_method,cheque'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['nullable', 'array'], 'allocations.*.invoice_schedule_public_id' => ['required', 'uuid'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
