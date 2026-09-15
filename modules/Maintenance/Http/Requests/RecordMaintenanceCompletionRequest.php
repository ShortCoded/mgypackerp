<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordMaintenanceCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.orders.complete');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'diagnosis' => ['required', 'string', 'max:5000'],
            'root_cause' => ['nullable', 'string', 'max:5000'],
            'work_performed' => ['required', 'string', 'max:5000'],
            'completion_notes' => ['nullable', 'string', 'max:5000'],
            'test_result' => ['required', Rule::in(['passed', 'failed'])],
            'repair_outcome' => ['nullable', 'required_if:test_result,passed', Rule::in(['permanent', 'temporary'])],
            'machine_released_at' => ['nullable', 'date'],
            'follow_up_due_at' => ['nullable', 'required_if:repair_outcome,temporary', 'date'],
            'next_due_date' => ['nullable', 'date'],
            'labor_details' => ['nullable', 'array', 'max:30'],
            'labor_details.*.name' => ['required', 'string', 'max:255'],
            'labor_details.*.discipline' => ['nullable', Rule::in(['electrical', 'mechanical', 'molds', 'other'])],
            'labor_details.*.actual_hours' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'labor_details.*.notes' => ['nullable', 'string', 'max:1000'],
            'material_usage' => ['nullable', 'array', 'max:100'],
            'material_usage.*.line_id' => ['required', 'integer', 'distinct'],
            'material_usage.*.consumed_quantity' => ['required', 'numeric', 'min:0'],
        ];
    }
}
