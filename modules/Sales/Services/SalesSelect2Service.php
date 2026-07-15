<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\Select2ResponseService;
use Modules\Sales\Models\Customer;

class SalesSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly Select2ResponseService $select2,
        private readonly OperatingContextService $operatingContext,
        private readonly BusinessPartnerAccountService $accounts,
        private readonly ProductImageResolver $productImages,
    ) {}

    public function customerGroups(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        if ($companyId === null) {
            return $this->empty();
        }

        try {
            $root = $this->accounts->rootAccount(BusinessPartnerAccountService::Customer);
        } catch (DomainException) {
            return $this->empty();
        }

        $query = Account::query()
            ->leftJoin('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->where('accounts.status', 'active')
            ->where('accounts.company_id', $companyId)
            ->where('accounts.is_group', true)
            ->where('accounts.is_postable', false)
            ->where('account_classifications.code', 'accounts_receivable')
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

        return $this->select2->paginated($query, $request, fn (Customer $customer): array => [
            'id' => (string) $customer->doc_num,
            'text' => trim(implode(' / ', array_filter([$customer->doc_num, $customer->name, $customer->phone ?: $customer->mobile]))),
        ]);
    }

    public function products(Request $request): array
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];
        $selectedDocNum = $request->string('selected_doc_num')->trim()->toString();
        $query = $this->productQuery($companyId);

        if ($selectedDocNum !== '') {
            $selected = (clone $query)->where('products.doc_num', $selectedDocNum)->first();

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

    private function empty(): array
    {
        return [
            'results' => [],
            'pagination' => ['more' => false],
        ];
    }

    private function productQuery(?int $companyId): Builder
    {
        return Product::query()
            ->with('mainImageUsage.file')
            ->active()
            ->when($companyId, fn ($query) => $query->forCompany((int) $companyId), fn ($query) => $query->whereRaw('1 = 0'))
            ->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
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
                'products.item_unit_id',
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
            'imageUrl' => $this->imageUrl($product),
            'productData' => [
                'doc_num' => (string) $product->doc_num,
                'name' => (string) $product->name,
                'barcode' => $barcode === '' ? null : $barcode,
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
