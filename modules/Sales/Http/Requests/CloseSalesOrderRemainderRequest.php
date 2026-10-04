<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class CloseSalesOrderRemainderRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_orders.close_remainder');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['lines.*.expected_remaining_quantity']);
    }

    public function rules(): array
    {
        return [
            'closure_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            '_submission_token' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_public_id' => ['required', 'uuid', 'distinct'],
            'lines.*.expected_remaining_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
        ];
    }

    public function attributes(): array
    {
        return [
            'closure_date' => __('sales_ui.remainder.closure_date'),
            'reason' => __('sales_ui.remainder.decline_reason'),
            '_submission_token' => __('sales_ui.remainder.fields.submission_token'),
            'lines' => __('sales_ui.remainder.fields.lines'),
            'lines.*.sales_order_line_public_id' => __('sales_ui.remainder.fields.line'),
            'lines.*.expected_remaining_quantity' => __('sales_ui.remainder.fields.expected_remaining_quantity'),
        ];
    }
}
