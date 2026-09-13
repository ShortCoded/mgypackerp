<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMaintenanceWorkOrder extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.orders.create');
    }

    public function rules(): array
    {
        return [
            'maintenance_request_doc_num' => ['nullable', 'string'],
            'fixed_asset_id' => ['required_without:maintenance_request_doc_num', 'nullable', 'integer'],
            'production_run_id' => ['nullable', 'integer'],
            'production_mold_id' => ['nullable', 'integer'],
            'maintenance_type' => ['required', Rule::in(['preventive', 'corrective', 'emergency', 'external'])],
            'discipline' => ['nullable', Rule::in(['electrical', 'mechanical', 'molds', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'service_mode' => ['required', Rule::in(['internal', 'external'])],
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
