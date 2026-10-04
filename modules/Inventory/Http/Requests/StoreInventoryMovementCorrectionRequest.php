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
        return ['operation' => ['required', 'in:reverse,replace,repair_lineage'], 'source_fingerprint' => ['required', 'string', 'size:64'],
            'posting_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:3000'],
            'lines' => ['required_if:operation,replace', 'array'], 'lines.*.line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'decimal:0,8'], 'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,8']];
    }
}
