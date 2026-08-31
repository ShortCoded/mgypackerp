<?php

namespace Modules\Accounting\Services;

use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Select2ResponseService;

class AccountSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly Select2ResponseService $select2,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function accounts(Request $request): array
    {
        $companyId = $this->companies->currentCompanyId($request);
        $query = Account::query()
            ->leftJoin('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->select(['accounts.doc_num', 'accounts.account_code', 'accounts.name', 'accounts.name_en', 'accounts.doc_number', 'accounts.account_type', 'accounts.statement_type', 'accounts.normal_balance', 'account_classifications.code as classification_code', 'account_classifications.name as classification_name', 'account_classifications.name_en as classification_name_en'])
            ->orderByRaw('LENGTH(accounts.account_code), accounts.account_code');

        if ($companyId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('accounts.company_id', $companyId);
        }

        if ($request->boolean('postable')) {
            $query->eligibleForDirectPosting();
        } else {
            $query->active();
        }

        if ($request->boolean('group')) {
            $query->where('accounts.is_group', true)->where('accounts.is_postable', false);
        }

        if ($request->filled('classification')) {
            $query->where('account_classifications.code', $request->string('classification')->toString());
        }

        if ($request->filled('parent')) {
            $parent = Account::query()
                ->select(['id', 'account_code'])
                ->forCompany($companyId ?? 0)
                ->where('doc_num', $request->string('parent')->toString())
                ->first();

            if ($parent instanceof Account) {
                $query
                    ->where('accounts.account_code', 'like', $parent->account_code.'%')
                    ->where('accounts.id', '!=', $parent->getKey());
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($request->string('classification')->toString() === 'bank' && ($request->boolean('group') || $request->boolean('bank_accounts'))) {
            $mainBank = $this->mainBanksAccount();

            if ($mainBank instanceof Account) {
                if ($request->boolean('bank_accounts')) {
                    $query
                        ->where('accounts.parent_id', $mainBank->getKey())
                        ->where('accounts.is_group', true)
                        ->where('accounts.is_postable', false);
                } else {
                    $query
                        ->where('accounts.account_code', 'like', $mainBank->account_code.'%')
                        ->where('accounts.id', '!=', $mainBank->getKey());
                }
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->boolean('exclude_linked_bank_accounts')) {
            $exceptBankAccountDocNum = $request->string('except_bank_account')->trim()->toString();

            $query->whereNotExists(function ($subquery) use ($exceptBankAccountDocNum): void {
                $subquery
                    ->selectRaw('1')
                    ->from('bank_accounts')
                    ->whereColumn('bank_accounts.account_id', 'accounts.id')
                    ->whereNull('bank_accounts.deleted_at')
                    ->where('bank_accounts.status', 'active');

                if ($exceptBankAccountDocNum !== '') {
                    $subquery->where('bank_accounts.doc_num', '!=', $exceptBankAccountDocNum);
                }
            });
        }

        if ($request->filled('exclude')) {
            $query->where('accounts.doc_num', '!=', $request->string('exclude')->toString());
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['accounts.doc_num', 'accounts.account_code', 'accounts.name', 'accounts.name_en']]);
        }

        return $this->select2->paginated($query, $request, fn (Account $account): array => $this->item($account));
    }

    public function classifications(Request $request): array
    {
        $query = AccountClassification::query()
            ->select(['code', 'name', 'name_en', 'account_type', 'statement_type', 'normal_balance'])
            ->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['code', 'name', 'name_en']]);
        }

        return $this->select2->paginated($query, $request, fn ($classification): array => [
            'id' => $classification->code,
            'text' => $classification->displayName(),
            'account_type' => $classification->account_type,
            'statement_type' => $classification->statement_type,
            'normal_balance' => $classification->normal_balance,
        ]);
    }

    public function item(Account $account): array
    {
        return [
            'id' => $account->doc_num,
            'text' => $account->codeNameLabel(),
            'account_code' => $account->account_code,
            'account_type' => $account->account_type,
            'statement_type' => $account->statement_type,
            'normal_balance' => $account->normal_balance,
            'classification_code' => $account->classification_code,
            'classification_text' => AccountClassification::displayNameFor($account->classification_name, $account->classification_name_en),
        ];
    }

    private function mainBanksAccount(): ?Account
    {
        $companyId = $this->companies->currentCompanyId();

        if ($companyId === null) {
            return null;
        }

        return Account::query()
            ->leftJoin('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->select(['accounts.id', 'accounts.account_code'])
            ->where('accounts.company_id', $companyId)
            ->where('account_classifications.code', 'bank')
            ->where('accounts.account_code', '1112')
            ->where('accounts.status', 'active')
            ->first();
    }
}
