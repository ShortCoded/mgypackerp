<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Branch;
use Modules\Core\Services\OperatingContextService;

class SaveProductionStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return (bool) $this->user()?->can($this->isMethod('post') ? 'production.stages.create' : 'production.stages.edit')
            && Branch::query()
                ->whereKey($context['branch_id'])
                ->where('company_id', $context['company_id'])
                ->where('type', Branch::TypeFactory)
                ->exists();
    }

    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'output_type' => ['nullable', 'string', 'max:80'],
            'standard_duration_value' => ['nullable', 'numeric', 'gt:0'],
            'standard_duration_unit' => ['nullable', Rule::in(['hours', 'days']), 'required_with:standard_duration_value'],
            'display_order' => ['required', 'integer', 'min:1', 'max:100000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'submit_action' => ['nullable', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new'])],
            'submit_intent' => ['nullable', Rule::in(['save_and_new', 'save_and_edit', 'save_and_back'])],
        ];
    }
}
