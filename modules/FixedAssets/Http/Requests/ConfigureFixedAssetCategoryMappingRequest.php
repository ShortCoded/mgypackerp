<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Services\OperatingCompanyContextService;

class ConfigureFixedAssetCategoryMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.accounting.configure');
    }

    protected function prepareForValidation(): void
    {
        foreach (array_keys($this->rules()) as $key) {
            $this->merge([$key => trim((string) $this->input($key)) ?: null]);
        }
    }

    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        $postable = fn () => Rule::exists('accounts', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_postable', true)->where('is_group', false)->where('status', 'active')->whereNull('deleted_at'));

        return [
            'asset_group_account_doc_num' => ['required', 'string', Rule::exists('accounts', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_group', true)->where('is_postable', false)->where('status', 'active')->whereNull('deleted_at'))],
            'accumulated_depreciation_account_doc_num' => ['nullable', 'string', $postable()],
            'depreciation_expense_account_doc_num' => ['nullable', 'string', $postable()],
            'disposal_gain_account_doc_num' => ['nullable', 'string', $postable()],
            'disposal_loss_account_doc_num' => ['nullable', 'string', $postable()],
            'disposal_clearing_account_doc_num' => ['nullable', 'string', $postable()],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('asset_group_account_doc_num')) {
                return;
            }

            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
            $category = Account::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $this->input('asset_group_account_doc_num'))
                ->whereNull('deleted_at')
                ->first();

            if (! $category instanceof Account || ! app(BusinessPartnerAccountService::class)->isSelectableGroup(BusinessPartnerAccountService::FixedAsset, $category)) {
                $validator->errors()->add('asset_group_account_doc_num', __('fixed_assets.messages.asset_category_unavailable'));
            }
        }];
    }
}
