<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\OperatingCompanyContextService;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('accounts.edit');
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
        $account = $this->currentAccount();

        return [
            'doc_number' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('accounts', 'doc_number')
                    ->ignore($account?->getKey())
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'account_code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'parent_doc_num' => [
                'nullable',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(function ($query) use ($companyId): void {
                        $query->where('company_id', $companyId);
                        Account::applyNewSelectionEligibility($query);
                    }),
            ],
            'classification_code' => [
                'nullable',
                'string',
                Rule::exists('account_classifications', 'code')
                    ->where(function ($query) use ($account): void {
                        $query->whereNull('deleted_at')
                            ->where(function ($classificationQuery) use ($account): void {
                                $classificationQuery->where('status', 'active');

                                if ($account?->classification?->code) {
                                    $classificationQuery->orWhere('code', $account->classification->code);
                                }
                            });
                    }),
            ],
            'account_type' => ['nullable', Rule::in(Account::accountTypes())],
            'statement_type' => ['nullable', Rule::in(Account::statementTypes())],
            'normal_balance' => ['nullable', Rule::in(Account::normalBalances())],
            'is_group' => ['boolean'],
            'is_postable' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $companyId = $this->companyId();
            $account = $this->currentAccount();

            if (! $this->filled('parent_doc_num') && ! $this->filled('account_code')) {
                $validator->errors()->add('account_code', __('validation.required', ['attribute' => __('accounts.attributes.account_code')]));
            }

            if ($this->filled('account_code') && Account::query()->forCompany($companyId)->where('account_code', $this->input('account_code'))->whereKeyNot($account?->getKey())->exists()) {
                $validator->errors()->add('account_code', __('accounts.messages.account_code_used'));
            }

            if ($account instanceof Account && $this->input('parent_doc_num') === $account->doc_num) {
                $validator->errors()->add('parent_doc_num', __('validation.different', ['attribute' => __('accounts.attributes.parent'), 'other' => __('accounts.attributes.account_code')]));
            }

            $parent = $this->filled('parent_doc_num')
                ? Account::query()->forCompany($companyId)->where('doc_num', $this->input('parent_doc_num'))->first()
                : null;

            if ($account instanceof Account && $parent instanceof Account && $this->wouldCreateCycle($account, $parent)) {
                $validator->errors()->add('parent_doc_num', __('accounts.messages.parent_cycle'));
            }
        });
    }

    public function attributes(): array
    {
        return (new StoreAccountRequest)->attributes();
    }

    protected function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function currentAccount(): ?Account
    {
        $docNum = $this->route('account');

        if (! is_string($docNum) || trim($docNum) === '') {
            return null;
        }

        return Account::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->first();
    }

    private function wouldCreateCycle(Account $account, Account $parent): bool
    {
        $parentId = $parent->getKey();

        while ($parentId !== null) {
            if ((int) $parentId === (int) $account->getKey()) {
                return true;
            }

            $parentId = Account::query()
                ->forCompany((int) $account->company_id)
                ->whereKey($parentId)
                ->value('parent_id');
        }

        return false;
    }
}
