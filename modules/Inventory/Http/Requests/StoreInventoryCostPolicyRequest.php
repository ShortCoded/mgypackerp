<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\InventoryCostPolicy;

class StoreInventoryCostPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.cost_policies.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_doc_num' => ['nullable', 'string', 'max:100'],
            'branch_store_uuid' => ['nullable', 'uuid'],
            'method' => ['required', Rule::in([InventoryCostPolicy::MovingAverage, InventoryCostPolicy::PeriodicWeightedAverage, InventoryCostPolicy::Fifo, InventoryCostPolicy::SpecificIdentification])],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
