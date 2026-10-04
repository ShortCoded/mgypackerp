<?php

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelInventoryCostPolicyTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inventory.cost_policies.transition.cancel');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cancellation_reason' => ['required', 'string', 'max:1000']];
    }
}
