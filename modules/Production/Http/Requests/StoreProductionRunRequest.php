<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'production_mold_id' => ['nullable', 'integer', 'exists:production_molds,id'],
            'batch_lot' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
