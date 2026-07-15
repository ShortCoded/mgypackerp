<?php

namespace Modules\Finance\Http\Requests\BankAccounts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Services\BankAccountChartAccountService;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'bank_accounts.clone' : 'bank_accounts.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bank_doc_num' => $this->filled('bank_doc_num')
                ? trim((string) $this->input('bank_doc_num'))
                : ($this->filled('account_doc_num') ? trim((string) $this->input('account_doc_num')) : null),
            'currency_doc_num' => $this->filled('currency_doc_num') ? trim((string) $this->input('currency_doc_num')) : null,
            'account_name' => trim((string) $this->input('account_name')),
            'account_number' => $this->nullableTrimmedInput('account_number'),
            'iban' => $this->nullableTrimmedInput('iban'),
            'swift_code' => strtoupper(trim((string) $this->input('swift_code'))),
            'owner_name' => trim((string) $this->input('owner_name')),
            'bank_branch_name' => trim((string) $this->input('bank_branch_name')),
        ]);
    }

    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);

        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueActiveBankAccountRule('doc_number')],
            'bank_doc_num' => [
                'required',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'currency_doc_num' => [
                'required',
                'string',
                Rule::exists('currencies', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100', $this->uniqueActiveBankAccountRule('account_number')],
            'iban' => ['nullable', 'string', 'max:100', $this->uniqueActiveBankAccountRule('iban')],
            'swift_code' => ['nullable', 'string', 'max:50'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'bank_branch_name' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_number.unique' => __('bank_accounts.messages.account_number_used'),
            'doc_number.unique' => __('bank_accounts.messages.doc_number_unique'),
            'iban.unique' => __('bank_accounts.messages.iban_used'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $chartAccounts = app(BankAccountChartAccountService::class);
            $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);
            $account = Account::query()
                ->with('classification')
                ->forCompany($companyId)
                ->where('doc_num', $this->input('bank_doc_num'))
                ->first();
            $currency = Currency::query()->forCompany($companyId)->where('doc_num', $this->input('currency_doc_num'))->first();

            if ($account && ! $chartAccounts->isSelectableBankGroup($account)) {
                $validator->errors()->add('bank_doc_num', __('bank_accounts.messages.account_outside_bank_accounts'));
            }

            if ($account && $currency && $this->hasDuplicateBankAccount($account, $currency)) {
                $validator->errors()->add('bank_doc_num', __('bank_accounts.messages.account_used'));
            }

            if ($currency && $currency->status !== 'active') {
                $validator->errors()->add('currency_doc_num', __('bank_accounts.messages.currency_inactive'));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('bank_accounts.attributes.doc_number'),
            'bank_doc_num' => __('bank_accounts.attributes.bank'),
            'currency_doc_num' => __('bank_accounts.attributes.currency'),
            'account_name' => __('bank_accounts.attributes.account_name'),
            'account_number' => __('bank_accounts.attributes.account_number'),
            'iban' => __('bank_accounts.attributes.iban'),
            'swift_code' => __('bank_accounts.attributes.swift_code'),
            'owner_name' => __('bank_accounts.attributes.owner_name'),
            'bank_branch_name' => __('bank_accounts.attributes.bank_branch_name'),
            'status' => __('bank_accounts.attributes.status'),
            'notes' => __('bank_accounts.attributes.notes'),
        ];
    }

    protected function hasDuplicateBankAccount(Account $bankGroup, Currency $currency, ?BankAccount $current = null): bool
    {
        $accountNumber = trim((string) $this->input('account_number'));
        $linkedAccountName = app(BankAccountChartAccountService::class)->linkedAccountName($this->validatedPayloadForDuplicateCheck(), $currency);

        $query = BankAccount::query()
            ->join('accounts', 'accounts.id', '=', 'bank_accounts.account_id')
            ->where('bank_accounts.company_id', $bankGroup->company_id)
            ->where('bank_accounts.bank_id', $bankGroup->getKey())
            ->where('bank_accounts.currency_id', $currency->getKey())
            ->where('bank_accounts.status', 'active')
            ->whereNull('bank_accounts.deleted_at')
            ->when($current, fn ($query) => $query->where('bank_accounts.id', '!=', $current->getKey()));

        if ($accountNumber !== '') {
            $query->where('bank_accounts.account_number', $accountNumber);
        } else {
            $query->where('accounts.name', $linkedAccountName);
        }

        return $query->exists();
    }

    protected function currentBankAccount(): ?BankAccount
    {
        return null;
    }

    private function nullableTrimmedInput(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }

    private function uniqueActiveBankAccountRule(string $column): Unique
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($this);
        $rule = Rule::unique('bank_accounts', $column)
            ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'));
        $current = $this->currentBankAccount();

        return $current ? $rule->ignore($current->getKey()) : $rule;
    }

    /**
     * @return array{account_name: string, account_number: string|null}
     */
    private function validatedPayloadForDuplicateCheck(): array
    {
        return [
            'account_name' => (string) $this->input('account_name'),
            'account_number' => $this->input('account_number') ?: null,
        ];
    }
}
