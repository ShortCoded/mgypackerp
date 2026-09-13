<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionQualityInspectionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.quality.report');
    }

    public function rules(): array
    {
        return [
            'reported_at' => ['required', 'date'],
            'result' => ['required', Rule::in(['pending', 'passed', 'failed', 'conditional'])],
            'disposition' => ['nullable', Rule::in(['release', 'hold', 'rework', 'scrap', 'return'])],
            'defect_code' => ['nullable', 'string', 'max:100'],
            'affected_base_quantity' => ['nullable', 'numeric', 'min:0'],
            'observations' => ['required', 'string', 'max:5000'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
            'evidence_files' => ['nullable', 'array', 'max:12'],
            'evidence_files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }
}
