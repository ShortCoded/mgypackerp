<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;

class OpeningStockCostCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.opening_stock_cost_corrections.prepare') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $costs = $this->input('unit_costs');
        if (is_array($costs)) {
            try {
                $costs = array_map(fn ($value) => app(NumericFormatService::class)->normalizeToScale($value, 8), $costs);
            } catch (\InvalidArgumentException|\TypeError) {
                throw ValidationException::withMessages(['unit_costs' => __('inventory.movements.messages.receipt_pricing_precision')]);
            }
        }
        $this->merge([
            'posting_date' => app(DateFormatService::class)->parseDate((string) $this->input('posting_date'))?->toDateString(),
            'unit_costs' => $costs,
            'reason' => trim((string) $this->input('reason')),
            'source_reference' => trim((string) $this->input('source_reference')),
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
            'counterpart_account_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'source_reference' => ['required', 'string', 'min:5', 'max:255'],
            'unit_costs' => ['required', 'array', 'min:1'],
            'unit_costs.*' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
        ];
    }
}
