<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;

class FinanceSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly Select2ResponseService $select2,
        private readonly OperatingContextService $operatingContext,
        private readonly CashboxChartAccountService $cashboxChartAccounts,
    ) {}

    public function accounts(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];
        $query = Account::query()
            ->join('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.status', 'active')
            ->where('accounts.is_postable', true)
            ->where('accounts.is_group', false)
            ->select(['accounts.doc_num', 'accounts.doc_number', 'accounts.account_code', 'accounts.name', 'accounts.name_en', 'accounts.normal_balance']);

        if ($companyId === null) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('accounts.company_id', (int) $companyId);
        }

        if ($request->filled('classification')) {
            $query->where('account_classifications.code', $request->string('classification')->toString());
        }

        if ($request->filled('exclude')) {
            $query->where('accounts.doc_num', '!=', $request->string('exclude')->toString());
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['accounts.doc_num', 'accounts.account_code', 'accounts.name']]);
        }

        $query->orderByRaw('LENGTH(accounts.account_code), accounts.account_code');

        return $this->select2->paginated($query, $request, fn (Account $account): array => [
            'id' => (string) $account->doc_num,
            'text' => $account->codeNameLabel(),
            'normal_balance' => (string) $account->normal_balance,
            'account_nature' => (string) $account->normal_balance,
        ]);
    }

    public function cashboxes(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return [
                'results' => [],
                'pagination' => [
                    'more' => false,
                ],
            ];
        }

        $query = Cashbox::query()
            ->leftJoin('accounts', 'accounts.id', '=', 'cashboxes.account_id')
            ->where('cashboxes.company_id', $companyId)
            ->where('cashboxes.status', 'active')
            ->whereNull('cashboxes.deleted_at')
            ->select([
                'cashboxes.doc_num',
                'cashboxes.doc_number',
                'cashboxes.name',
                'accounts.doc_num as account_doc_num',
                'accounts.account_code',
                'accounts.name as account_name',
                'accounts.name_en as account_name_en',
            ])
            ->orderBy('cashboxes.doc_number');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => ['cashboxes.doc_num', 'cashboxes.name', 'accounts.account_code', 'accounts.name', 'accounts.name_en'],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Cashbox $cashbox): array => [
            'id' => (string) $cashbox->doc_num,
            'text' => trim(implode(' — ', array_filter([$cashbox->doc_num, $cashbox->name]))),
            'account_doc_num' => $cashbox->account_doc_num,
            'account_label' => Account::codeNameLabelFor($cashbox->account_code, $cashbox->account_name, $cashbox->account_name_en),
        ]);
    }

    public function bankAccounts(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return [
                'results' => [],
                'pagination' => [
                    'more' => false,
                ],
            ];
        }

        $query = BankAccount::query()
            ->leftJoin('currencies', 'currencies.id', '=', 'bank_accounts.currency_id')
            ->where('bank_accounts.company_id', $companyId)
            ->where('bank_accounts.status', 'active')
            ->whereNull('bank_accounts.deleted_at')
            ->select([
                'bank_accounts.doc_num',
                'bank_accounts.doc_number',
                'bank_accounts.bank_name',
                'bank_accounts.account_name',
                'currencies.doc_num as currency_doc_num',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'currencies.is_main as currency_is_main',
            ])
            ->orderBy('bank_accounts.doc_number');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => ['bank_accounts.doc_num', 'bank_accounts.bank_name', 'bank_accounts.account_name', 'currencies.code', 'currencies.name'],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (BankAccount $bankAccount): array => [
            'id' => (string) $bankAccount->doc_num,
            'text' => trim(implode(' — ', array_filter([$bankAccount->doc_num, $bankAccount->bank_name, $bankAccount->account_name]))),
            'currency_doc_num' => $bankAccount->currency_doc_num,
            'currency_text' => trim(implode(' — ', array_filter([$bankAccount->currency_code, $bankAccount->currency_name]))),
            'currency_is_main' => (bool) $bankAccount->currency_is_main,
        ]);
    }

    public function customers(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return $this->empty();
        }

        $query = Customer::query()
            ->active()
            ->forCompany((int) $companyId)
            ->select(['doc_num', 'doc_number', 'name', 'phone', 'mobile', 'email'])
            ->orderBy('name')
            ->orderBy('doc_number');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => ['doc_num', 'name', 'phone', 'mobile', 'email'],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Customer $customer): array => $this->partyOption($customer));
    }

    public function suppliers(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return $this->empty();
        }

        $query = Supplier::query()
            ->active()
            ->forCompany((int) $companyId)
            ->select(['doc_num', 'doc_number', 'name', 'phone', 'mobile', 'email'])
            ->orderBy('name')
            ->orderBy('doc_number');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => ['doc_num', 'name', 'phone', 'mobile', 'email'],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Supplier $supplier): array => $this->partyOption($supplier));
    }

    public function holderCurrencies(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return [
                'results' => [],
                'pagination' => [
                    'more' => false,
                ],
            ];
        }

        $holderType = $request->string('holder_type')->trim()->toString();
        $holderDocNum = $request->string('holder')->trim()->toString() ?: $request->string('parent')->trim()->toString();
        $restrictedCurrencyIds = [];

        if ($holderType === 'bank_account' && $holderDocNum !== '') {
            $bankCurrencyId = BankAccount::query()
                ->forCompany((int) $companyId)
                ->active()
                ->where('doc_num', $holderDocNum)
                ->value('currency_id');

            $restrictedCurrencyIds = $bankCurrencyId ? [(int) $bankCurrencyId] : [-1];
        }

        if ($holderType === 'cashbox' && $holderDocNum !== '') {
            $cashbox = Cashbox::query()
                ->forCompany((int) $companyId)
                ->active()
                ->where('doc_num', $holderDocNum)
                ->first();

            if (! $cashbox instanceof Cashbox) {
                $restrictedCurrencyIds = [-1];
            } else {
                $restrictedCurrencyIds = $cashbox->currencies()
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->pluck('currency_id')
                    ->all();
            }
        }

        $query = Currency::query()
            ->active()
            ->forCompany((int) $companyId)
            ->select(['doc_num', 'doc_number', 'name', 'code', 'is_main'])
            ->when($restrictedCurrencyIds !== [], fn ($query) => $query->whereIn('id', $restrictedCurrencyIds))
            ->orderByDesc('is_main')
            ->orderBy('code');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name', 'code']]);
        }

        return $this->select2->paginated($query, $request, fn (Currency $currency): array => [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' — ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
            'default_exchange_rate' => $currency->is_main ? '1' : null,
        ]);
    }

    public function cashboxCurrencies(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];
        $cashboxDocNum = $request->string('cashbox')->trim()->toString() ?: $request->string('parent')->trim()->toString();

        if ($companyId === null || $cashboxDocNum === '') {
            return [
                'results' => [],
                'pagination' => [
                    'more' => false,
                ],
            ];
        }

        $cashbox = Cashbox::query()
            ->forCompany((int) $companyId)
            ->active()
            ->where('doc_num', $cashboxDocNum)
            ->first();

        if (! $cashbox instanceof Cashbox) {
            return [
                'results' => [],
                'pagination' => [
                    'more' => false,
                ],
            ];
        }

        $restrictedCurrencyIds = $cashbox->currencies()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('currency_id')
            ->all();

        $query = Currency::query()
            ->active()
            ->forCompany((int) $companyId)
            ->select(['doc_num', 'doc_number', 'name', 'code', 'is_main'])
            ->when($restrictedCurrencyIds !== [], fn ($query) => $query->whereIn('id', $restrictedCurrencyIds))
            ->orderByDesc('is_main')
            ->orderBy('code');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name', 'code']]);
        }

        return $this->select2->paginated($query, $request, fn (Currency $currency): array => [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' — ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
            'default_exchange_rate' => $currency->is_main ? '1' : null,
        ]);
    }

    public function cashboxBranches(Request $request): array
    {
        if (! $this->operatingContext->currentCompanyModel($request)) {
            return [
                'results' => [],
                'pagination' => [
                    'more' => false,
                ],
            ];
        }

        $query = $this->operatingContext->allowedBranchQueryForCurrentCompany($request)
            ->select([
                'branches.doc_num',
                'branches.name',
                'branches.doc_number',
                'companies.name as company_name',
            ]);

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => ['branches.doc_num', 'branches.name', 'companies.name'],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Branch $branch): array => [
            'id' => (string) $branch->doc_num,
            'text' => trim(implode(' / ', array_filter([$branch->name, $branch->doc_num, $branch->company_name]))),
        ]);
    }

    public function cashboxParentAccounts(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return [
                'results' => [],
                'pagination' => [
                    'more' => false,
                ],
            ];
        }

        try {
            $mainCashParent = $this->cashboxChartAccounts->mainCashboxesAccount();
        } catch (DomainException) {
            return [
                'results' => [],
                'pagination' => [
                    'more' => false,
                ],
            ];
        }

        $query = Account::query()
            ->leftJoin('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.status', 'active')
            ->where('accounts.company_id', $companyId)
            ->where('accounts.is_group', true)
            ->where('accounts.is_postable', false)
            ->where('account_classifications.code', 'cash')
            ->where('accounts.parent_id', $mainCashParent->getKey())
            ->select(['accounts.doc_num', 'accounts.doc_number', 'accounts.account_code', 'accounts.name', 'accounts.name_en'])
            ->orderByRaw('LENGTH(accounts.account_code), accounts.account_code');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => ['accounts.doc_num', 'accounts.account_code', 'accounts.name', 'accounts.name_en'],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Account $account): array => [
            'id' => (string) $account->doc_num,
            'text' => Account::codeNameLabelFor($account->account_code, $account->name, $account->name_en),
        ]);
    }

    private function empty(): array
    {
        return [
            'results' => [],
            'pagination' => [
                'more' => false,
            ],
        ];
    }

    /**
     * @param  Customer|Supplier  $party
     * @return array{id: string, text: string, name: string}
     */
    private function partyOption(Customer|Supplier $party): array
    {
        return [
            'id' => (string) $party->doc_num,
            'text' => trim(implode(' / ', array_filter([$party->doc_num, $party->name, $party->phone ?: $party->mobile]))),
            'name' => (string) $party->name,
        ];
    }
}
