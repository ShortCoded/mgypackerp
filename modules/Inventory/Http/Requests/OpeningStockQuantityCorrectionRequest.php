<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Inventory\Services\InventorySerialService;

class OpeningStockQuantityCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.opening_stock_quantity_corrections.prepare') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $targets = $this->input('targets');
        if (is_array($targets)) {
            try {
                $targets = array_map(function ($entry): array {
                    if (! is_array($entry)) {
                        throw new \InvalidArgumentException;
                    }

                    return [
                        'target_quantity' => app(NumericFormatService::class)->normalizeToScale($entry['target_quantity'] ?? null, 4),
                        'unit_cost' => app(NumericFormatService::class)->normalizeToScale($entry['unit_cost'] ?? null, 8),
                        'selected_receipt_layer_id' => $entry['selected_receipt_layer_id'] ?? null,
                        'serial_numbers' => app(InventorySerialService::class)->numbers($entry['serial_numbers'] ?? []),
                        'serial_receipt_layer_ids' => $entry['serial_receipt_layer_ids'] ?? [],
                    ];
                }, $targets);
            } catch (\InvalidArgumentException|\TypeError|\DomainException) {
                throw ValidationException::withMessages(['targets' => __('opening_stock_quantity_correction.errors.invalid_input')]);
            }
        }
        $postingDate = $this->input('posting_date');
        $reason = $this->input('reason');
        $sourceReference = $this->input('source_reference');
        $this->merge([
            'targets' => $targets,
            'posting_date' => is_string($postingDate) ? app(DateFormatService::class)->parseDate($postingDate)?->toDateString() : $postingDate,
            'reason' => is_string($reason) ? trim($reason) : $reason,
            'source_reference' => is_string($sourceReference) ? trim($sourceReference) : $sourceReference,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'source_reference' => ['required', 'string', 'min:5', 'max:255'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.target_quantity' => ['required', 'numeric', 'gte:0', 'decimal:0,4'],
            'targets.*.unit_cost' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8'],
            'targets.*.selected_receipt_layer_id' => ['nullable', 'integer', 'min:1'],
            'targets.*.serial_numbers' => ['array', 'max:10000'],
            'targets.*.serial_numbers.*' => ['string', 'max:100'],
            'targets.*.serial_receipt_layer_ids' => ['array', 'max:10000'],
            'targets.*.serial_receipt_layer_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
