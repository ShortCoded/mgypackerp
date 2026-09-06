<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;

class StoreFixedAssetCostMovementRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.improvement.post');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['amount', 'revised_useful_life', 'revised_residual_value', 'exchange_rate', 'estimated_remaining_units', 'actual_usage_before_addition']);
        $this->merge(['movement_date' => app(DateFormatService::class)->normalizeForStorage($this->input('movement_date'))]);
    }

    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'movement_date' => ['required', 'date'], 'description' => ['required', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'decimal:0,6', 'gt:0'],
            'estimated_remaining_units' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0'],
            'actual_usage_before_addition' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'counter_account_doc_num' => ['required', 'string', 'max:255'],
            'revised_useful_life' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0'],
            'revised_residual_value' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
