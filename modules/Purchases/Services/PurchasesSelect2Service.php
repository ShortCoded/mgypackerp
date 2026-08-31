<?php

namespace Modules\Purchases\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\ScreenDataVisibilityService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Purchases\Models\Supplier;

class PurchasesSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly Select2ResponseService $select2,
        private readonly OperatingContextService $operatingContext,
        private readonly BusinessPartnerAccountService $accounts,
        private readonly ProductComponentUnitOptionsService $unitOptions,
        private readonly ProductImageResolver $productImages,
        private readonly ScreenDataVisibilityService $visibility,
    ) {}

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

        return $this->select2->paginated($query, $request, fn (Supplier $supplier): array => [
            'id' => (string) $supplier->doc_num,
            'text' => trim(implode(' / ', array_filter([$supplier->doc_num, $supplier->name, $supplier->phone ?: $supplier->mobile]))),
        ]);
    }

    public function products(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];
        $selectedDocNum = $request->string('selected_doc_num')->trim()->toString();
        $query = $this->productQuery($companyId, $request->user());

        if ($selectedDocNum !== '') {
            $selected = $this->productQuery($companyId, $request->user(), true)
                ->where('products.doc_num', $selectedDocNum)
                ->first();

            return [
                'results' => $selected instanceof Product ? [$this->productItem($selected)] : [],
                'pagination' => ['more' => false],
            ];
        }

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'products.doc_num',
                    'products.name',
                    'products.barcode',
                    'products.item_classification',
                    'item_units.name',
                    'item_units.doc_num',
                    'item_categories.name',
                    'item_groups.name',
                    'item_models.name',
                    'item_colors.name',
                    'item_sizes.name',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Product $product): array => $this->productItem($product));
    }

    public function currencies(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return $this->empty();
        }

        $query = Currency::query()
            ->active()
            ->forCompany((int) $companyId)
            ->select(['doc_num', 'doc_number', 'code', 'name', 'is_main'])
            ->orderByDesc('is_main')
            ->orderBy('code');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'code', 'name']]);
        }

        return $this->select2->paginated($query, $request, fn (Currency $currency): array => [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ]);
    }

    public function branchStores(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $companyId = $context['company_id'];

        if ($companyId === null) {
            return $this->empty();
        }

        $query = BranchStore::query()
            ->join('branches', 'branches.id', '=', 'branch_stores.branch_id')
            ->purchasingEligible()
            ->where('branches.company_id', (int) $companyId)
            ->where('branches.status', 'active')
            ->whereNull('branches.deleted_at')
            ->whereNull('branch_stores.deleted_at')
            ->select([
                'branch_stores.public_uuid',
                'branch_stores.name',
                'branch_stores.classification',
                'branch_stores.position',
                'branches.name as branch_name',
            ])
            ->orderBy('branches.name')
            ->orderBy('branch_stores.position')
            ->orderBy('branch_stores.name');

        $terms = $this->search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->search->applyMultiTermSearch($query, $terms, ['text' => ['branch_stores.name', 'branches.name']]);
        }

        return $this->select2->paginated($query, $request, fn (BranchStore $store): array => [
            'id' => (string) $store->public_uuid,
            'text' => trim(implode(' — ', array_filter([$store->name, $store->branch_name]))),
        ]);
    }

    public function cashboxes(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return $this->empty();
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
            'text' => trim(implode(' / ', array_filter([$cashbox->doc_num, $cashbox->name]))),
            'account_doc_num' => $cashbox->account_doc_num,
            'account_label' => Account::codeNameLabelFor($cashbox->account_code, $cashbox->account_name, $cashbox->account_name_en),
        ]);
    }

    public function bankAccounts(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return $this->empty();
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
            'text' => trim(implode(' / ', array_filter([$bankAccount->doc_num, $bankAccount->bank_name, $bankAccount->account_name]))),
            'currency_doc_num' => $bankAccount->currency_doc_num,
            'currency_text' => trim(implode(' / ', array_filter([$bankAccount->currency_code, $bankAccount->currency_name]))),
            'currency_is_main' => (bool) $bankAccount->currency_is_main,
        ]);
    }

    public function supplierGroups(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return $this->empty();
        }

        $root = $this->accounts->rootAccount(BusinessPartnerAccountService::Supplier);

        $query = Account::query()
            ->leftJoin('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.status', 'active')
            ->where('accounts.company_id', $companyId)
            ->where('accounts.is_group', true)
            ->where('accounts.is_postable', false)
            ->where('account_classifications.code', 'accounts_payable')
            ->where('accounts.id', '!=', $root->getKey())
            ->where(function ($query) use ($root): void {
                $query->where('accounts.parent_id', $root->getKey())
                    ->orWhereIn('accounts.parent_id', Account::query()
                        ->select('id')
                        ->where('company_id', $root->company_id)
                        ->where('parent_id', $root->getKey()));
            })
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
            'pagination' => ['more' => false],
        ];
    }

    private function productQuery(?int $companyId, ?User $user, bool $includeHistorical = false): Builder
    {
        $query = Product::query()
            ->with(['unit', 'equivalentUnit', 'mainImageUsage.file'])
            ->active()
            ->when(! $includeHistorical, fn (Builder $query) => $query->purchasable())
            ->when($companyId, fn ($query) => $query->forCompany((int) $companyId), fn ($query) => $query->whereRaw('1 = 0'));

        if ($user instanceof User) {
            $query = $this->visibility->applyAnyScreenToEloquent($query, $user, [Product::ContextProducts, Product::ContextRawMaterials, Product::ContextPackagingMaterials]);
        }

        return $query->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'products.item_category_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'products.item_group_id')
            ->leftJoin('item_models', 'item_models.id', '=', 'products.item_model_id')
            ->leftJoin('item_colors', 'item_colors.id', '=', 'products.item_color_id')
            ->leftJoin('item_sizes', 'item_sizes.id', '=', 'products.item_size_id')
            ->select([
                'products.id',
                'products.company_id',
                'products.doc_number',
                'products.doc_num',
                'products.name',
                'products.image_path',
                'products.barcode',
                'products.item_classification',
                'products.item_unit_id',
                'products.equivalent_unit_id',
                'item_units.doc_num as unit_doc_num',
                'item_units.name as unit_name',
                'item_categories.name as category_name',
                'item_groups.name as group_name',
                'item_models.name as model_name',
                'item_colors.name as color_name',
                'item_sizes.name as size_name',
            ])
            ->orderBy('products.name')
            ->orderBy('products.doc_number');
    }

    /**
     * @return array<string, mixed>
     */
    private function productItem(Product $product): array
    {
        $unitLabel = trim(implode(' / ', array_filter([$product->unit_doc_num, $product->unit_name])));
        $barcode = trim((string) $product->barcode);

        return [
            'id' => (string) $product->doc_num,
            'text' => trim(implode(' / ', array_filter([$product->doc_num, $product->name, $barcode === '' ? null : $barcode, $unitLabel]))),
            'unitDocNum' => $product->unit_doc_num,
            'unitLabel' => $unitLabel,
            'unit_options' => $this->unitOptions->options($product),
            'imageUrl' => $this->imageUrl($product),
            'productData' => [
                'doc_num' => (string) $product->doc_num,
                'name' => (string) $product->name,
                'barcode' => $barcode === '' ? null : $barcode,
                'item_classification' => (string) $product->item_classification,
                'unit' => $unitLabel,
                'unit_doc_num' => $product->unit_doc_num,
                'category' => $product->category_name,
                'group' => $product->group_name,
                'model' => $product->model_name,
                'color' => $product->color_name,
                'size' => $product->size_name,
            ],
        ];
    }

    private function imageUrl(Product $product): ?string
    {
        return $this->productImages->url($product);
    }
}
