<?php

namespace Modules\Finance\Http\Requests\OpeningBalances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\OpeningBalance;

class StoreOpeningBalanceRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'opening_balances.clone' : 'opening_balances.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'exchange_rate',
            'lines.*.amount',
        ]);

        $context = app(OperatingContextService::class)->snapshot($this);
        $lines = collect($this->input('lines', []))
            ->filter(fn ($line): bool => is_array($line))
            ->map(fn (array $line): array => [
                'account_doc_num' => isset($line['account_doc_num']) ? trim((string) $line['account_doc_num']) : null,
                'transaction_type' => isset($line['transaction_type']) ? trim((string) $line['transaction_type']) : null,
                'amount' => isset($line['amount']) ? trim((string) $line['amount']) : null,
                'description' => isset($line['description']) ? trim((string) $line['description']) : null,
            ])
            ->values()
            ->all();

        $this->merge([
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'currency_doc_num' => $this->filled('currency_doc_num') ? trim((string) $this->input('currency_doc_num')) : null,
            'document_date' => $this->filled('document_date') ? trim((string) $this->input('document_date')) : null,
            'exchange_rate' => $this->filled('exchange_rate') ? trim((string) $this->input('exchange_rate')) : 1,
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
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
                Rule::unique('opening_balances', 'doc_number')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->where('financial_period_id', $this->input('financial_period_id'))
                        ->whereNull('deleted_at')),
            ],
            'document_date' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('opening_balances.messages.document_date_invalid'));
                }
            }],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'financial_period_id' => ['required', 'integer', 'exists:financial_periods,id'],
            'currency_doc_num' => [
                'required',
                'string',
                Rule::exists('currencies', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->whereNull('deleted_at')),
            ],
            'exchange_rate' => ['required', 'numeric', 'decimal:0,6', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/D', 'gt:0'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_doc_num' => [
                'required',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $this->input('company_id'))
                        ->whereNull('deleted_at')),
            ],
            'lines.*.transaction_type' => ['required', Rule::in(['debit', 'credit'])],
            'lines.*.amount' => ['required', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'gt:0'],
            'lines.*.description' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'exchange_rate.required' => __('opening_balances.messages.exchange_rate_required'),
            'exchange_rate.numeric' => __('opening_balances.messages.exchange_rate_numeric'),
            'exchange_rate.gt' => __('opening_balances.messages.exchange_rate_positive'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $period = FinancialPeriod::query()->find($this->input('financial_period_id'));
            $currency = Currency::query()
                ->where('company_id', $this->input('company_id'))
                ->where('doc_num', $this->input('currency_doc_num'))
                ->first();

            $this->validateBusiness($validator, $period, $currency);
        });
    }

    protected function validateBusiness(Validator $validator, ?FinancialPeriod $period, ?Currency $currency, ?OpeningBalance $current = null): void
    {
        if ($current?->isApproved()) {
            $validator->errors()->add('document', __('opening_balances.messages.approved_edit_forbidden'));
        } elseif ($current?->isClosed()) {
            $validator->errors()->add('document', __('opening_balances.messages.closed_edit_forbidden'));
        } elseif ($current?->isLockedForEditing()) {
            $validator->errors()->add('document', __('opening_balances.messages.document_locked'));
        }

        if ($period && (int) $period->company_id !== (int) $this->input('company_id')) {
            $validator->errors()->add('financial_period_id', __('operating_context.validation.financial_period_invalid'));
        }

        if ($period?->is_closed) {
            $validator->errors()->add('financial_period_id', __('opening_balances.messages.period_closed'));
        }

        if ($period && $period->allows_opening_entries === false) {
            $validator->errors()->add('financial_period_id', __('opening_balances.messages.period_disallows_opening_entries'));
        }

        $this->validateDocumentDateInsidePeriod($validator, $period);
        $this->validateExchangeRate($validator, $currency);

        if ($currency && $currency->status !== 'active') {
            $validator->errors()->add('currency_doc_num', __('opening_balances.messages.currency_inactive'));
        }

        $this->validateLines($validator);
    }

    private function validateDocumentDateInsidePeriod(Validator $validator, ?FinancialPeriod $period): void
    {
        $documentDate = $this->normalizedDocumentDate();

        if (! $period instanceof FinancialPeriod || $documentDate === null) {
            return;
        }

        $fromDate = $period->from_date?->toDateString();
        $toDate = $period->to_date?->toDateString();

        if ($fromDate !== null && $toDate !== null && ($documentDate < $fromDate || $documentDate > $toDate)) {
            $validator->errors()->add('document_date', __('opening_balances.messages.document_date_outside_period'));
        }
    }

    private function normalizedDocumentDate(): ?string
    {
        $value = $this->input('document_date');

        if (! is_string($value) || ! app(DateFormatService::class)->isValidDate($value)) {
            return null;
        }

        return app(DateFormatService::class)->normalizeForStorage($value);
    }

    private function validateExchangeRate(Validator $validator, ?Currency $currency): void
    {
        $rate = $this->input('exchange_rate');

        if (! $currency instanceof Currency || ! $currency->is_main || ! is_numeric($rate)) {
            return;
        }

        if (abs(((float) $rate) - 1.0) > 0.000001) {
            $validator->errors()->add('exchange_rate', __('opening_balances.messages.exchange_rate_main_currency'));
        }
    }

    private function validateLines(Validator $validator): void
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $seen = [];

        foreach ($this->input('lines', []) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $account = Account::query()
                ->where('company_id', $this->input('company_id'))
                ->where('doc_num', $line['account_doc_num'] ?? null)
                ->first();
            $amount = (float) ($line['amount'] ?? 0);
            $type = (string) ($line['transaction_type'] ?? '');

            if ($account && (! $account->is_postable || $account->is_group || $account->status !== 'active')) {
                $validator->errors()->add("lines.{$index}.account_doc_num", __('opening_balances.messages.account_not_postable'));
            }

            if ($type === 'debit') {
                $totalDebit += $amount;
            } elseif ($type === 'credit') {
                $totalCredit += $amount;
            }

            $key = implode(':', [
                $account?->getKey() ?? 'missing',
                $line['customer_id'] ?? 'null',
                $line['supplier_id'] ?? 'null',
                $line['employee_id'] ?? 'null',
                $line['bank_account_id'] ?? 'null',
                $line['cost_center_id'] ?? 'null',
                $line['branch_id'] ?? 'null',
            ]);

            if (isset($seen[$key])) {
                $validator->errors()->add("lines.{$index}.account_doc_num", __('opening_balances.messages.duplicate_line'));
            }

            $seen[$key] = true;
        }

        if (round($totalDebit, 4) !== round($totalCredit, 4)) {
            $validator->errors()->add('lines', __('opening_balances.messages.unbalanced'));
        }
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('opening_balances.attributes.doc_number'),
            'document_date' => __('opening_balances.attributes.document_date'),
            'currency_doc_num' => __('opening_balances.attributes.currency'),
            'exchange_rate' => __('opening_balances.attributes.exchange_rate'),
            'description' => __('opening_balances.attributes.description'),
            'notes' => __('opening_balances.attributes.notes'),
            'lines' => __('opening_balances.attributes.lines'),
            'lines.*.account_doc_num' => __('opening_balances.attributes.account'),
            'lines.*.transaction_type' => __('opening_balances.attributes.transaction_type'),
            'lines.*.amount' => __('opening_balances.attributes.amount'),
            'lines.*.description' => __('opening_balances.attributes.line_description'),
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        if (array_key_exists('document_date', $data)) {
            $data['document_date'] = app(DateFormatService::class)->normalizeForStorage((string) $data['document_date']);
        }

        return $data;
    }
}
