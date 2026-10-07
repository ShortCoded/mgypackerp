<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;

class ProductionDailyReportRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.runs.progress');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['working_hours', 'lines.*.quantity', 'lines.*.cavities', 'lines.*.cycle_seconds',
            'lines.*.piece_weight_grams', 'lines.*.average_piece_weight_grams', 'lines.*.machine_speed',
            'lines.*.packing_ratio', 'lines.*.actual_pieces', 'lines.*.carton_count', 'lines.*.material_used',
            'lines.*.actual_waste', 'lines.*.intact_roll_waste', 'lines.*.working_hours', 'lines.*.stage_input_base_quantity', 'lines.*.rejected_quantity', 'lines.*.rework_quantity', 'lines.*.scrap_quantity']);
        $this->merge(['lines' => collect($this->input('lines', []))->filter(function (mixed $line): bool {
            if (! is_array($line)) {
                return true;
            }
            foreach (['quantity', 'rejected_quantity', 'rework_quantity', 'scrap_quantity'] as $field) {
                if (filled($line[$field] ?? null) && (! is_numeric($line[$field]) || bccomp((string) $line[$field], '0', 8) !== 0)) {
                    return true;
                }
            }

            return false;
        })->map(fn (mixed $line): mixed => is_array($line) ? [...$line, 'quantity' => filled($line['quantity'] ?? null) ? $line['quantity'] : '0'] : $line)->values()->all()]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['sheet_kind' => ['required', Rule::in(['injection', 'cover'])],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'hr_shift_id' => ['required', 'integer', 'min:1'],
            'working_hours' => ['required_if:sheet_kind,injection', 'nullable', 'numeric', 'gt:0', 'max:24', 'decimal:0,4'],
            'started_time' => ['required_if:sheet_kind,cover', 'nullable', 'date_format:H:i'],
            'ended_time' => ['required_if:sheet_kind,cover', 'nullable', 'date_format:H:i'],
            'technician_names' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.run_public_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'decimal:0,8'],
            'lines.*.stage_input_base_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,8'],
            'lines.*.technician_names' => ['nullable', 'string', 'max:2000'],
            'lines.*.working_hours' => ['nullable', 'numeric', 'gt:0', 'max:24', 'decimal:0,4'],
            'lines.*.started_time' => ['nullable', 'required_with:lines.*.ended_time', 'date_format:H:i'],
            'lines.*.ended_time' => ['nullable', 'required_with:lines.*.started_time', 'date_format:H:i'], 'lines.*.notes' => ['nullable', 'string', 'max:5000']];
        foreach (['rejected_quantity', 'rework_quantity', 'scrap_quantity', 'cavities', 'cycle_seconds', 'piece_weight_grams', 'average_piece_weight_grams', 'machine_speed', 'packing_ratio',
            'actual_pieces', 'carton_count', 'material_used', 'actual_waste', 'intact_roll_waste'] as $field) {
            $rules['lines.*.'.$field] = ['nullable', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,8'];
        }
        foreach (['customer_name', 'bag_type', 'bag_size', 'carton_type', 'carton_size', 'cover_components', 'material_unit', 'material_name', 'roll_reference', 'waste_unit'] as $field) {
            $rules['lines.*.'.$field] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }
}
