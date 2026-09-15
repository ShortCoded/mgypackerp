<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceWorkOrder extends FormRequest
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
            ? 'maintenance.orders.edit'
            : 'maintenance.orders.create';

        return (bool) $this->user()?->can($permission);
    }

    public function rules(): array
    {
        return [
            'maintenance_request_doc_num' => ['nullable', 'string'],
            'maintainable_key' => ['nullable', 'string', 'regex:/^(asset|mold):[1-9][0-9]*$/'],
            'fixed_asset_id' => ['nullable', 'required_without_all:maintenance_request_doc_num,production_mold_id', 'integer'],
            'production_run_id' => ['nullable', 'integer'],
            'production_mold_id' => ['nullable', 'integer'],
            'maintenance_type' => ['required', Rule::in(['preventive', 'corrective', 'emergency', 'condition_based', 'external'])],
            'discipline' => ['nullable', Rule::in(['electrical', 'mechanical', 'molds', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'service_mode' => ['required', Rule::in(['internal', 'external', 'mixed'])],
            'supplier_id' => ['nullable', 'integer'],
            'external_provider_name' => ['nullable', 'string', 'max:255'],
            'external_provider_contact' => ['nullable', 'string', 'max:255'],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date', 'after:planned_start_at'],
            'work_description' => ['required', 'string', 'max:5000'],
            'external_cost' => ['nullable', 'numeric', 'min:0'],
            'next_due_date' => ['nullable', 'date'],
        ];
    }
}
