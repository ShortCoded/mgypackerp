<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Production\Services\ProductionShiftEvidenceService;

class ProductionShiftEvidenceRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->routeIs('*.shift-defaults') ? 'production.runs.setup' : 'production.runs.progress');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['downtime_minutes', 'sheet_fields.cavities', 'sheet_fields.cycle_seconds', 'sheet_fields.piece_weight_grams',
            'sheet_fields.average_piece_weight_grams', 'sheet_fields.machine_speed', 'sheet_fields.pack_ratio', 'crew.*.planned_hours']);
        if ($this->has('crew')) {
            $rows = collect($this->input('crew', []))->filter(fn (mixed $row): bool => is_array($row) && filled($row['employee_id'] ?? null))->values()->all();
            $this->merge(['crew' => $rows === [] ? null : $rows]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $defaults = $this->routeIs('*.shift-defaults');
        $common = [
            'crew' => [$defaults ? 'required' : 'nullable', 'array', 'min:1', 'max:100'],
            'crew.*.employee_id' => ['required', 'integer', 'min:1', 'distinct'],
            'crew.*.role' => ['required', Rule::in(['supervisor', 'technician', 'operator', 'foreman'])],
            'crew.*.planned_hours' => ['nullable', 'numeric', 'min:0', 'max:24', 'decimal:0,4'],
        ];
        if ($defaults) {
            return [...$common, 'shift_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
                'shift_name' => ['required', 'string', 'max:100'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i']];
        }
        $rules = [...$common, 'production_shift_id' => ['required', 'integer', 'min:1'], 'work_date' => ['required', 'date_format:Y-m-d'],
            'started_at' => ['required', 'date'], 'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'downtime_minutes' => ['nullable', 'numeric', 'min:0', 'max:1440', 'decimal:0,4'], 'notes' => ['nullable', 'string', 'max:5000'],
            'sheet_fields' => ['required', 'array:sheet_kind,cavities,cycle_seconds,piece_weight_grams,average_piece_weight_grams,machine_speed,pack_ratio,product_size,bag_type,bag_size,carton_type,carton_size,cover_components,primary_material_requirement_public_id'],
            'sheet_fields.sheet_kind' => ['required', Rule::in(['injection', 'cover'])],
            'sheet_fields.cavities' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sheet_fields.primary_material_requirement_public_id' => ['nullable', 'uuid'],
        ];
        foreach (ProductionShiftEvidenceService::NumericFields as $field) {
            $rules['sheet_fields.'.$field] = ['nullable', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,8'];
        }
        foreach (ProductionShiftEvidenceService::TextFields as $field) {
            $rules['sheet_fields.'.$field] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }
}
