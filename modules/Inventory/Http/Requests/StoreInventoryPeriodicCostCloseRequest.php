<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Services\DateFormatService;

class StoreInventoryPeriodicCostCloseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.cost_policies.periodic.prepare');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_doc_num' => ['nullable', 'string', 'max:100'], 'branch_store_uuid' => ['nullable', 'uuid'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'posting_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:to_date'],
            'counterpart_account_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $dates = app(DateFormatService::class);
        foreach (['from_date', 'to_date', 'posting_date'] as $field) {
            $this->merge([$field => $dates->parseDate((string) $this->input($field))?->toDateString()]);
        }
    }
}
