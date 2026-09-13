<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class SalesRequestWorkflowRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['exchange_rate', 'lines.*.quantity']);
        $data = $this->all();
        foreach (['request_date', 'required_delivery_date'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = app(DateFormatService::class)->normalizeForStorage((string) $data[$field]);
            }
        }
        if ($this->routeIs('admin.sales.customer-requests.convert')) {
            $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn (array $line): bool => filled($line['quantity'] ?? null) && (float) $line['quantity'] > 0));
        }
        $this->replace($data);
    }

    public function rules(): array
    {
        if ($this->routeIs('admin.sales.customer-requests.transition')) {
            return ['status' => ['required', Rule::in(['submitted', 'approved', 'rejected', 'cancelled', 'closed'])], 'reason' => ['nullable', 'string', 'max:2000']];
        }
        if ($this->routeIs('admin.sales.customer-requests.convert')) {
            return ['customer_doc_num' => ['nullable', 'required_if:request_type,customer', 'string'], 'currency_doc_num' => ['nullable', 'string'], 'branch_store_uuid' => ['nullable', 'uuid'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'target' => ['required', Rule::in(['quotation', 'order'])], 'lines' => ['required', 'array', 'min:1'], 'lines.*.public_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0']];
        }

        return ['request_date' => ['required', 'date'], 'required_delivery_date' => ['nullable', 'date', 'after_or_equal:request_date'],
            'customer_doc_num' => ['nullable', 'required_if:request_type,customer', 'string'], 'currency_doc_num' => ['required', 'string'], 'branch_store_uuid' => ['nullable', 'uuid'],
            'sales_employee_doc_num' => ['nullable', 'string', 'exists:hr_employees,doc_num'], 'request_type' => ['required', Rule::in(['customer', 'internal'])], 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'customer_reference' => ['nullable', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:5000'], 'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_doc_num' => ['required', 'string'], 'lines.*.unit_doc_num' => ['required', 'string'],
            'lines.*.description' => ['nullable', 'string'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.specifications' => ['nullable', 'array'], 'lines.*.notes' => ['nullable', 'string']];
    }
}
