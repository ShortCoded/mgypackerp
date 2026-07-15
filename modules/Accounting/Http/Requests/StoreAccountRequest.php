<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'accounts.clone' : 'accounts.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'account_code' => trim((string) $this->input('account_code')),
            'name' => trim((string) $this->input('name')),
            'name_en' => trim((string) $this->input('name_en')),
            'parent_doc_num' => $this->filled('parent_doc_num') ? trim((string) $this->input('parent_doc_num')) : null,
            'classification_code' => $this->filled('classification_code') ? trim((string) $this->input('classification_code')) : null,
            'account_type' => $this->filled('account_type') ? trim((string) $this->input('account_type')) : null,
            'statement_type' => $this->filled('statement_type') ? trim((string) $this->input('statement_type')) : null,
            'normal_balance' => $this->filled('normal_balance') ? trim((string) $this->input('normal_balance')) : null,
            'is_group' => $this->boolean('is_group'),
            'is_postable' => $this->boolean('is_postable'),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->companyId();

        return [
            'doc_number' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('accounts', 'doc_number')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'account_code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'parent_doc_num' => [
                'nullable',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'classification_code' => ['nullable', 'string', 'exists:account_classifications,code'],
            'account_type' => ['nullable', Rule::in(Account::accountTypes())],
            'statement_type' => ['nullable', Rule::in(Account::statementTypes())],
            'normal_balance' => ['nullable', Rule::in(Account::normalBalances())],
            'is_group' => ['boolean'],
            'is_postable' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $companyId = $this->companyId();

            if (! $this->filled('parent_doc_num') && ! $this->filled('account_code')) {
                $validator->errors()->add('account_code', __('validation.required', ['attribute' => __('accounts.attributes.account_code')]));
            }

            if ($this->filled('account_code') && Account::query()->forCompany($companyId)->where('account_code', $this->input('account_code'))->exists()) {
                $validator->errors()->add('account_code', __('accounts.messages.account_code_used'));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('accounts.attributes.doc_number'),
            'account_code' => __('accounts.attributes.account_code'),
            'name' => __('accounts.attributes.name'),
            'name_en' => __('accounts.attributes.name_en'),
            'parent_doc_num' => __('accounts.attributes.parent'),
            'classification_code' => __('accounts.attributes.classification'),
            'account_type' => __('accounts.attributes.account_type'),
            'statement_type' => __('accounts.attributes.statement_type'),
            'normal_balance' => __('accounts.attributes.normal_balance'),
            'status' => __('accounts.attributes.status'),
        ];
    }

    protected function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }
}
