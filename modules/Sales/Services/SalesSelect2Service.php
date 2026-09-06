<?php

namespace Modules\Sales\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Core\Services\Select2ResponseService;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;

class SalesSelect2Service
{
    public function employees(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = HrEmployee::query()->where('company_id', $context['company_id'])->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $context['branch_id']))->orderBy('full_name')->orderBy('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q', $request->input('term'))), ['text' => ['doc_num', 'full_name', 'name', 'employee_code']]);

        return $this->select2->paginated($query, $request, fn (HrEmployee $employee): array => ['id' => $employee->doc_num, 'text' => $employee->doc_num.' / '.($employee->full_name ?: $employee->name)]);
    }

    public function invoiceableOrders(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = SalesOrder::query()->with('customer')
            ->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->whereIn('status', ['approved', 'partially_fulfilled', 'fulfilled'])
            ->whereHas('lines', fn ($lines) => $lines->whereColumn('invoiced_quantity', '<', 'quantity'))
            ->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q', $request->input('term'))), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn ($order): array => ['id' => $order->doc_num, 'text' => $order->doc_num.' / '.$order->customer?->name]);
    }

    public function convertibleRequests(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = SalesRequest::query()->with('customer')
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->whereIn('status', ['approved', 'partially_converted'])
            ->whereNotNull('customer_id')
            ->whereNotNull('currency_id')
            ->whereHas('lines', fn (Builder $lines) => $lines->whereColumn('converted_quantity', '<', 'quantity'))
            ->orderByDesc('request_date')
            ->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q', $request->input('term'))), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn (SalesRequest $salesRequest): array => [
            'id' => $salesRequest->doc_num,
            'text' => trim(implode(' / ', array_filter([$salesRequest->doc_num, $salesRequest->customer?->name, $salesRequest->request_date?->toDateString()]))),
        ]);
    }

    public function returnableInvoices(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = CustomerInvoice::query()->with('customer')
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('document_type', CustomerInvoice::TypeInvoice)
            ->where('posting_status', CustomerInvoice::StatusPosted)
            ->whereHas('lines', fn (Builder $lines) => $lines->where(function (Builder $eligible): void {
                $returnsSql = '(select coalesce(sum(sales_return_lines.quantity), 0) from sales_return_lines inner join sales_returns on sales_returns.id = sales_return_lines.sales_return_id where sales_return_lines.customer_invoice_line_id = customer_invoice_lines.id and sales_returns.status <> ? and sales_returns.deleted_at is null)';
                $eligible->where(fn (Builder $service) => $service
                    ->where('customer_invoice_lines.is_service', true)
                    ->whereRaw('customer_invoice_lines.quantity > '.$returnsSql, [SalesReturn::StatusCancelled]))
                    ->orWhere(fn (Builder $physical) => $physical
                        ->where('customer_invoice_lines.is_service', false)
                        ->whereRaw('(select coalesce(sum(inventory_document_lines.transaction_quantity), 0) from inventory_document_lines inner join customer_invoice_deliveries on customer_invoice_deliveries.inventory_document_id = inventory_document_lines.inventory_document_id inner join inventory_documents on inventory_documents.id = inventory_document_lines.inventory_document_id where customer_invoice_deliveries.customer_invoice_id = customer_invoice_lines.customer_invoice_id and inventory_document_lines.source_line_type = ? and inventory_document_lines.source_line_id = customer_invoice_lines.sales_order_line_id and inventory_documents.status = ? and inventory_documents.deleted_at is null) > '.$returnsSql, [SalesOrderLine::class, InventoryDocument::StatusPosted, SalesReturn::StatusCancelled]));
            }))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q', $request->input('term'))), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn (CustomerInvoice $invoice): array => [
            'id' => $invoice->doc_num,
            'text' => trim(implode(' / ', array_filter([$invoice->doc_num, $invoice->customer?->name, $invoice->invoice_date?->toDateString()]))),
        ]);
    }

    public function creditTargetInvoices(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $customerDocNum = $request->string('customer_doc_num')->trim()->toString();
        $query = CustomerInvoice::query()
            ->with('customer')
            ->where('company_id', $context['company_id'])
            ->where('document_type', CustomerInvoice::TypeInvoice)
            ->where('posting_status', CustomerInvoice::StatusPosted)
            ->where('remaining_amount', '>', 0)
            ->whereHas('customer', fn (Builder $customer) => $customer->where('doc_num', $customerDocNum))
            ->orderBy('invoice_date')
            ->orderBy('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q', $request->input('term'))), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn (CustomerInvoice $invoice): array => [
            'id' => $invoice->doc_num,
            'text' => trim(implode(' / ', array_filter([
                $invoice->doc_num,
                app(DateFormatService::class)->formatDate($invoice->invoice_date, ''),
                app(NumericFormatService::class)->format($invoice->remaining_amount),
            ]))),
        ]);
    }

    public function deliverableInvoices(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = CustomerInvoice::query()->with('customer')
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('document_type', CustomerInvoice::TypeInvoice)
            ->where('posting_status', CustomerInvoice::StatusPosted)
            ->whereHas('lines', fn (Builder $lines) => $lines
                ->where('is_service', false)
                ->whereRaw('customer_invoice_lines.quantity > (select coalesce(sum(inventory_document_lines.transaction_quantity), 0) from inventory_document_lines inner join customer_invoice_deliveries on customer_invoice_deliveries.inventory_document_id = inventory_document_lines.inventory_document_id inner join inventory_documents on inventory_documents.id = inventory_document_lines.inventory_document_id where customer_invoice_deliveries.customer_invoice_id = customer_invoice_lines.customer_invoice_id and inventory_document_lines.source_line_type = ? and inventory_document_lines.source_line_id = customer_invoice_lines.sales_order_line_id and inventory_documents.status = ? and inventory_documents.deleted_at is null)', [SalesOrderLine::class, InventoryDocument::StatusPosted]))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q', $request->input('term'))), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn (CustomerInvoice $invoice): array => [
            'id' => $invoice->doc_num,
            'text' => trim(implode(' / ', array_filter([$invoice->doc_num, $invoice->customer?->name, $invoice->invoice_date?->toDateString()]))),
        ]);
    }

    public function returnableDeliveries(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = InventoryDocument::query()->with('salesOrder.customer')
            ->where('company_id', $context['company_id'])
            ->where('branch_id', $context['branch_id'])
            ->where('document_type', InventoryDocument::TypeSalesDelivery)
            ->where('status', InventoryDocument::StatusPosted)
            ->where('source_document_type', SalesOrder::class)
            ->whereHas('lines', fn ($lines) => $lines->whereRaw(
                'inventory_document_lines.transaction_quantity > (select coalesce(sum(customer_invoice_lines.quantity), 0) from customer_invoice_lines inner join customer_invoices on customer_invoices.id = customer_invoice_lines.customer_invoice_id where customer_invoice_lines.delivery_line_id = inventory_document_lines.id and customer_invoices.document_type = ?) + (select coalesce(sum(sales_return_lines.quantity), 0) from sales_return_lines inner join sales_returns on sales_returns.id = sales_return_lines.sales_return_id where sales_return_lines.delivery_line_id = inventory_document_lines.id and sales_return_lines.customer_invoice_line_id is null and sales_returns.status <> ? and sales_returns.deleted_at is null)',
                [CustomerInvoice::TypeInvoice, SalesReturn::StatusCancelled],
            ))
            ->orderByDesc('document_date')
            ->orderByDesc('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q', $request->input('term'))), ['text' => ['doc_num']]);

        return $this->select2->paginated($query, $request, fn (InventoryDocument $delivery): array => [
            'id' => $delivery->doc_num,
            'text' => trim(implode(' / ', array_filter([$delivery->doc_num, $delivery->salesOrder?->customer?->name, $delivery->document_date?->toDateString()]))),
        ]);
    }

    public function stores(Request $request): array
    {
        $context = $this->operatingContext->snapshot($request);
        $query = BranchStore::query()->where('branch_id', $context['branch_id'])->orderBy('name')->orderBy('id');
        $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('q', $request->input('term'))), ['text' => ['name']]);

        return $this->select2->paginated($query, $request, fn (BranchStore $store): array => ['id' => $store->public_uuid, 'text' => $store->name]);
    }

    public function employeeId(int $companyId, ?int $branchId, ?string $docNum, ?int $preservedId = null, string $attribute = 'sales_employee_doc_num'): ?int
    {
        if (blank($docNum)) {
            return null;
        }
        $employee = HrEmployee::withTrashed()->where('company_id', $companyId)->where('doc_num', $docNum)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branchId))->first();
        if (! $employee || (($employee->status !== 'active' || $employee->trashed()) && $employee->id !== $preservedId)) {
            throw ValidationException::withMessages([$attribute => __('The selected employee is not available in this company and branch.')]);
        }

        return $employee->id;
    }

    /** Only selected options are hydrated; full datasets stay behind paginated pickers. @return array<string, mixed> */
    public function formOptions(Request $request, ?Model $record = null, ?SalesRequest $sourceRequest = null): array
    {
        $context = $this->operatingContext->snapshot($request);
        $selectedProducts = collect($request->old('lines', []))->pluck('product_doc_num')
            ->merge($record?->lines?->map(fn ($line) => $line->product?->doc_num) ?? [])
            ->merge($sourceRequest?->lines?->map(fn ($line) => $line->product?->doc_num) ?? [])->filter()->unique();
        $selectedCurrencyDocNum = $request->old('currency_doc_num', $record?->currency?->doc_num ?? $sourceRequest?->currency?->doc_num);
        $selectedCustomerDocNum = $request->old('customer_doc_num', $record?->customer?->doc_num ?? $sourceRequest?->customer?->doc_num);
        $selectedEmployeeDocNum = $request->old('sales_employee_doc_num', $record?->salesEmployee?->doc_num ?? $sourceRequest?->salesEmployee?->doc_num);

        return [
            'products' => Product::query()->with('unit', 'equivalentUnit', 'color')->forCompany($context['company_id'])->whereIn('doc_num', $selectedProducts)->get(),
            'customers' => Customer::query()->forCompany($context['company_id'])->where('doc_num', $selectedCustomerDocNum)->get(),
            'stores' => BranchStore::query()->where('branch_id', $context['branch_id'])->where('public_uuid', $request->old('branch_store_uuid', $record?->branchStore?->public_uuid))->get(),
            'currencies' => Currency::query()->forCompany($context['company_id'])->active()
                ->where(function (Builder $query) use ($selectedCurrencyDocNum): void {
                    $query->where('is_main', true)
                        ->when($selectedCurrencyDocNum, fn (Builder $selected) => $selected->orWhere('doc_num', $selectedCurrencyDocNum));
                })
                ->orderByDesc('is_main')->get(),
            'salesEmployees' => HrEmployee::withTrashed()->where('company_id', $context['company_id'])->where('doc_num', $selectedEmployeeDocNum)->get(),
            'sourceRequest' => $sourceRequest,
        ];
    }

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

        $root = $this->accounts->rootAccount(BusinessPartnerAccountService::Customer);

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
            ->with('mainImageUsage.file', 'unit', 'equivalentUnit')
            ->active()
            ->salesEligible()
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
            'text' => trim(implode(' / ', array_filter([$product->doc_num, $product->name]))),
            'unitDocNum' => $product->unit_doc_num,
            'unitLabel' => $unitLabel,
            'units' => collect([$product->unit, $product->equivalentUnit])->filter()->unique('id')->map(fn ($unit): array => ['id' => $unit->doc_num, 'text' => $unit->doc_num.' / '.$unit->name])->values()->all(),
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
                'item_classification' => $product->item_classification,
            ],
        ];
    }

    private function imageUrl(Product $product): ?string
    {
        return $this->productImages->url($product);
    }
}
