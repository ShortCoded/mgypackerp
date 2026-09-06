<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Sales\Models\Customer;

class FixedAssetsSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly Select2ResponseService $select2,
        private readonly OperatingContextService $operatingContext,
        private readonly BusinessPartnerAccountService $accounts,
    ) {}

    public function assetCategories(Request $request): array
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return $this->empty();
        }

        $root = $this->accounts->rootAccount(BusinessPartnerAccountService::FixedAsset);

        $query = Account::query()
            ->leftJoin('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.status', 'active')
            ->where('accounts.company_id', $companyId)
            ->where('accounts.is_group', true)
            ->where('accounts.is_postable', false)
            ->where('account_classifications.code', AccountClassification::FixedAssets)
            ->where('account_classifications.status', 'active')
            ->where('accounts.id', '!=', $root->getKey())
            ->whereIn('accounts.id', $this->accounts->selectableGroupIds(BusinessPartnerAccountService::FixedAsset))
            ->select(['accounts.doc_num', 'accounts.doc_number', 'accounts.account_code', 'accounts.name', 'accounts.name_en'])
            ->orderByRaw('LENGTH(accounts.account_code), accounts.account_code');

        $this->applyTerms($query, $request, ['accounts.doc_num', 'accounts.account_code', 'accounts.name', 'accounts.name_en']);

        return $this->select2->paginated($query, $request, fn (Account $account): array => [
            'id' => (string) $account->doc_num,
            'text' => Account::codeNameLabelFor($account->account_code, $account->name, $account->name_en),
        ]);
    }

    public function assets(Request $request): array
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return $this->empty();
        }

        $query = FixedAsset::query()
            ->whereIn('branch_id', app(FixedAssetAccessService::class)->branchIds())
            ->where('company_id', $companyId)
            ->select(['doc_num', 'doc_number', 'asset_name', 'serial_number', 'status'])
            ->orderBy('doc_number');

        $this->applyTerms($query, $request, ['doc_num', 'asset_name', 'serial_number']);

        return $this->select2->paginated($query, $request, fn (FixedAsset $asset): array => [
            'id' => (string) $asset->doc_num,
            'text' => trim(implode(' / ', array_filter([$asset->doc_num, $asset->asset_name, $asset->serial_number]))),
        ]);
    }

    public function creditAccounts(Request $request): array
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return $this->empty();
        }

        $query = Account::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_group', false)
            ->where('is_postable', true)
            ->select(['doc_num', 'doc_number', 'account_code', 'name', 'name_en'])
            ->orderByRaw('LENGTH(account_code), account_code');

        $this->applyTerms($query, $request, ['doc_num', 'account_code', 'name', 'name_en']);

        return $this->select2->paginated($query, $request, fn (Account $account): array => [
            'id' => (string) $account->doc_num,
            'text' => Account::codeNameLabelFor($account->account_code, $account->name, $account->name_en),
        ]);
    }

    public function costCenters(Request $request): array
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return $this->empty();
        }

        $query = CostCenter::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_group', false)
            ->select(['doc_num', 'doc_number', 'cost_center_code', 'name'])
            ->orderBy('cost_center_code');

        $this->applyTerms($query, $request, ['doc_num', 'cost_center_code', 'name']);

        return $this->select2->paginated($query, $request, fn (CostCenter $costCenter): array => [
            'id' => (string) $costCenter->doc_num,
            'text' => $costCenter->codeNameLabel(),
        ]);
    }

    public function branches(Request $request): array
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return $this->empty();
        }

        $query = Branch::query()
            ->whereIn('id', app(FixedAssetAccessService::class)->branchIds())
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->select(['doc_num', 'doc_number', 'name'])
            ->orderBy('doc_number');

        $this->applyTerms($query, $request, ['doc_num', 'name']);

        return $this->select2->paginated($query, $request, fn (Branch $branch): array => [
            'id' => (string) $branch->doc_num,
            'text' => trim(implode(' / ', array_filter([$branch->doc_num, $branch->name]))),
        ]);
    }

    public function branchHalls(Request $request): array
    {
        $companyId = $this->companyId($request);
        $branchDocNum = $request->string('branch_doc_num')->trim()->toString();

        if ($companyId === null || $branchDocNum === '') {
            return $this->empty();
        }

        $branch = Branch::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $branchDocNum)
            ->where('status', 'active')
            ->where('type', Branch::TypeFactory)
            ->first();

        if (! $branch instanceof Branch) {
            return $this->empty();
        }

        $query = BranchHall::query()
            ->where('branch_id', $branch->getKey())
            ->select(['public_uuid', 'name', 'position'])
            ->orderBy('position')
            ->orderBy('name');

        $this->applyTerms($query, $request, ['name']);

        return $this->select2->paginated($query, $request, fn (BranchHall $hall): array => [
            'id' => (string) $hall->public_uuid,
            'text' => trim($hall->name),
        ]);
    }

    public function currencies(Request $request): array
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return $this->empty();
        }

        $query = Currency::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->select(['doc_num', 'doc_number', 'code', 'name', 'is_main'])
            ->orderByDesc('is_main')
            ->orderBy('code');

        $this->applyTerms($query, $request, ['doc_num', 'code', 'name']);

        return $this->select2->paginated($query, $request, fn (Currency $currency): array => [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ]);
    }

    public function customers(Request $request): array
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return $this->empty();
        }

        $query = Customer::query()
            ->active()
            ->forCompany($companyId)
            ->select(['doc_num', 'doc_number', 'name', 'phone', 'mobile'])
            ->orderBy('name')
            ->orderBy('doc_number');

        $this->applyTerms($query, $request, ['doc_num', 'name', 'phone', 'mobile']);

        return $this->select2->paginated($query, $request, fn (Customer $customer): array => [
            'id' => (string) $customer->doc_num,
            'text' => trim(implode(' / ', array_filter([$customer->doc_num, $customer->name, $customer->phone ?: $customer->mobile]))),
        ]);
    }

    private function companyId(Request $request): ?int
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        return $companyId === null ? null : (int) $companyId;
    }

    /**
     * @param  list<string>  $columns
     */
    private function applyTerms($query, Request $request, array $columns): void
    {
        $terms = $this->search->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => $columns]);
        }
    }

    private function empty(): array
    {
        return [
            'results' => [],
            'pagination' => ['more' => false],
        ];
    }
}
