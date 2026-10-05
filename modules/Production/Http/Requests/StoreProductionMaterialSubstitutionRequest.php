<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\OperatingContextService;

final class StoreProductionMaterialSubstitutionRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return ($this->user()?->can('production.runs.correct') ?? false) && ($this->user()?->can('production.runs.issue') ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['quantity']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingContextService::class)->snapshot($this)['company_id'];

        return ['fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/D'],
            'requirement_public_id' => ['required', 'uuid'],
            'replacement_product_doc_num' => ['required', 'string', Rule::exists('products', 'doc_num')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at')],
            'branch_store_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'reason' => ['required', 'string', 'max:2000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['quantity' => __('Quantity'), 'reason' => __('production_material_substitution.reason'),
            'replacement_product_doc_num' => __('production_material_substitution.replacement'), 'branch_store_id' => __('Store')];
    }
}
