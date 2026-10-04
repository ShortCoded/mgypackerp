<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryCostPolicy;

class PrepareInventoryCostPolicyTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.cost_policies.transition.prepare');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'transition_branch_doc_num' => ['nullable', 'string', 'max:100'],
            'transition_branch_store_uuid' => ['nullable', 'uuid'],
            'transition_effective_from' => ['required', 'date_format:Y-m-d'],
            'transition_target_method' => ['sometimes', 'required', Rule::in([InventoryCostPolicy::Fifo, InventoryCostPolicy::SpecificIdentification])],
            'transition_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
