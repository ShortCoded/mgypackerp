<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;

class FixedAssetDepreciationRunRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        $permission = $this->routeIs('admin.fixed-assets.depreciation.post') ? 'fixed_assets.depreciation.post' : 'fixed_assets.depreciation.preview';

        return (bool) $this->user()?->can($permission);
    }

    protected function prepareForValidation(): void
    {
        $usageUnits = collect($this->input('usage_units', []))->map(function (mixed $value): ?string {
            $normalized = app(NumericFormatService::class)->normalizeForValidation($value);

            return $normalized === null || trim($normalized) === '' ? null : $normalized;
        })->filter(fn ($value): bool => $value !== null)->all();
        $this->merge([
            'financial_period_doc_num' => $this->nullableTrim('financial_period_doc_num'),
            'posting_date' => app(DateFormatService::class)->normalizeForStorage($this->nullableTrim('posting_date')),
            'branch_doc_num' => $this->nullableTrim('branch_doc_num'),
            'asset_group_account_doc_num' => $this->nullableTrim('asset_group_account_doc_num'),
            'cost_center_doc_num' => $this->nullableTrim('cost_center_doc_num'),
            'asset_doc_nums' => array_values(array_filter(array_map(fn ($value): string => trim((string) $value), (array) $this->input('asset_doc_nums', [])))),
            'usage_units' => $usageUnits,
        ]);
    }

    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();

        return [
            'financial_period_doc_num' => ['required', 'string', Rule::exists('financial_periods', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'posting_date' => ['required', 'date'],
            'branch_doc_num' => ['nullable', 'string', Rule::exists('branches', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'asset_group_account_doc_num' => ['nullable', 'string', Rule::exists('accounts', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'cost_center_doc_num' => ['nullable', 'string', Rule::exists('cost_centers', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'asset_doc_nums' => [$this->routeIs('admin.fixed-assets.depreciation.post') ? 'required' : 'nullable', 'array'],
            'asset_doc_nums.*' => ['string', 'distinct', Rule::exists('fixed_assets', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))],
            'usage_units' => ['nullable', 'array'],
            'usage_units.*' => ['numeric', 'decimal:0,4', 'min:0'],
        ];
    }

    private function nullableTrim(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
