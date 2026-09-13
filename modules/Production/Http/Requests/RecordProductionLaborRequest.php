<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordProductionLaborRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.runs.labor');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actual_labor_count' => ['required', 'integer', 'min:1', 'max:100000'],
            'labor_details' => ['required', 'array', 'min:1', 'max:200'],
            'labor_details.*.employee_id' => ['nullable', 'integer'],
            'labor_details.*.name' => ['required', 'string', 'max:255'],
            'labor_details.*.role' => ['nullable', 'string', 'max:255'],
            'labor_details.*.planned_hours' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'labor_details.*.actual_hours' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'labor_details.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->integer('actual_labor_count') < count($this->input('labor_details', []))) {
                $validator->errors()->add('actual_labor_count', __('production_execution.messages.labor_count_too_small'));
            }
        }];
    }
}
