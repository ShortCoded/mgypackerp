<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $maintainable = $this->string('maintainable_key')->trim()->toString();
        if ($maintainable === '') {
            return;
        }

        [$type, $id] = array_pad(explode(':', $maintainable, 2), 2, null);
        if ($type === 'asset' && ctype_digit((string) $id)) {
            $this->merge(['fixed_asset_id' => (int) $id, 'production_mold_id' => null]);
        } elseif ($type === 'mold' && ctype_digit((string) $id)) {
            $this->merge(['fixed_asset_id' => null, 'production_mold_id' => (int) $id]);
        }
    }

    public function authorize(): bool
    {
        $permission = $this->isMethod('PUT') || $this->isMethod('PATCH')
            ? 'maintenance.requests.edit'
            : 'maintenance.requests.create';

        return (bool) $this->user()?->can($permission);
    }

    public function rules(): array
    {
        return [
            'maintainable_key' => ['nullable', 'string', 'regex:/^(asset|mold):[1-9][0-9]*$/'],
            'fixed_asset_id' => ['nullable', 'required_without:production_mold_id', 'integer'],
            'production_mold_id' => ['nullable', 'required_without:fixed_asset_id', 'integer'],
            'production_run_id' => ['nullable', 'integer'],
            'quality_inspection_id' => ['nullable', 'integer'],
            'reported_at' => ['nullable', 'date'],
            'is_machine_stopped' => ['nullable', 'boolean'],
            'request_type' => ['required', Rule::in(['breakdown', 'inspection'])],
            'discipline' => ['nullable', Rule::in(['electrical', 'mechanical', 'molds', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'symptoms' => ['required', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
