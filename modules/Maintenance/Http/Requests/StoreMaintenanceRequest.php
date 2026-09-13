<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.requests.create');
    }

    public function rules(): array
    {
        return [
            'fixed_asset_id' => ['required', 'integer'],
            'production_run_id' => ['nullable', 'integer'],
            'reported_at' => ['nullable', 'date'],
            'request_type' => ['required', Rule::in(['breakdown', 'inspection'])],
            'discipline' => ['nullable', Rule::in(['electrical', 'mechanical', 'molds', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'symptoms' => ['required', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
