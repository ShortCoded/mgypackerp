<?php

namespace Modules\Finance\Http\Requests\Cashboxes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Services\CashboxChartAccountService;

class StoreCashboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'cashboxes.clone' : 'cashboxes.create');
    }

    protected function prepareForValidation(): void
    {
        $currencies = collect((array) $this->input('currency_doc_nums', []))->filter()->map(fn ($value) => trim((string) $value))->values()->all();

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'parent_account_doc_num' => $this->filled('parent_account_doc_num')
                ? trim((string) $this->input('parent_account_doc_num'))
                : ($this->filled('account_doc_num') ? trim((string) $this->input('account_doc_num')) : null),
            'branch_doc_num' => $this->filled('branch_doc_num') ? trim((string) $this->input('branch_doc_num')) : null,
            'currency_doc_nums' => $currencies,
        ]);
    }

    public function rules(): array
    {
        $companyId = app(OperatingContextService::class)->snapshot($this)['company_id'];

        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueActiveCashboxRule('doc_number')],
            'name' => ['required', 'string', 'max:255'],
            'parent_account_doc_num' => [
                'nullable',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'branch_doc_num' => [
                'nullable',
                'string',
                Rule::exists('branches', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'currency_doc_nums' => ['nullable', 'array'],
            'currency_doc_nums.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('currencies', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $chartAccounts = app(CashboxChartAccountService::class);
            $parentAccount = $this->resolvedParentAccount($chartAccounts);
            $currencyDocNums = (array) $this->input('currency_doc_nums', []);
            $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');

            if ($this->filled('parent_account_doc_num') && ! $parentAccount) {
                $validator->errors()->add('parent_account_doc_num', __('cashboxes.messages.cashbox_group_unavailable'));
            }

            if ($this->filled('parent_account_doc_num') && $parentAccount && (! $parentAccount->is_group || $parentAccount->is_postable)) {
                $validator->errors()->add('parent_account_doc_num', __('cashboxes.messages.cashbox_group_must_be_group'));
            }

            if ($this->filled('parent_account_doc_num') && $parentAccount && $parentAccount->is_group && ! $chartAccounts->isSelectableCashboxParent($parentAccount)) {
                $validator->errors()->add('parent_account_doc_num', __('cashboxes.messages.cashbox_group_unavailable'));
            }

            if ($parentAccount && $this->hasDuplicateLinkedAccount($parentAccount)) {
                $validator->errors()->add('parent_account_doc_num', __('cashboxes.messages.linked_account_duplicate'));
            }

            $inactiveCurrencyExists = Currency::query()->where('company_id', $companyId)->whereIn('doc_num', $currencyDocNums)->where('status', '!=', 'active')->exists();
            if ($inactiveCurrencyExists) {
                $validator->errors()->add('currency_doc_nums', __('cashboxes.messages.currency_inactive'));
            }

            $branchDocNum = (string) $this->input('branch_doc_num');
            if ($branchDocNum !== '' && ! app(OperatingContextService::class)->allowedBranchForCurrentCompany($this, $branchDocNum)) {
                $validator->errors()->add('branch_doc_num', __('cashboxes.messages.branch_not_allowed'));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('cashboxes.attributes.doc_number'),
            'name' => __('cashboxes.attributes.name'),
            'parent_account_doc_num' => __('cashboxes.attributes.account_group'),
            'branch_doc_num' => __('cashboxes.attributes.branch'),
            'currency_doc_nums' => __('cashboxes.attributes.currencies'),
            'status' => __('cashboxes.attributes.status'),
            'notes' => __('cashboxes.attributes.notes'),
        ];
    }

    public function messages(): array
    {
        return [
            'doc_number.unique' => __('cashboxes.messages.doc_number_unique'),
        ];
    }

    protected function currentCashbox(): ?Cashbox
    {
        return null;
    }

    protected function hasDuplicateLinkedAccount(Account $parentAccount): bool
    {
        $current = $this->currentCashbox();
        $currentAccountId = $current?->account_id;
        $name = app(CashboxChartAccountService::class)->linkedAccountName([
            'name' => (string) $this->input('name'),
        ]);

        return Account::query()
            ->whereNull('deleted_at')
            ->where('company_id', $parentAccount->company_id)
            ->where('parent_id', $parentAccount->getKey())
            ->where('name', $name)
            ->when($currentAccountId, fn ($query) => $query->whereKeyNot($currentAccountId))
            ->exists();
    }

    private function resolvedParentAccount(CashboxChartAccountService $chartAccounts): ?Account
    {
        if (! $this->filled('parent_account_doc_num')) {
            try {
                return $chartAccounts->mainCashboxesAccount();
            } catch (\DomainException) {
                return null;
            }
        }

        return Account::query()
            ->with('classification')
            ->where('company_id', Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id'))
            ->where('doc_num', $this->input('parent_account_doc_num'))
            ->first();
    }

    private function uniqueActiveCashboxRule(string $column): Unique
    {
        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');
        $rule = Rule::unique('cashboxes', $column)
            ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'));
        $current = $this->currentCashbox();

        return $current ? $rule->ignore($current->getKey()) : $rule;
    }
}
