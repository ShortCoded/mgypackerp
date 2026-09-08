<?php

namespace Modules\Purchases\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\ScreenDataVisibilityService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturnLine;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Models\SupplyOrderLine;

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

    public function currencyRate(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $currency = Currency::query()->forCompany((int) $context['company_id'])->where('doc_num', $request->input('currency_doc_num'))->firstOrFail();
        if ($currency->is_main) {
            return ['rate' => 1, 'is_main' => true, 'source' => null];
        }
        $date = app(DateFormatService::class)->normalizeForStorage($request->input('document_date')) ?? now()->toDateString();
        $previous = PurchaseOrder::query()->where('company_id', $context['company_id'])->where('currency_id', $currency->id)
            ->where('status', 'approved')->whereDate('document_date', '<=', $date)->latest('document_date')->latest('id')->first(['doc_num', 'exchange_rate']);

        return ['rate' => $previous?->exchange_rate, 'is_main' => false, 'source' => $previous?->doc_num];
    }

    public function rfqs(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = RequestForQuotation::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('status', 'issued')->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q')), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn ($record): array => ['id' => $record->doc_num, 'text' => $record->doc_num]);
    }

    public function employees(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = HrEmployee::query()->where('company_id', $context['company_id'])
            ->active()->select(['id', 'doc_num', 'full_name', 'name'])->orderBy('full_name')->orderBy('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q')), ['text' => ['doc_num', 'full_name', 'name']]);

        return $this->select2->paginated($query, $request, fn ($employee): array => ['id' => (string) $employee->id, 'text' => $employee->doc_num.' / '.($employee->full_name ?: $employee->name)]);
    }

    public function requisitions(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $isAdministrativeBranch = Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
        $query = PurchaseRequisition::query()->where('company_id', $context['company_id'])
            ->when(! $isAdministrativeBranch, fn (Builder $query) => $query->where('branch_id', $context['branch_id']))
            ->when($request->input('purpose') === 'supplier_quotation',
                fn (Builder $query) => $query->whereIn('status', [PurchaseRequisition::StatusApproved, PurchaseRequisition::StatusPartiallyConverted, PurchaseRequisition::StatusFullyConverted])
                    ->whereHas('lines', fn (Builder $lines) => $lines->where('approved_quantity', '>', 0)->whereHas('product', fn (Builder $products) => $products->purchasable())),
                fn (Builder $query) => $query->whereIn('status', [PurchaseRequisition::StatusApproved, PurchaseRequisition::StatusPartiallyConverted])
                    ->whereHas('lines', fn ($lines) => $lines->whereRaw('approved_quantity > COALESCE((SELECT SUM(purchase_order_lines.ordered_quantity) FROM purchase_order_lines INNER JOIN purchase_orders ON purchase_orders.id = purchase_order_lines.purchase_order_id WHERE purchase_order_lines.purchase_requisition_line_id = purchase_requisition_lines.id AND purchase_order_lines.deleted_at IS NULL AND purchase_orders.deleted_at IS NULL AND purchase_orders.status NOT IN (?, ?)), 0)', ['cancelled', 'rejected'])))
            ->with(['branch:id,name', 'branchStore:id,branch_id,name'])
            ->select(['id', 'doc_num', 'request_date', 'branch_id', 'branch_store_id'])->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q')), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn ($record): array => [
            'id' => $record->doc_num,
            'text' => collect([$record->doc_num, $record->request_date->format('Y-m-d'), $record->branch?->name, $record->branchStore?->name])->filter()->join(' / '),
        ]);
    }

    public function purchaseOrders(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $isAdministrativeBranch = Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
        $query = PurchaseOrder::query()->where('company_id', $context['company_id'])
            ->when(
                $request->input('purpose') === 'receipt',
                fn (Builder $query) => $query->whereHas('branchStore', fn (Builder $stores) => $stores->where('branch_id', $context['branch_id'])),
                fn (Builder $query) => $query->when(! $isAdministrativeBranch, fn (Builder $orders) => $orders->where('branch_id', $context['branch_id'])),
            )
            ->whereIn('status', [PurchaseOrder::StatusApproved, PurchaseOrder::StatusClosed])
            ->with('supplier')->orderByDesc('id');
        if (in_array($request->input('purpose'), ['receipt', 'supply_order'], true)) {
            $query->where('status', PurchaseOrder::StatusApproved)->whereHas('lines', function ($lines) use ($request): void {
                $received = UnpricedInventoryReceiptLine::query()->selectRaw('COALESCE(SUM(accepted_quantity), 0)')
                    ->whereColumn('purchase_order_line_id', 'purchase_order_lines.id')
                    ->whereHas('receipt', fn ($receipts) => $receipts->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']));
                if ($request->input('purpose') === 'receipt') {
                    $received = UnpricedInventoryReceiptLine::query()
                        ->selectRaw("COALESCE(SUM(CASE WHEN unpriced_inventory_receipts.approved = 1 AND unpriced_inventory_receipts.posting_status = 'posted' AND unpriced_inventory_receipts.status NOT IN ('cancelled', 'reversed') THEN unpriced_inventory_receipt_lines.accepted_quantity WHEN unpriced_inventory_receipts.posting_status = 'unposted' AND unpriced_inventory_receipts.status = 'draft' THEN unpriced_inventory_receipt_lines.delivered_quantity ELSE 0 END), 0)")
                        ->join('unpriced_inventory_receipts', 'unpriced_inventory_receipts.id', '=', 'unpriced_inventory_receipt_lines.receipt_id')
                        ->whereColumn('purchase_order_line_id', 'purchase_order_lines.id')
                        ->whereNull('unpriced_inventory_receipts.deleted_at');
                }
                $lines->whereHas('product', fn ($products) => $products->purchasable())
                    ->where('ordered_quantity', '>', $received);
                if ($request->input('purpose') === 'supply_order') {
                    $allocated = SupplyOrderLine::query()->selectRaw('COALESCE(SUM(ordered_quantity), 0)')
                        ->whereColumn('purchase_order_line_id', 'purchase_order_lines.id')
                        ->whereHas('supplyOrder', fn ($orders) => $orders->whereNotIn('status', [SupplyOrder::StatusCancelled]));
                    $lines->where('ordered_quantity', '>', $allocated);
                }
            });
        }
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q')), ['text' => ['doc_num', 'supplier_reference']]);

        return $this->select2->paginated($query, $request, fn ($record): array => ['id' => $record->doc_num, 'text' => $record->doc_num.' / '.$record->supplier?->name]);
    }

    public function invoices(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $isAdministrativeBranch = Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
        $query = PurchaseInvoice::query()->where('company_id', $context['company_id'])
            ->when(
                $request->input('purpose') === 'return',
                fn (Builder $query) => $query->whereHas('purchaseOrder.branchStore', fn (Builder $stores) => $stores->where('branch_id', $context['branch_id'])),
                fn (Builder $query) => $query->when(! $isAdministrativeBranch, fn (Builder $invoices) => $invoices->where('branch_id', $context['branch_id'])),
            )
            ->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])
            ->when($request->input('purpose') === 'supply_order' && ! $request->filled('purchase_order'),
                fn ($query) => $query->whereNotNull('purchase_order_id')
                    ->whereHas('lines', fn ($lines) => $lines->whereNotNull('purchase_order_line_id')->whereHas('product', fn ($products) => $products->purchasable())),
                fn ($query) => $query->whereHas('purchaseOrder', fn ($orders) => $orders->where('doc_num', $request->input('purchase_order'))))
            ->when($request->filled('receipt'), fn ($query) => $query->whereHas('lines.receiptLine.receipt', fn ($receipts) => $receipts->where('doc_num', $request->input('receipt'))))
            ->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q')), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn ($record): array => ['id' => $record->doc_num, 'text' => $record->doc_num]);
    }

    public function supplyOrders(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = SupplyOrder::query()->where('company_id', $context['company_id'])
            ->when(
                $request->input('purpose') === 'receipt',
                fn (Builder $query) => $query->whereHas('branchStore', fn (Builder $stores) => $stores->where('branch_id', $context['branch_id'])),
                fn (Builder $query) => $query->where('branch_id', $context['branch_id']),
            )
            ->whereIn('status', [SupplyOrder::StatusIssued, SupplyOrder::StatusPartiallyReceived])
            ->with('supplier')->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q')), ['text' => ['doc_num', 'source_doc_num']]);

        return $this->select2->paginated($query, $request, fn (SupplyOrder $record): array => [
            'id' => $record->doc_num,
            'text' => $record->doc_num.' / '.$record->supplier?->name.' / '.$record->source_doc_num,
        ]);
    }

    public function receipts(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $isAdministrativeBranch = Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
        $query = UnpricedInventoryReceipt::query()->where('company_id', $context['company_id'])
            ->when(
                $request->input('purpose') === 'invoice',
                fn (Builder $query) => $query->when(! $isAdministrativeBranch, fn (Builder $receipts) => $receipts->whereHas('purchaseOrder', fn (Builder $orders) => $orders->where('branch_id', $context['branch_id']))),
                fn (Builder $query) => $query->where('branch_id', $context['branch_id']),
            )
            ->whereNotNull('purchase_order_id')->where('posting_status', 'posted')
            ->when($request->filled('purchase_order'), fn ($query) => $query->whereHas('purchaseOrder', fn ($po) => $po->where('doc_num', $request->input('purchase_order'))))
            ->with(['supplier', 'purchaseOrder'])->orderByDesc('id');
        if (in_array($request->input('purpose'), ['invoice', 'return'], true)) {
            $query->whereHas('lines', function ($lines) use ($request): void {
                $returned = PurchaseReturnLine::query()->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('receipt_line_id', 'unpriced_inventory_receipt_lines.id')
                    ->whereHas('purchaseReturn', fn ($returns) => $returns->whereNotIn('status', ['cancelled', 'reversed']));
                if ($request->input('purpose') === 'return') {
                    $lines->where('delivered_quantity', '>', $returned);
                } else {
                    $billed = PurchaseInvoiceLine::query()->selectRaw('COALESCE(SUM(quantity), 0)')
                        ->whereColumn('receipt_line_id', 'unpriced_inventory_receipt_lines.id')
                        ->whereHas('purchaseInvoice', fn ($invoices) => $invoices->whereNotIn('status', ['cancelled', 'reversed']));
                    $returnedBeforeInvoice = PurchaseReturnLine::query()->selectRaw('COALESCE(SUM(quantity), 0)')
                        ->whereColumn('receipt_line_id', 'unpriced_inventory_receipt_lines.id')->where('from_quarantine', false)
                        ->whereHas('purchaseReturn', fn ($returns) => $returns->where('status', 'posted')->whereNull('purchase_invoice_id'));
                    $lines->whereRaw('inventory_posted_quantity > ('.$billed->toSql().') + ('.$returnedBeforeInvoice->toSql().')', [...$billed->getBindings(), ...$returnedBeforeInvoice->getBindings()]);
                }
            });
        }
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q')), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn ($record): array => ['id' => $record->doc_num, 'text' => $record->doc_num.' / '.$record->supplier?->name]);
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

        if ($request->filled('rfq')) {
            $query->whereIn('suppliers.id', DB::table('request_for_quotation_suppliers')->select('supplier_id')->whereIn('request_for_quotation_id', RequestForQuotation::query()->where('company_id', $companyId)->where('doc_num', $request->input('rfq'))->select('id')));
        }

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
            ->where('branch_stores.branch_id', $context['branch_id'])
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
