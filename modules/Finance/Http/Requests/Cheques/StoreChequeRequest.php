<?php

namespace Modules\Finance\Http\Requests\Cheques;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Services\FinanceAmountService;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;

class StoreChequeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $action = $this->filled('clone_source_token') ? 'clone' : 'create';

        return (bool) $this->user()?->can('cheques.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $context = app(OperatingContextService::class)->snapshot($this);
        $lines = collect($this->input('lines', []))
            ->filter(fn ($line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'account_doc_num' => isset($line['account_doc_num']) ? trim((string) $line['account_doc_num']) : null,
                'amount' => isset($line['amount']) ? str_replace(',', '', (string) $line['amount']) : null,
                'description' => isset($line['description']) ? trim((string) $line['description']) : null,
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
            ])
            ->values()
            ->all();

        $this->merge([
            'company_id' => $context['company_id'],
            'cheque_type' => $this->filled('cheque_type') ? trim((string) $this->input('cheque_type')) : null,
            'cheque_number' => $this->filled('cheque_number') ? trim((string) $this->input('cheque_number')) : null,
            'cheque_date' => $this->filled('cheque_date') ? trim((string) $this->input('cheque_date')) : null,
            'due_date' => $this->filled('due_date') ? trim((string) $this->input('due_date')) : null,
            'bank_account_doc_num' => $this->filled('bank_account_doc_num') ? trim((string) $this->input('bank_account_doc_num')) : null,
            'external_bank_name' => $this->filled('external_bank_name') ? trim((string) $this->input('external_bank_name')) : null,
            'external_bank_branch' => $this->filled('external_bank_branch') ? trim((string) $this->input('external_bank_branch')) : null,
            'party_type' => $this->filled('party_type') ? trim((string) $this->input('party_type')) : null,
            'party_doc_num' => $this->filled('party_doc_num') ? trim((string) $this->input('party_doc_num')) : null,
            'party_name' => $this->filled('party_name') ? trim((string) $this->input('party_name')) : null,
            'currency_doc_num' => $this->filled('currency_doc_num') ? trim((string) $this->input('currency_doc_num')) : null,
            'exchange_rate' => $this->filled('exchange_rate') ? str_replace(',', '', (string) $this->input('exchange_rate')) : 1,
            'amount' => $this->filled('amount') ? str_replace(',', '', (string) $this->input('amount')) : null,
            'reason' => $this->filled('reason') ? trim((string) $this->input('reason')) : null,
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueActiveDocumentNumberRule()],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'cheque_type' => ['required', Rule::in(Cheque::types())],
            'cheque_number' => ['required', 'string', 'max:100'],
            'cheque_date' => ['nullable', $this->dateRule('cheque_date_invalid')],
            'due_date' => ['nullable', $this->dateRule('due_date_invalid')],
            'bank_account_doc_num' => [
                'nullable',
                'string',
                Rule::exists('bank_accounts', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('status', 'active')
                        ->whereNull('deleted_at')),
            ],
            'external_bank_name' => ['nullable', 'string', 'max:255'],
            'external_bank_branch' => ['nullable', 'string', 'max:255'],
            'party_type' => ['nullable', Rule::in(['customer', 'supplier', 'other'])],
            'party_doc_num' => ['nullable', 'string', 'max:100'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'currency_doc_num' => [
                'required',
                'string',
                Rule::exists('currencies', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('status', 'active')
                        ->whereNull('deleted_at')),
            ],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'lines' => ['nullable', 'array'],
            'lines.*.account_doc_num' => [
                'required_with:lines.*.amount',
                'nullable',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->whereNull('deleted_at')),
            ],
            'lines.*.amount' => ['required_with:lines.*.account_doc_num', 'nullable', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', Rule::in(['save', 'save_new'])],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'exchange_rate.gt' => $this->message('exchange_rate_positive'),
            'amount.gt' => $this->message('amount_positive'),
            'lines.*.amount.gt' => $this->message('line_amount_positive'),
            'party_type.in' => $this->message('party_type_invalid'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bankAccount = BankAccount::query()
                ->with('currency')
                ->where('company_id', $this->input('company_id'))
                ->where('doc_num', $this->input('bank_account_doc_num'))
                ->first();
            $currency = Currency::query()
                ->where('company_id', $this->input('company_id'))
                ->where('doc_num', $this->input('currency_doc_num'))
                ->first();

            $this->validateBusiness($validator, $bankAccount, $currency, $this->currentRecord());
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => $this->attribute('doc_number'),
            'cheque_type' => $this->attribute('cheque_type'),
            'cheque_number' => $this->attribute('cheque_number'),
            'cheque_date' => $this->attribute('cheque_date'),
            'due_date' => $this->attribute('due_date'),
            'bank_account_doc_num' => $this->attribute('bank_account'),
            'external_bank_name' => $this->attribute('external_bank_name'),
            'external_bank_branch' => $this->attribute('external_bank_branch'),
            'party_type' => $this->attribute('party_type'),
            'party_doc_num' => $this->attribute('party'),
            'party_name' => $this->attribute('party_name'),
            'currency_doc_num' => $this->attribute('currency'),
            'exchange_rate' => $this->attribute('exchange_rate'),
            'amount' => $this->attribute('amount'),
            'reason' => $this->attribute('reason'),
            'description' => $this->attribute('description'),
            'lines' => $this->attribute('lines'),
            'lines.*.account_doc_num' => $this->attribute('account'),
            'lines.*.amount' => $this->attribute('line_amount'),
            'lines.*.description' => $this->attribute('line_description'),
            'lines.*.notes' => $this->attribute('line_notes'),
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        foreach (['cheque_date', 'due_date'] as $field) {
            if (array_key_exists($field, $data) && $data[$field]) {
                $data[$field] = app(DateFormatService::class)->normalizeForStorage((string) $data[$field]);
            }
        }

        [$partyId, $partyName] = $this->resolvedPartyForStorage();
        $data['party_id'] = $partyId;
        $data['party_name'] = $partyName;

        return $data;
    }

    protected function currentRecord(): ?Cheque
    {
        return null;
    }

    protected function message(string $key): string
    {
        return __("cheques.messages.{$key}");
    }

    protected function attribute(string $key): string
    {
        return __("cheques.attributes.{$key}");
    }

    protected function validateBusiness(Validator $validator, ?BankAccount $bankAccount, ?Currency $currency, ?Cheque $current = null): void
    {
        if ($current instanceof Cheque) {
            if ($current->isLockedForEditing()) {
                $validator->errors()->add('document', $this->message('document_locked'));
            }

            if ($current->cheque_type !== $this->input('cheque_type')) {
                $validator->errors()->add('cheque_type', $this->message('cheque_type_locked'));
            }
        }

        if ($bankAccount instanceof BankAccount && ($bankAccount->status !== 'active' || $bankAccount->trashed())) {
            $validator->errors()->add('bank_account_doc_num', $this->message('bank_account_inactive'));
        }

        if ($currency instanceof Currency) {
            $this->validateCurrency($validator, $bankAccount, $currency);
        }

        $this->validateParty($validator);
        $this->validateLines($validator);
    }

    private function validateCurrency(Validator $validator, ?BankAccount $bankAccount, Currency $currency): void
    {
        if ($currency->status !== 'active' || $currency->trashed()) {
            $validator->errors()->add('currency_doc_num', $this->message('currency_inactive'));
        }

        if ($bankAccount instanceof BankAccount && (int) $bankAccount->currency_id !== (int) $currency->getKey()) {
            $validator->errors()->add('currency_doc_num', $this->message('currency_not_allowed_for_bank_account'));
        }

        $rate = $this->input('exchange_rate');

        if ($currency->is_main && is_numeric($rate) && abs(((float) $rate) - 1.0) > 0.000001) {
            $validator->errors()->add('exchange_rate', $this->message('exchange_rate_main_currency'));
        }
    }

    private function validateLines(Validator $validator): void
    {
        $amounts = app(FinanceAmountService::class);
        $amountUnits = $amounts->toUnits($this->input('amount'));
        $distributedUnits = 0;

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line) || empty($line['account_doc_num'])) {
                continue;
            }

            $account = Account::query()
                ->where('company_id', $this->input('company_id'))
                ->where('doc_num', $line['account_doc_num'])
                ->first();

            if ($account instanceof Account && ($account->status !== 'active' || $account->trashed() || $account->is_group || ! $account->is_postable)) {
                $validator->errors()->add("lines.{$index}.account_doc_num", $this->message('account_not_postable'));
            }

            $distributedUnits += $amounts->toUnits($line['amount'] ?? 0);
        }

        if ($distributedUnits > $amountUnits && $amountUnits > 0) {
            $validator->errors()->add('lines', $this->message('distribution_exceeds_amount'));
        }
    }

    private function validateParty(Validator $validator): void
    {
        $partyType = $this->input('party_type');
        $partyDocNum = $this->input('party_doc_num');
        $partyName = $this->input('party_name');

        if ($partyDocNum && ! in_array($partyType, ['customer', 'supplier'], true)) {
            $validator->errors()->add('party_type', $this->message('party_type_required_for_selector'));

            return;
        }

        if ($partyType === 'other' && ! $partyName) {
            $validator->errors()->add('party_name', __('validation.required', ['attribute' => $this->attribute('party_name')]));

            return;
        }

        if (! $partyType && ! $partyName) {
            $validator->errors()->add('party_name', __('validation.required', ['attribute' => $this->attribute('party_name')]));

            return;
        }

        if (! in_array($partyType, ['customer', 'supplier'], true)) {
            return;
        }

        if (! $partyDocNum && ! $partyName) {
            $validator->errors()->add('party_doc_num', $this->message('party_required'));

            return;
        }

        if ($partyDocNum) {
            $party = $this->partyModel($partyType, $partyDocNum);

            if (! ($party instanceof Customer) && ! ($party instanceof Supplier)) {
                $validator->errors()->add('party_doc_num', $this->message('party_not_found'));
            }
        }
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function resolvedPartyForStorage(): array
    {
        $partyType = $this->input('party_type');
        $partyDocNum = $this->input('party_doc_num');

        if (in_array($partyType, ['customer', 'supplier'], true) && $partyDocNum) {
            $party = $this->partyModel($partyType, $partyDocNum);

            if ($party instanceof Customer || $party instanceof Supplier) {
                return [(int) $party->getKey(), (string) $party->name];
            }
        }

        return [null, $this->input('party_name') ?: null];
    }

    private function partyModel(?string $partyType, ?string $partyDocNum): Customer|Supplier|null
    {
        if (! $partyDocNum || ! in_array($partyType, ['customer', 'supplier'], true)) {
            return null;
        }

        $model = $partyType === 'customer' ? Customer::class : Supplier::class;

        return $model::query()
            ->active()
            ->forCompany((int) $this->input('company_id'))
            ->where('doc_num', $partyDocNum)
            ->first();
    }

    private function uniqueActiveDocumentNumberRule(): mixed
    {
        $rule = Rule::unique('cheques', 'doc_number')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->where('cheque_type', $this->input('cheque_type'))
                ->whereNull('deleted_at'));

        $current = $this->currentRecord();

        return $current instanceof Cheque ? $rule->ignore($current->getKey()) : $rule;
    }

    private function dateRule(string $messageKey): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($messageKey): void {
            if ($value !== null && ! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                $fail($this->message($messageKey));
            }
        };
    }
}
