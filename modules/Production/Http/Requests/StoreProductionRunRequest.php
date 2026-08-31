<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreProductionRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'production_order_line_id' => ['required', 'integer', 'exists:production_order_lines,id'],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after:planned_start_at'],
            'production_shift_id' => ['nullable', 'integer', 'exists:production_shifts,id'],
            'production_machine_id' => ['nullable', 'integer', 'exists:production_machines,id'],
            'cost_center_doc_num' => [
                'nullable',
                'string',
                Rule::exists('cost_centers', 'doc_num')->where(fn ($query) => $query
                    ->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId($this))
                    ->where('status', 'active')
                    ->where('is_group', false)
                    ->whereNull('deleted_at')),
            ],
            'production_mold_id' => ['nullable', 'integer', 'exists:production_molds,id'],
            'batch_lot' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
