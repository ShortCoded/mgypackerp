<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreInventoryMovementCorrectionRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.documents.correct_prepare') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('posting_date')) {
            $this->merge(['posting_date' => app(DateFormatService::class)->normalizeForStorage($this->input('posting_date')) ?? $this->input('posting_date')]);
        }
        $this->normalizeNumericInput(['lines.*.quantity', 'lines.*.unit_cost']);
    }

    public function rules(): array
    {
        $items = $this->input('operation') === 'replace_items';

        return ['operation' => ['required', 'in:reverse,replace,replace_items,repair_lineage'], 'source_fingerprint' => ['required', 'string', 'size:64'],
            'posting_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:3000'],
            'lines' => ['required_if:operation,replace,replace_items', 'array', 'max:100'],
            'lines.*.line_id' => [$items ? 'nullable' : 'required', 'integer', 'distinct'],
            'lines.*.product_doc_num' => [$items ? 'required' : 'prohibited', 'string', 'max:100'],
            'lines.*.unit_doc_num' => [$items ? 'required' : 'prohibited', 'string', 'max:100'],
            'lines.*.quantity' => ['required', 'numeric', $items ? 'gt:0' : 'min:0', 'decimal:0,8'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'lines.*.selected_receipt_layer_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:100'],
            'lines.*.batch_lot' => ['nullable', 'string', 'max:100'],
            'lines.*.manufacture_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:posting_date']];
    }
}
