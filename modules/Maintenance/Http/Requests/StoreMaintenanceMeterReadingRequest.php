<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Maintenance\Models\MaintenancePlan;

class StoreMaintenanceMeterReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.plans.readings');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'basis' => ['required', Rule::in([
                MaintenancePlan::FrequencyOperatingHours,
                MaintenancePlan::FrequencyCycles,
                MaintenancePlan::FrequencyCondition,
            ])],
            'reading_value' => ['nullable', 'required_unless:basis,condition', 'numeric', 'gte:0'],
            'is_triggered' => ['nullable', 'boolean'],
            'reading_type' => ['required', Rule::in(['reading', 'correction', 'replacement'])],
            'recorded_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'uuid'],
            'notes' => [Rule::requiredIf(fn (): bool => in_array($this->input('reading_type'), ['correction', 'replacement'], true)), 'nullable', 'string', 'max:2000'],
        ];
    }
}
