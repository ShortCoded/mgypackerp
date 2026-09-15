<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\OperatingCompanyContextService;

class SaveProductProductionRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.product_stages.manage');
    }

    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);

        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'selected_stage_ids' => ['present', 'array'],
            'selected_stage_ids.*' => ['integer', 'distinct', Rule::exists('production_stages', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'stage_sequences' => ['nullable', 'array'],
            'stage_sequences.*' => ['integer', 'min:1', 'max:100000'],
            'component_stage_ids' => ['nullable', 'array'],
            'component_stage_ids.*' => [
                'nullable',
                'integer',
                Rule::exists('production_stages', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $selectedStageIds = collect($this->input('selected_stage_ids', []))->map(fn ($id): int => (int) $id);

            foreach ($this->input('component_stage_ids', []) as $publicId => $stageId) {
                if (filled($stageId) && ! $selectedStageIds->contains((int) $stageId)) {
                    $validator->errors()->add("component_stage_ids.{$publicId}", __('production_execution.messages.component_stage_must_be_selected'));
                }
            }
        }];
    }
}
