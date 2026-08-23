<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\FixedAssets\Models\FixedAssetDisposal;

class StoreFixedAssetDisposalRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fixed_assets.dispose');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['proceeds']);
        $this->merge([
            'disposal_date' => app(DateFormatService::class)->normalizeForStorage($this->nullableTrim('disposal_date')),
            'disposition_type' => $this->nullableTrim('disposition_type'),
            'reason' => $this->nullableTrim('reason'),
            'customer_doc_num' => $this->nullableTrim('customer_doc_num'),
            'proceeds_account_doc_num' => $this->nullableTrim('proceeds_account_doc_num'),
            'proceeds' => $this->nullableTrim('proceeds') ?? '0',
            'notes' => $this->nullableTrim('notes'),
        ]);
    }

    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();

        return [
            'disposal_date' => ['required', 'date'],
            'disposition_type' => ['required', Rule::in(FixedAssetDisposal::types())],
            'reason' => ['required', 'string'],
            'customer_doc_num' => ['nullable', 'string', Rule::exists('customers', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'))],
            'proceeds_account_doc_num' => ['nullable', 'string', Rule::exists('accounts', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_postable', true)->where('is_group', false)->where('status', 'active')->whereNull('deleted_at'))],
            'proceeds' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $proceeds = $this->input('proceeds', '0');

            if (bccomp((string) $proceeds, '0', 4) > 0 && ! $this->filled('proceeds_account_doc_num')) {
                $validator->errors()->add('proceeds_account_doc_num', __('fixed_assets.lifecycle.errors.proceeds_account_required'));
            }

            if ($this->input('disposition_type') === FixedAssetDisposal::TypeSale && bccomp((string) $proceeds, '0', 4) <= 0) {
                $validator->errors()->add('proceeds', __('fixed_assets.lifecycle.errors.sale_proceeds_required'));
            }
        }];
    }

    private function nullableTrim(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
