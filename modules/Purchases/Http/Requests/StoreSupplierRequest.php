<?php

namespace Modules\Purchases\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\Supplier;

class StoreSupplierRequest extends FormRequest
{
    use NormalizesNumericInput;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'suppliers.clone' : 'suppliers.create');
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput([
            'credit_limits.*.credit_limit',
        ]);

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'account_group_doc_num' => $this->filled('account_group_doc_num') ? trim((string) $this->input('account_group_doc_num')) : null,
            'phone' => $this->nullableTrim('phone'),
            'mobile' => $this->nullableTrim('mobile'),
            'email' => $this->nullableTrim('email'),
            'tax_number' => $this->nullableTrim('tax_number'),
            'commercial_register' => $this->nullableTrim('commercial_register'),
            'national_id' => $this->nullableTrim('national_id'),
            'contact_person' => $this->nullableTrim('contact_person'),
            'payment_terms_days' => $this->filled('payment_terms_days') ? (int) $this->input('payment_terms_days') : null,
            'address' => $this->nullableTrim('address'),
            'country_doc_num' => $this->nullableTrim('country_doc_num'),
            'governorate_doc_num' => $this->nullableTrim('governorate_doc_num'),
            'city_doc_num' => $this->nullableTrim('city_doc_num'),
            'area_doc_num' => $this->nullableTrim('area_doc_num'),
            'credit_limits' => $this->normalizedCreditLimits(),
            'notes' => $this->nullableTrim('notes'),
        ]);
    }

    public function rules(): array
    {
        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');

        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueActiveSupplierRule('doc_number')],
            'name' => ['required', 'string', 'max:255'],
            'account_group_doc_num' => [
                'nullable',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'commercial_register' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'address' => ['nullable', 'string'],
            'country_doc_num' => ['nullable', 'string', Rule::exists('hr_countries', 'doc_num')->whereNull('deleted_at')],
            'governorate_doc_num' => ['nullable', 'string', Rule::exists('hr_governorates', 'doc_num')->whereNull('deleted_at')],
            'city_doc_num' => ['nullable', 'string', Rule::exists('hr_cities', 'doc_num')->whereNull('deleted_at')],
            'area_doc_num' => ['nullable', 'string', Rule::exists('hr_areas', 'doc_num')->whereNull('deleted_at')],
            'credit_limits' => ['nullable', 'array'],
            'credit_limits.*.currency_doc_num' => ['nullable', 'string'],
            'credit_limits.*.credit_limit' => ['nullable', 'numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D', 'min:0'],
            'credit_limits.*.notes' => ['nullable', 'string'],
            'credit_limits.*._delete' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $accounts = app(BusinessPartnerAccountService::class);
            $parentAccount = $this->resolvedParentAccount();

            if ($this->filled('account_group_doc_num') && ! $parentAccount) {
                $validator->errors()->add('account_group_doc_num', __('suppliers.messages.supplier_group_unavailable'));
            }

            if ($this->filled('account_group_doc_num') && $parentAccount && (! $parentAccount->is_group || $parentAccount->is_postable)) {
                $validator->errors()->add('account_group_doc_num', __('suppliers.messages.supplier_group_must_be_group'));
            }

            if ($this->filled('account_group_doc_num') && $parentAccount && $parentAccount->is_group && ! $accounts->isSelectableGroup(BusinessPartnerAccountService::Supplier, $parentAccount)) {
                $validator->errors()->add('account_group_doc_num', __('suppliers.messages.supplier_group_unavailable'));
            }

            if ($parentAccount && $this->hasDuplicateLinkedAccount($parentAccount)) {
                $validator->errors()->add('account_group_doc_num', __('suppliers.messages.linked_account_duplicate'));
            }

            $this->validateCreditLimits($validator);
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('suppliers.attributes.doc_number'),
            'name' => __('suppliers.attributes.name'),
            'account_group_doc_num' => __('suppliers.attributes.account_group'),
            'status' => __('suppliers.attributes.status'),
            'phone' => __('suppliers.attributes.phone'),
            'mobile' => __('suppliers.attributes.mobile'),
            'email' => __('suppliers.attributes.email'),
            'tax_number' => __('suppliers.attributes.tax_number'),
            'commercial_register' => __('suppliers.attributes.commercial_register'),
            'national_id' => __('suppliers.attributes.national_id'),
            'contact_person' => __('suppliers.attributes.contact_person'),
            'payment_terms_days' => __('Payment terms (days)'),
            'address' => __('suppliers.attributes.address'),
            'country_doc_num' => __('suppliers.attributes.country'),
            'governorate_doc_num' => __('suppliers.attributes.governorate'),
            'city_doc_num' => __('suppliers.attributes.city'),
            'area_doc_num' => __('suppliers.attributes.area'),
            'credit_limits' => __('suppliers.attributes.credit_limits'),
            'credit_limits.*.currency_doc_num' => __('suppliers.attributes.currency'),
            'credit_limits.*.credit_limit' => __('suppliers.attributes.credit_limit'),
            'credit_limits.*.notes' => __('suppliers.attributes.notes'),
            'notes' => __('suppliers.attributes.notes'),
        ];
    }

    public function messages(): array
    {
        return [
            'doc_number.unique' => __('suppliers.messages.doc_number_unique'),
        ];
    }

    protected function currentSupplier(): ?Supplier
    {
        return null;
    }

    protected function hasDuplicateLinkedAccount(Account $parentAccount): bool
    {
        $current = $this->currentSupplier();
        $currentAccountId = $current?->account_id;
        $name = app(BusinessPartnerAccountService::class)->linkedAccountName(['name' => (string) $this->input('name')]);

        return Account::query()
            ->whereNull('deleted_at')
            ->where('company_id', $parentAccount->company_id)
            ->where('parent_id', $parentAccount->getKey())
            ->where('name', $name)
            ->when($currentAccountId, fn ($query) => $query->whereKeyNot($currentAccountId))
            ->exists();
    }

    private function resolvedParentAccount(): ?Account
    {
        if (! $this->filled('account_group_doc_num')) {
            try {
                return app(BusinessPartnerAccountService::class)->rootAccount(BusinessPartnerAccountService::Supplier);
            } catch (\DomainException) {
                return null;
            }
        }

        return Account::query()
            ->with('classification')
            ->where('company_id', Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id'))
            ->where('doc_num', $this->input('account_group_doc_num'))
            ->first();
    }

    private function uniqueActiveSupplierRule(string $column): Unique
    {
        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');
        $rule = Rule::unique('suppliers', $column)
            ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'));
        $current = $this->currentSupplier();

        return $current ? $rule->ignore($current->getKey()) : $rule;
    }

    private function nullableTrim(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<array{currency_doc_num: string|null, credit_limit: string|null, notes: string|null, _delete: bool}>
     */
    private function normalizedCreditLimits(): array
    {
        return collect($this->input('credit_limits', []))
            ->filter(fn ($row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'currency_doc_num' => isset($row['currency_doc_num']) ? trim((string) $row['currency_doc_num']) ?: null : null,
                'credit_limit' => isset($row['credit_limit']) ? trim((string) $row['credit_limit']) ?: null : null,
                'notes' => isset($row['notes']) ? trim((string) $row['notes']) ?: null : null,
                '_delete' => filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();
    }

    private function validateCreditLimits(Validator $validator): void
    {
        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');
        $seen = [];

        foreach ($this->input('credit_limits', []) as $index => $row) {
            if (! is_array($row) || ($row['_delete'] ?? false)) {
                continue;
            }

            $currencyDocNum = trim((string) ($row['currency_doc_num'] ?? ''));
            $amount = $row['credit_limit'] ?? null;
            $hasAmount = $amount !== null && trim((string) $amount) !== '';

            if ($currencyDocNum === '' && ! $hasAmount) {
                continue;
            }

            if ($currencyDocNum === '') {
                $validator->errors()->add("credit_limits.{$index}.currency_doc_num", __('validation.required', ['attribute' => __('suppliers.attributes.currency')]));

                continue;
            }

            if (! $hasAmount) {
                $validator->errors()->add("credit_limits.{$index}.credit_limit", __('business_partners.messages.credit_limit_required'));
            }

            $currency = Currency::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $currencyDocNum)
                ->whereNull('deleted_at')
                ->first();

            if (! $currency instanceof Currency) {
                $validator->errors()->add("credit_limits.{$index}.currency_doc_num", __('validation.exists', ['attribute' => __('suppliers.attributes.currency')]));

                continue;
            }

            if (isset($seen[$currency->getKey()])) {
                $validator->errors()->add("credit_limits.{$index}.currency_doc_num", __('business_partners.messages.credit_limit_currency_duplicate'));
            }

            $seen[$currency->getKey()] = true;
        }
    }
}
