<?php

namespace Modules\Finance\Http\Requests\BankAccounts;

use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Services\BankAccountChartAccountService;

class UpdateBankAccountRequest extends StoreBankAccountRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('bank_accounts.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clone_source_token']);

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var BankAccount|null $bankAccount */
            $bankAccount = $this->route('bankAccount');
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

            if ($account && $currency && $this->hasDuplicateBankAccount($account, $currency, $bankAccount)) {
                $validator->errors()->add('bank_doc_num', __('bank_accounts.messages.account_used'));
            }

            if ($currency && $currency->status !== 'active') {
                $validator->errors()->add('currency_doc_num', __('bank_accounts.messages.currency_inactive'));
            }
        });
    }

    protected function currentBankAccount(): ?BankAccount
    {
        $bankAccount = $this->route('bankAccount');

        return $bankAccount instanceof BankAccount ? $bankAccount : null;
    }
}
