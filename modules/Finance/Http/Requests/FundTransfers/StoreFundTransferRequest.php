<?php

namespace Modules\Finance\Http\Requests\FundTransfers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\FundTransfer;
use Modules\Finance\Services\FinanceAmountService;

class StoreFundTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->filled('clone_source_token') ? 'clone' : 'create';

        return (bool) $this->user()?->can('fund_transfers.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        $this->merge([
            'company_id' => $context['company_id'],
            'transfer_date' => $this->filled('transfer_date') ? trim((string) $this->input('transfer_date')) : null,
            'source_type' => $this->filled('source_type') ? trim((string) $this->input('source_type')) : null,
            'source_cashbox_doc_num' => $this->filled('source_cashbox_doc_num') ? trim((string) $this->input('source_cashbox_doc_num')) : null,
            'source_bank_account_doc_num' => $this->filled('source_bank_account_doc_num') ? trim((string) $this->input('source_bank_account_doc_num')) : null,
            'target_type' => $this->filled('target_type') ? trim((string) $this->input('target_type')) : null,
            'target_cashbox_doc_num' => $this->filled('target_cashbox_doc_num') ? trim((string) $this->input('target_cashbox_doc_num')) : null,
            'target_bank_account_doc_num' => $this->filled('target_bank_account_doc_num') ? trim((string) $this->input('target_bank_account_doc_num')) : null,
            'source_currency_doc_num' => $this->filled('source_currency_doc_num') ? trim((string) $this->input('source_currency_doc_num')) : null,
            'target_currency_doc_num' => $this->filled('target_currency_doc_num') ? trim((string) $this->input('target_currency_doc_num')) : null,
            'source_amount' => $this->filled('source_amount') ? str_replace(',', '', (string) $this->input('source_amount')) : null,
            'exchange_rate' => $this->filled('exchange_rate') ? str_replace(',', '', (string) $this->input('exchange_rate')) : 1,
            'target_amount' => $this->filled('target_amount') ? str_replace(',', '', (string) $this->input('target_amount')) : null,
            'reason' => $this->filled('reason') ? trim((string) $this->input('reason')) : null,
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueActiveDocumentNumberRule()],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'transfer_date' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail($this->message('transfer_date_invalid'));
                }
            }],
            'source_type' => ['required', Rule::in(FundTransfer::holderTypes())],
            'source_cashbox_doc_num' => [$this->requiredWhenHolder('source', FundTransfer::HolderCashbox), 'nullable', 'string', $this->cashboxExistsRule()],
            'source_bank_account_doc_num' => [$this->requiredWhenHolder('source', FundTransfer::HolderBankAccount), 'nullable', 'string', $this->bankAccountExistsRule()],
            'target_type' => ['required', Rule::in(FundTransfer::holderTypes())],
            'target_cashbox_doc_num' => [$this->requiredWhenHolder('target', FundTransfer::HolderCashbox), 'nullable', 'string', $this->cashboxExistsRule()],
            'target_bank_account_doc_num' => [$this->requiredWhenHolder('target', FundTransfer::HolderBankAccount), 'nullable', 'string', $this->bankAccountExistsRule()],
            'source_currency_doc_num' => ['required', 'string', $this->currencyExistsRule()],
            'target_currency_doc_num' => ['required', 'string', $this->currencyExistsRule()],
            'source_amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'target_amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'submit_action' => ['nullable', Rule::in(['save', 'save_new'])],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_amount.gt' => $this->message('source_amount_positive'),
            'target_amount.gt' => $this->message('target_amount_positive'),
            'exchange_rate.gt' => $this->message('exchange_rate_positive'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sourceCashbox = $this->holderCashbox('source');
            $sourceBankAccount = $this->holderBankAccount('source');
            $targetCashbox = $this->holderCashbox('target');
            $targetBankAccount = $this->holderBankAccount('target');
            $sourceCurrency = $this->currency('source_currency_doc_num');
            $targetCurrency = $this->currency('target_currency_doc_num');

            $this->validateBusiness($validator, $sourceCashbox, $sourceBankAccount, $targetCashbox, $targetBankAccount, $sourceCurrency, $targetCurrency, $this->currentRecord());
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => $this->attribute('doc_number'),
            'transfer_date' => $this->attribute('transfer_date'),
            'source_type' => $this->attribute('source_type'),
            'source_cashbox_doc_num' => $this->attribute('source_cashbox'),
            'source_bank_account_doc_num' => $this->attribute('source_bank_account'),
            'target_type' => $this->attribute('target_type'),
            'target_cashbox_doc_num' => $this->attribute('target_cashbox'),
            'target_bank_account_doc_num' => $this->attribute('target_bank_account'),
            'source_currency_doc_num' => $this->attribute('source_currency'),
            'target_currency_doc_num' => $this->attribute('target_currency'),
            'source_amount' => $this->attribute('source_amount'),
            'exchange_rate' => $this->attribute('exchange_rate'),
            'target_amount' => $this->attribute('target_amount'),
            'reason' => $this->attribute('reason'),
            'description' => $this->attribute('description'),
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        if (array_key_exists('transfer_date', $data)) {
            $data['transfer_date'] = app(DateFormatService::class)->normalizeForStorage((string) $data['transfer_date']);
        }

        return $data;
    }

    protected function currentRecord(): ?FundTransfer
    {
        return null;
    }

    protected function message(string $key): string
    {
        return __("fund_transfers.messages.{$key}");
    }

    protected function attribute(string $key): string
    {
        return __("fund_transfers.attributes.{$key}");
    }

    protected function validateBusiness(
        Validator $validator,
        ?Cashbox $sourceCashbox,
        ?BankAccount $sourceBankAccount,
        ?Cashbox $targetCashbox,
        ?BankAccount $targetBankAccount,
        ?Currency $sourceCurrency,
        ?Currency $targetCurrency,
        ?FundTransfer $current = null
    ): void {
        if ($current instanceof FundTransfer && $current->isLockedForEditing()) {
            $validator->errors()->add('document', $this->message('document_locked'));
        }

        $this->validateHolderCurrency($validator, 'source', $sourceCashbox, $sourceBankAccount, $sourceCurrency);
        $this->validateHolderCurrency($validator, 'target', $targetCashbox, $targetBankAccount, $targetCurrency);
        $this->validateNoop($validator);
        $this->validateAmounts($validator, $sourceCurrency, $targetCurrency);
    }

    private function validateHolderCurrency(Validator $validator, string $side, ?Cashbox $cashbox, ?BankAccount $bankAccount, ?Currency $currency): void
    {
        if ($cashbox instanceof Cashbox && ($cashbox->status !== 'active' || $cashbox->trashed())) {
            $validator->errors()->add("{$side}_cashbox_doc_num", $this->message('cashbox_inactive'));
        }

        if ($bankAccount instanceof BankAccount && ($bankAccount->status !== 'active' || $bankAccount->trashed())) {
            $validator->errors()->add("{$side}_bank_account_doc_num", $this->message('bank_account_inactive'));
        }

        if (! $currency instanceof Currency) {
            return;
        }

        if ($currency->status !== 'active' || $currency->trashed()) {
            $validator->errors()->add("{$side}_currency_doc_num", $this->message('currency_inactive'));
        }

        if ($bankAccount instanceof BankAccount && (int) $bankAccount->currency_id !== (int) $currency->getKey()) {
            $validator->errors()->add("{$side}_currency_doc_num", $this->message('currency_not_allowed_for_bank_account'));
        }

        if ($cashbox instanceof Cashbox && ! $this->currencyAllowedForCashbox($cashbox, $currency)) {
            $validator->errors()->add("{$side}_currency_doc_num", $this->message('currency_not_allowed_for_cashbox'));
        }
    }

    private function validateNoop(Validator $validator): void
    {
        if ($this->holderToken('source') !== $this->holderToken('target')) {
            return;
        }

        if ($this->input('source_currency_doc_num') === $this->input('target_currency_doc_num')) {
            $validator->errors()->add('target_currency_doc_num', $this->message('noop_transfer'));
        }
    }

    private function validateAmounts(Validator $validator, ?Currency $sourceCurrency, ?Currency $targetCurrency): void
    {
        if (! $sourceCurrency instanceof Currency || ! $targetCurrency instanceof Currency) {
            return;
        }

        $amounts = app(FinanceAmountService::class);
        $sourceUnits = $amounts->toUnits($this->input('source_amount'));
        $targetUnits = $amounts->toUnits($this->input('target_amount'));
        $rate = (float) $this->input('exchange_rate');

        if ((int) $sourceCurrency->getKey() === (int) $targetCurrency->getKey()) {
            if (abs($rate - 1.0) > 0.000001) {
                $validator->errors()->add('exchange_rate', $this->message('same_currency_exchange_rate'));
            }

            if ($sourceUnits !== $targetUnits) {
                $validator->errors()->add('target_amount', $this->message('same_currency_target_amount'));
            }

            return;
        }

        $expectedTargetUnits = $amounts->toUnits($amounts->multiply($this->input('source_amount'), $this->input('exchange_rate'), 4));

        if ($targetUnits !== $expectedTargetUnits) {
            $validator->errors()->add('target_amount', $this->message('target_amount_mismatch'));
        }
    }

    private function requiredWhenHolder(string $side, string $holderType): mixed
    {
        return Rule::requiredIf(fn (): bool => $this->input("{$side}_type") === $holderType);
    }

    private function cashboxExistsRule(): mixed
    {
        return Rule::exists('cashboxes', 'doc_num')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->where('status', 'active')
                ->whereNull('deleted_at'));
    }

    private function bankAccountExistsRule(): mixed
    {
        return Rule::exists('bank_accounts', 'doc_num')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->where('status', 'active')
                ->whereNull('deleted_at'));
    }

    private function currencyExistsRule(): mixed
    {
        return Rule::exists('currencies', 'doc_num')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->where('status', 'active')
                ->whereNull('deleted_at'));
    }

    private function holderCashbox(string $side): ?Cashbox
    {
        if ($this->input("{$side}_type") !== FundTransfer::HolderCashbox) {
            return null;
        }

        return Cashbox::query()
            ->where('company_id', $this->input('company_id'))
            ->where('doc_num', $this->input("{$side}_cashbox_doc_num"))
            ->first();
    }

    private function holderBankAccount(string $side): ?BankAccount
    {
        if ($this->input("{$side}_type") !== FundTransfer::HolderBankAccount) {
            return null;
        }

        return BankAccount::query()
            ->where('company_id', $this->input('company_id'))
            ->where('doc_num', $this->input("{$side}_bank_account_doc_num"))
            ->first();
    }

    private function currency(string $field): ?Currency
    {
        return Currency::query()
            ->where('company_id', $this->input('company_id'))
            ->where('doc_num', $this->input($field))
            ->first();
    }

    private function holderToken(string $side): string
    {
        $type = (string) $this->input("{$side}_type");
        $docNum = $type === FundTransfer::HolderBankAccount
            ? (string) $this->input("{$side}_bank_account_doc_num")
            : (string) $this->input("{$side}_cashbox_doc_num");

        return $type.':'.$docNum;
    }

    private function currencyAllowedForCashbox(Cashbox $cashbox, Currency $currency): bool
    {
        $hasRestrictions = $cashbox->currencies()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasRestrictions) {
            return true;
        }

        return $cashbox->currencies()
            ->where('status', 'active')
            ->where('currency_id', $currency->getKey())
            ->whereNull('deleted_at')
            ->exists();
    }

    private function uniqueActiveDocumentNumberRule(): mixed
    {
        $rule = Rule::unique('fund_transfers', 'doc_number')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->whereNull('deleted_at'));

        $current = $this->currentRecord();

        return $current instanceof FundTransfer ? $rule->ignore($current->getKey()) : $rule;
    }
}
