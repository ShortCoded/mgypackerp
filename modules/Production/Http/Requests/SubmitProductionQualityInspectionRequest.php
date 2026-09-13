<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitProductionQualityInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.quality.submit');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in(['passed', 'failed', 'conditional'])],
            'disposition' => ['required', Rule::in(['release', 'hold', 'rework', 'scrap', 'return'])],
            'defect_code' => ['nullable', 'string', 'max:100'],
            'affected_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'rework_notes' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'evidence_files' => ['nullable', 'array', 'max:10'],
            'evidence_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:10240'],
            'results' => ['nullable', 'array'],
            'results.*.quality_checkpoint_id' => ['required', 'integer', 'distinct'],
            'results.*.result' => ['required', Rule::in(['passed', 'failed', 'conditional', 'pending'])],
            'results.*.measured_value' => ['nullable', 'string', 'max:255'],
            'results.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
