<?php

namespace Modules\Finance\Http\Requests\CashVouchers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;

class StoreCashVoucherRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        $action = $this->filled('clone_source_token') ? 'clone' : 'create';

        return (bool) $this->user()?->can($this->permissionPrefix().'.'.$action);
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'exchange_rate',
            'amount',
            'lines.*.amount',
        ]);

        $context = app(OperatingContextService::class)->snapshot($this);
        $lines = collect($this->input('lines', []))
            ->filter(fn ($line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'account_doc_num' => isset($line['account_doc_num']) ? trim((string) $line['account_doc_num']) : null,
                'amount' => isset($line['amount']) ? trim((string) $line['amount']) : null,
                'description' => isset($line['description']) ? trim((string) $line['description']) : null,
                'notes' => isset($line['notes']) ? trim((string) $line['notes']) : null,
            ])
            ->values()
            ->all();

        $this->merge([
            'company_id' => $context['company_id'],
            'cashbox_doc_num' => $this->filled('cashbox_doc_num') ? trim((string) $this->input('cashbox_doc_num')) : null,
            'currency_doc_num' => $this->filled('currency_doc_num') ? trim((string) $this->input('currency_doc_num')) : null,
            'voucher_date' => $this->filled('voucher_date') ? trim((string) $this->input('voucher_date')) : null,
            'exchange_rate' => $this->filled('exchange_rate') ? trim((string) $this->input('exchange_rate')) : 1,
            'amount' => $this->filled('amount') ? trim((string) $this->input('amount')) : null,
            'person_name' => $this->filled('person_name') ? trim((string) $this->input('person_name')) : null,
            'person_national_id' => $this->filled('person_national_id') ? trim((string) $this->input('person_national_id')) : null,
            'person_phone' => $this->filled('person_phone') ? trim((string) $this->input('person_phone')) : null,
            'reason' => $this->filled('reason') ? trim((string) $this->input('reason')) : null,
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        return [
            'doc_number' => [
                'nullable',
                'integer',
                'min:1',
                $this->uniqueActiveDocumentNumberRule(),
            ],
            'voucher_date' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail($this->message('voucher_date_invalid'));
                }
            }],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'cashbox_doc_num' => [
                'required',
                'string',
                Rule::exists('cashboxes', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('status', 'active')
                        ->whereNull('deleted_at')),
            ],
            'currency_doc_num' => [
                'required',
                'string',
                Rule::exists('currencies', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('status', 'active')
                        ->whereNull('deleted_at')),
            ],
            'exchange_rate' => ['required', 'numeric', 'decimal:0,6', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/D', 'gt:0'],
            'amount' => ['required', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'gt:0'],
            'person_name' => ['required', 'string', 'max:255'],
            'person_national_id' => ['nullable', 'string', 'max:50'],
            'person_phone' => ['nullable', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_doc_num' => [
                'required',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->whereNull('deleted_at')),
            ],
            'lines.*.amount' => ['required', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'gt:0'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'exchange_rate.gt' => $this->message('exchange_rate_positive'),
            'amount.gt' => $this->message('amount_positive'),
            'person_name.required' => $this->message('person_name_required'),
            'lines.*.amount.gt' => $this->message('line_amount_positive'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cashbox = Cashbox::query()
                ->with('account')
                ->where('company_id', $this->input('company_id'))
                ->where('doc_num', $this->input('cashbox_doc_num'))
                ->first();
            $currency = Currency::query()
                ->where('company_id', $this->input('company_id'))
                ->where('doc_num', $this->input('currency_doc_num'))
                ->first();

            $this->validateBusiness($validator, $cashbox, $currency, $this->currentRecord());
        });
    }

    protected function validateBusiness(Validator $validator, ?Cashbox $cashbox, ?Currency $currency, ?CashVoucher $current = null): void
    {
        if ($current instanceof CashVoucher && ! $current->isDraft()) {
            $validator->errors()->add('document', $this->message('document_locked'));
        }

        if ($cashbox instanceof Cashbox) {
            $this->validateCashbox($validator, $cashbox);
        }

        if ($currency instanceof Currency) {
            $this->validateCurrency($validator, $cashbox, $currency);
        }

        $this->validateLines($validator, $cashbox);
    }

    public function attributes(): array
    {
        return [
            'doc_number' => $this->attribute('doc_number'),
            'voucher_date' => $this->attribute('voucher_date'),
            'cashbox_doc_num' => $this->attribute('cashbox'),
            'currency_doc_num' => $this->attribute('currency'),
            'exchange_rate' => $this->attribute('exchange_rate'),
            'amount' => $this->attribute('amount'),
            'person_name' => $this->attribute('person_name'),
            'person_national_id' => $this->attribute('person_national_id'),
            'person_phone' => $this->attribute('person_phone'),
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

        if (array_key_exists('voucher_date', $data)) {
            $data['voucher_date'] = app(DateFormatService::class)->normalizeForStorage((string) $data['voucher_date']);
        }

        return $data;
    }

    protected function currentRecord(): ?CashVoucher
    {
        return null;
    }

    protected function voucherType(): string
    {
        $routeName = (string) $this->route()?->getName();

        return str_contains($routeName, 'cash-payment-vouchers')
            ? CashVoucher::TypePayment
            : CashVoucher::TypeReceipt;
    }

    protected function permissionPrefix(): string
    {
        return CashVoucher::permissionPrefixForType($this->voucherType());
    }

    protected function translationKey(): string
    {
        return CashVoucher::translationKeyForType($this->voucherType());
    }

    protected function message(string $key): string
    {
        return __($this->translationKey().".messages.{$key}");
    }

    protected function attribute(string $key): string
    {
        return __($this->translationKey().".attributes.{$key}");
    }

    private function validateCashbox(Validator $validator, Cashbox $cashbox): void
    {
        if ($cashbox->status !== 'active' || $cashbox->trashed()) {
            $validator->errors()->add('cashbox_doc_num', $this->message('cashbox_inactive'));
        }

        $account = $cashbox->account;

        if (! $account instanceof Account || $account->trashed() || $account->status !== 'active' || $account->is_group || ! $account->is_postable) {
            $validator->errors()->add('cashbox_doc_num', $this->message('cashbox_account_required'));
        }
    }

    private function validateCurrency(Validator $validator, ?Cashbox $cashbox, Currency $currency): void
    {
        if ($currency->status !== 'active' || $currency->trashed()) {
            $validator->errors()->add('currency_doc_num', $this->message('currency_inactive'));
        }

        if ($cashbox instanceof Cashbox && ! $this->currencyAllowedForCashbox($cashbox, $currency)) {
            $validator->errors()->add('currency_doc_num', $this->message('currency_not_allowed'));
        }

        $rate = $this->input('exchange_rate');

        if ($currency->is_main && is_numeric($rate) && abs(((float) $rate) - 1.0) > 0.000001) {
            $validator->errors()->add('exchange_rate', $this->message('exchange_rate_main_currency'));
        }
    }

    private function validateLines(Validator $validator, ?Cashbox $cashbox): void
    {
        $amountUnits = $this->toUnits($this->input('amount'));
        $distributedUnits = 0;
        $cashboxAccountId = $cashbox instanceof Cashbox ? (int) $cashbox->account_id : null;

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $account = Account::query()
                ->where('company_id', $this->input('company_id'))
                ->where('doc_num', $line['account_doc_num'] ?? null)
                ->first();
            $lineUnits = $this->toUnits($line['amount'] ?? 0);

            if ($account instanceof Account && ($account->status !== 'active' || $account->trashed() || $account->is_group || ! $account->is_postable)) {
                $validator->errors()->add("lines.{$index}.account_doc_num", $this->message('account_not_postable'));
            }

            if ($account instanceof Account && $cashboxAccountId !== null && (int) $account->getKey() === $cashboxAccountId) {
                $validator->errors()->add("lines.{$index}.account_doc_num", $this->message('cashbox_account_line_forbidden'));
            }

            $distributedUnits += $lineUnits;
        }

        if ($distributedUnits > $amountUnits && $amountUnits > 0) {
            $validator->errors()->add('lines', $this->message('distribution_exceeds_amount'));
        }
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
        $rule = Rule::unique('cash_vouchers', 'doc_number')
            ->where(fn ($query) => $query
                ->where('company_id', $this->input('company_id'))
                ->where('voucher_type', $this->voucherType())
                ->whereNull('deleted_at'));

        $current = $this->currentRecord();

        return $current instanceof CashVoucher ? $rule->ignore($current->getKey()) : $rule;
    }

    private function toUnits(mixed $value): int
    {
        $value = trim((string) $value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = substr(str_pad(preg_replace('/\D/', '', $fraction) ?: '', 4, '0'), 0, 4);
        $units = ((int) $whole * 10000) + (int) $fraction;

        return $negative ? -$units : $units;
    }
}
