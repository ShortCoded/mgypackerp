<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingContextService;
use Modules\Maintenance\Models\MaintenancePlan;

class StoreMaintenancePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.plans.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'fixed_asset_id' => [
                'nullable', 'required_without:production_mold_id', 'integer',
                Rule::exists('fixed_assets', 'id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->whereNull('deleted_at')),
            ],
            'production_mold_id' => [
                'nullable', 'required_without:fixed_asset_id', 'integer',
                Rule::exists('production_molds', 'id')->where(fn ($query) => $query
                    ->where('company_id', $context['company_id'])
                    ->where('branch_id', $context['branch_id'])
                    ->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'maintenance_type' => ['required', Rule::in(['preventive', 'condition_based'])],
            'discipline' => ['nullable', Rule::in(['electrical', 'mechanical', 'molds', 'other'])],
            'service_mode' => ['required', Rule::in(['internal', 'external', 'mixed'])],
            'supplier_id' => ['nullable', 'integer'],
            'external_provider_name' => [Rule::requiredIf(fn (): bool => in_array($this->input('service_mode'), ['external', 'mixed'], true) && ! $this->filled('supplier_id')), 'nullable', 'string', 'max:255'],
            'frequency_basis' => ['required', Rule::in([
                MaintenancePlan::FrequencyCalendar,
                MaintenancePlan::FrequencyOperatingHours,
                MaintenancePlan::FrequencyCycles,
                MaintenancePlan::FrequencyCondition,
            ])],
            'interval_value' => ['nullable', 'required_unless:frequency_basis,condition', 'numeric', 'gt:0'],
            'schedule_anchor' => ['required', Rule::in([MaintenancePlan::AnchorPlanned, MaintenancePlan::AnchorActual])],
            'next_due_at' => ['nullable', 'required_if:frequency_basis,calendar', 'date'],
            'next_meter_value' => ['nullable', 'required_if:frequency_basis,operating_hours,cycles', 'numeric', 'gte:0'],
            'task_template' => ['required', 'string', 'max:5000'],
            'expected_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
