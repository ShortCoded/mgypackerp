<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductionMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.material_requests.create');
    }

    public function rules(): array
    {
        return [
            'production_run_id' => ['required', 'integer'],
            'branch_store_id' => ['required', 'integer'],
            'additional' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:additional,1'],
            'required_by_date' => ['nullable', 'date'],
            'lines' => ['nullable', 'array'],
            'lines.*.requirement_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
        ];
    }
}
