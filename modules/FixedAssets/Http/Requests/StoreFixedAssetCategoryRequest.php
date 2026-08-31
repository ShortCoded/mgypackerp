<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreFixedAssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('accounts.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'notes' => trim((string) $this->input('notes')) ?: null,
        ]);
    }

    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);
        $rootAccountId = $companyId === null
            ? null
            : app(BusinessPartnerAccountService::class)
                ->rootAccount(BusinessPartnerAccountService::FixedAsset)
                ->getKey();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('accounts', 'name')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('parent_id', $rootAccountId)
                        ->where('status', 'active'))
                    ->withoutTrashed(),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => __('erp_errors.duplicate_name'),
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => __('fixed_assets.attributes.asset_category_name'),
            'notes' => __('fixed_assets.attributes.notes'),
        ];
    }
}
