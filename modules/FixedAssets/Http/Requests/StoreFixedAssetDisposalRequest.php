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
        $this->normalizeNumericInput(['proceeds', 'tax_rate', 'disposal_expenses']);
        $this->merge([
            'disposal_date' => app(DateFormatService::class)->normalizeForStorage($this->nullableTrim('disposal_date')),
            'disposition_type' => $this->nullableTrim('disposition_type'),
            'reason' => $this->nullableTrim('reason'),
            'customer_doc_num' => $this->nullableTrim('customer_doc_num'),
            'proceeds_account_doc_num' => $this->nullableTrim('proceeds_account_doc_num'),
            'proceeds' => $this->nullableTrim('proceeds') ?? '0',
            'settlement_path' => $this->nullableTrim('settlement_path') ?? FixedAssetDisposal::SettlementDirect,
            'tax_rate' => $this->nullableTrim('tax_rate') ?? '0',
            'due_date' => app(DateFormatService::class)->normalizeForStorage($this->nullableTrim('due_date')),
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
            'disposal_expenses' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'expenses_account_doc_num' => ['nullable', 'string', Rule::requiredIf(fn (): bool => is_numeric($this->input('disposal_expenses')) && (float) $this->input('disposal_expenses') > 0), Rule::exists('accounts', 'doc_num')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_postable', true)->where('is_group', false)->where('status', 'active')->whereNull('deleted_at'))],
            'proceeds' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
            'settlement_path' => ['required', Rule::in([FixedAssetDisposal::SettlementDirect, FixedAssetDisposal::SettlementCustomerInvoice])],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'due_date' => ['nullable', 'date', 'after_or_equal:disposal_date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $proceeds = $this->input('proceeds', '0');

            if ($this->input('settlement_path') === FixedAssetDisposal::SettlementDirect && bccomp((string) $proceeds, '0', 4) > 0 && ! $this->filled('proceeds_account_doc_num')) {
                $validator->errors()->add('proceeds_account_doc_num', __('fixed_assets.lifecycle.errors.proceeds_account_required'));
            }

            if ($this->input('settlement_path') === FixedAssetDisposal::SettlementCustomerInvoice && ! $this->filled('customer_doc_num')) {
                $validator->errors()->add('customer_doc_num', __('A Customer is required for an invoiced Fixed Asset sale.'));
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
