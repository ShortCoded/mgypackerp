<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemGroup;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemSize;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Inventory\Exports\InventoryBookValuationExport;
use Modules\Inventory\Exports\InventoryReportExport;
use Modules\Inventory\Exports\InventorySalesValuationExport;
use Modules\Inventory\Exports\InventoryValuationComparisonExport;
use Modules\Inventory\Exports\StockBalanceInquiryExport;
use Modules\Inventory\Http\Requests\InventoryBookValuationRequest;
use Modules\Inventory\Http\Requests\StockBalanceInquiryRequest;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryReportService;
use Modules\Inventory\Services\InventoryValuationService;
use Modules\Sales\Models\PriceList;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryReportController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly InventoryReportService $reports,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
        private readonly NumericFormatService $numbers,
        private readonly InventoryValuationService $valuation,
    ) {}

    public function index(Request $request): View
    {
        [, , $report] = $this->report($request);
        $balances = $report['balances'];
        $balances->each->makeHidden(['inventory_value', 'unvalued_receipt_quantity']);

        return view('modules.inventory.reports.index', [
            ...$report,
            'balances' => $balances,
            'canViewFinancial' => false,
            'agingSupported' => true,
            'expirySupported' => true,
            'numbers' => $this->numbers,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [, , $report] = $this->report($request);

        return Excel::download(
            new InventoryReportExport($report),
            'inventory-operations-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function print(Request $request): Response
    {
        [$context, , $report] = $this->report($request);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.inventory.operations', [
            ...$report,
            'canViewFinancial' => false,
            'title' => __('Inventory Operations Report'),
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
            'numbers' => $this->numbers,
        ], 'inventory-operations-report.pdf');
    }

    public function valuation(InventoryBookValuationRequest $request): View
    {
        return view('modules.inventory.reports.valuation', $this->valuationData($request));
    }

    public function valuationExport(InventoryBookValuationRequest $request): BinaryFileResponse|Response
    {
        $data = $this->valuationData($request);
        $format = (string) $request->route('valuation_export_format');
        $isComparisonExport = filled($request->input('product_id'))
            && filled($request->input('branch_store_id'))
            && is_array($data['comparison']);

        if ($format === 'pdf') {
            $context = $this->context->snapshot($request);
            $company = Company::query()->findOrFail($context['company_id']);

            if ($isComparisonExport) {
                return $this->pdf->stream('reports.inventory.valuation', [
                    'title' => __('inventory_accounting.valuation_report.title'),
                    'comparison' => $data['comparison'],
                    'product' => $data['selectedProduct'],
                    'store' => $data['selectedStore'],
                    'companyPrintIdentity' => $this->printIdentity->forCompany($company),
                ], 'inventory-valuation-comparison.pdf', 'L');
            }

            return $this->pdf->stream('reports.inventory.book-valuation', [
                'title' => __('inventory_accounting.book_valuation.title'),
                'rows' => $data['bookValuation']['rows'],
                'totals' => $data['bookValuation']['totals'],
                'asOf' => $data['asOf'],
                'currencyCode' => $data['currencyCode'],
                'filterSummary' => $data['filterSummary'],
                'companyPrintIdentity' => $this->printIdentity->forCompany($company),
            ], 'inventory-book-valuation.pdf', 'L');
        }

        $writer = $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;
        $extension = $format === 'csv' ? 'csv' : 'xlsx';

        if ($isComparisonExport) {
            return Excel::download(
                new InventoryValuationComparisonExport($data['comparison']),
                "inventory-valuation-comparison.{$extension}",
                $writer,
            );
        }

        return Excel::download(
            new InventoryBookValuationExport(
                $data['bookValuation']['rows'],
                $data['bookValuation']['totals'],
                $data['currencyCode'],
            ),
            "inventory-book-valuation.{$extension}",
            $writer,
        );
    }

    /** @return array<string, mixed> */
    private function valuationData(InventoryBookValuationRequest $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless(
            $context['company_id'] && $context['financial_period_id'] && $context['branch_id'],
            422,
            __('inventory_accounting.errors.context_required'),
        );

        $filters = $request->validated();
        $period = FinancialPeriod::query()
            ->where('company_id', $context['company_id'])
            ->findOrFail($context['financial_period_id']);
        $defaultAsOf = today()->betweenIncluded($period->from_date, $period->to_date)
            ? today()->toDateString()
            : $period->to_date->toDateString();
        $asOf = (string) ($filters['as_of'] ?? $defaultAsOf);

        if ($asOf < $period->from_date->toDateString() || $asOf > $period->to_date->toDateString()) {
            throw ValidationException::withMessages([
                'as_of' => __('inventory_accounting.errors.date_range', [
                    'min' => $period->from_date->toDateString(),
                    'max' => $period->to_date->toDateString(),
                ]),
            ]);
        }

        $branches = $this->context->allowedBranchQueryForCurrentCompany($request)
            ->whereIn('branches.type', [Branch::TypeFactory, Branch::TypeWarehouse, Branch::TypeShowroom])
            ->get();
        $branchIds = $branches->modelKeys();
        $aggregateStores = BranchStore::query()
            ->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0])
            ->with('branch:id,doc_num,name,type')
            ->orderBy('position')->orderBy('name')->get();
        $halls = BranchHall::query()
            ->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0])
            ->with('branch:id,doc_num,name,type')
            ->orderBy('position')->orderBy('name')->get();
        $locations = WarehouseLocation::query()
            ->whereIn('branch_store_id', $aggregateStores->modelKeys() !== [] ? $aggregateStores->modelKeys() : [0])
            ->with('branchStore.branch:id,doc_num,name,type')
            ->orderBy('branch_store_id')->orderBy('position')->orderBy('code')->get();

        $selectedBranch = $this->selectedOption($branches, 'doc_num', $filters['branch_doc_num'] ?? null, 'branch_doc_num');
        $selectedAggregateStore = $this->selectedOption($aggregateStores, 'public_uuid', $filters['branch_store_uuid'] ?? null, 'branch_store_uuid');
        $selectedHall = $this->selectedOption($halls, 'public_uuid', $filters['branch_hall_uuid'] ?? null, 'branch_hall_uuid');
        $selectedLocation = $this->selectedOption($locations, 'public_id', $filters['warehouse_location_uuid'] ?? null, 'warehouse_location_uuid');

        if ($selectedBranch && $selectedAggregateStore && (int) $selectedAggregateStore->branch_id !== (int) $selectedBranch->getKey()) {
            throw ValidationException::withMessages(['branch_store_uuid' => __('stock_balance_inquiry.validation.store_branch')]);
        }
        if ($selectedBranch && $selectedHall && (int) $selectedHall->branch_id !== (int) $selectedBranch->getKey()) {
            throw ValidationException::withMessages(['branch_hall_uuid' => __('stock_balance_inquiry.validation.hall_branch')]);
        }
        if ($selectedAggregateStore && $selectedLocation && (int) $selectedLocation->branch_store_id !== (int) $selectedAggregateStore->getKey()) {
            throw ValidationException::withMessages(['warehouse_location_uuid' => __('stock_balance_inquiry.validation.location_store')]);
        }

        $queryFilters = [
            ...$filters,
            'as_of' => $asOf,
            'branch_id' => $selectedBranch?->getKey(),
            'branch_store_id' => $selectedAggregateStore?->getKey(),
            'branch_hall_id' => $selectedHall?->getKey(),
            'warehouse_location_id' => $selectedLocation?->getKey(),
        ];
        $bookValuation = $this->reports->bookValuation(
            (int) $context['company_id'],
            $branchIds,
            $queryFilters,
        );
        $currencyCode = (string) (Currency::query()->forCompany((int) $context['company_id'])
            ->active()->where('is_main', true)->value('code') ?? '—');

        $products = Product::query()
            ->where('company_id', $context['company_id'])
            ->whereIn('item_classification', Product::stockableItemClassifications())
            ->orderBy('name')
            ->get(['id', 'doc_num', 'name']);
        $stores = BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name']);
        $selectedProduct = filled($filters['product_id'] ?? null)
            ? $products->firstWhere('id', (int) $filters['product_id'])
            : null;
        $selectedStore = filled($filters['branch_store_id'] ?? null)
            ? $stores->firstWhere('id', (int) $filters['branch_store_id'])
            : null;

        if ((filled($filters['product_id'] ?? null) && ! $selectedProduct)
            || (filled($filters['branch_store_id'] ?? null) && ! $selectedStore)) {
            throw ValidationException::withMessages([
                'product_id' => __('inventory_accounting.errors.selection_scope'),
            ]);
        }

        $comparison = null;
        $comparisonError = null;
        $referenceMethod = (string) ($filters['reference_method'] ?? InventoryValuationService::Method);

        if ($selectedProduct && $selectedStore) {
            try {
                $comparison = $this->valuation->comparisonForStockPosition(
                    (int) $context['company_id'],
                    (int) $context['financial_period_id'],
                    (int) $context['branch_id'],
                    (int) $selectedStore->getKey(),
                    (int) $selectedProduct->getKey(),
                    $asOf,
                    $referenceMethod,
                );
            } catch (DomainException $exception) {
                $comparisonError = __($exception->getMessage());
            }
        }

        return [
            'filters' => [...$filters, 'as_of' => $asOf],
            'options' => [
                'branches' => $branches,
                'stores' => $aggregateStores,
                'halls' => $halls,
                'locations' => $locations,
                'selected_branch' => $selectedBranch,
                'selected_store' => $selectedAggregateStore,
                'selected_hall' => $selectedHall,
                'selected_location' => $selectedLocation,
                'selected_product' => $this->selectedProduct((int) $context['company_id'], $filters['product_doc_num'] ?? null),
                'selected_lookups' => $this->selectedStockBalanceLookups((int) $context['company_id'], $filters),
            ],
            'bookValuation' => $bookValuation,
            'currencyCode' => $currencyCode,
            'filterSummary' => [
                __('inventory_accounting.book_valuation.as_of') => $asOf,
                __('inventory_accounting.book_valuation.currency') => $currencyCode,
                __('stock_balance_inquiry.filters.branch') => (string) ($selectedBranch?->name ?? __('stock_balance_inquiry.options.all')),
                __('stock_balance_inquiry.filters.store') => (string) ($selectedAggregateStore?->name ?? __('stock_balance_inquiry.options.all')),
                __('stock_balance_inquiry.filters.hall') => (string) ($selectedHall?->name ?? __('stock_balance_inquiry.options.all')),
                __('stock_balance_inquiry.filters.location') => (string) ($selectedLocation?->name ?? __('stock_balance_inquiry.options.all')),
            ],
            'asOf' => $asOf,
            'referenceMethod' => $referenceMethod,
            'period' => $period,
            'products' => $products,
            'stores' => $stores,
            'selectedProduct' => $selectedProduct,
            'selectedStore' => $selectedStore,
            'comparison' => $comparison,
            'comparisonError' => $comparisonError,
            'numbers' => $this->numbers,
        ];
    }

    public function stockBalances(StockBalanceInquiryRequest $request): View
    {
        [$context, $filters, $options, $report] = $this->stockBalanceReport($request);
        $report['rows']->each->makeHidden(['inventory_value']);

        return view('modules.inventory.stock-balances.index', [
            'context' => $context,
            'filters' => $filters,
            'options' => $options,
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'reservationsAreHallScoped' => $report['reservations_are_hall_scoped'],
            'canViewFinancial' => false,
            'filtersExpanded' => ! $request->boolean('run') || collect($request->except(['run', 'as_of']))->filter(fn ($value) => filled($value))->isNotEmpty(),
        ]);
    }

    public function stockBalancesExport(StockBalanceInquiryRequest $request): BinaryFileResponse
    {
        [, , , $report] = $this->stockBalanceReport($request);

        return Excel::download(
            new StockBalanceInquiryExport($report['rows'], $report['totals']),
            'stock-balance-inquiry-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function stockBalancesPrint(StockBalanceInquiryRequest $request): Response
    {
        [$context, $filters, $options, $report] = $this->stockBalanceReport($request);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.inventory.stock-balance-inquiry', [
            'title' => __('stock_balance_inquiry.title'),
            'filters' => $filters,
            'filterSummary' => $this->stockBalanceFilterSummary($filters, $options),
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'reservationsAreHallScoped' => $report['reservations_are_hall_scoped'],
            'canViewFinancial' => false,
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
            'printIdentityPolicy' => 'report',
        ], 'stock-balance-inquiry.pdf', 'L');
    }

    public function salesValuation(Request $request): View
    {
        [$context, $filters, $options, $valuation] = $this->salesValuationReport($request, false);

        return view('modules.inventory.reports.sales-valuation', [
            'context' => $context,
            'filters' => $filters,
            'options' => $options,
            'valuation' => $valuation,
            'numbers' => $this->numbers,
        ]);
    }

    public function salesValuationExport(Request $request, string $format = 'xlsx'): BinaryFileResponse
    {
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);
        [, , , $valuation] = $this->salesValuationReport($request, true);

        $extension = $format === 'csv' ? 'csv' : 'xlsx';

        return Excel::download(
            new InventorySalesValuationExport($valuation),
            'inventory-sales-valuation-'.now()->format('Ymd-His').'.'.$extension,
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }

    public function salesValuationPrint(Request $request, ReportPdfService $pdf, CompanyPrintIdentityService $printIdentity): Response
    {
        [$context, $filters, $options, $valuation] = $this->salesValuationReport($request, true);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.inventory.sales-valuation', [
            'title' => __('inventory_accounting.sales_valuation.title'),
            'valuation' => $valuation,
            'filterSummary' => [
                __('stock_balance_inquiry.filters.as_of') => $valuation['asOf'],
                __('inventory_accounting.sales_valuation.price_list') => $valuation['priceList']
                    ? $valuation['priceList']->doc_num
                    : __('stock_balance_inquiry.options.all'),
                __('stock_balance_inquiry.filters.branch') => $options['selected_branch']?->name ?? __('stock_balance_inquiry.options.all'),
            ],
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
            'printIdentityPolicy' => 'report',
            'numbers' => $this->numbers,
        ], 'inventory-sales-valuation.pdf', 'L');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: ?array<string, mixed>} */
    private function salesValuationReport(Request $request, bool $priceListRequired): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Company, financial period, and branch context are required.');
        $filters = $request->validate([
            'as_of' => ['nullable', 'date'], 'branch_doc_num' => ['nullable', 'string'],
            'branch_store_uuid' => ['nullable', 'string'], 'branch_hall_uuid' => ['nullable', 'string'],
            'warehouse_location_uuid' => ['nullable', 'string'], 'product_doc_num' => ['nullable', 'string'],
            'stock_status' => ['nullable', 'string'], 'quantity_state' => ['nullable', 'string', 'in:positive,negative'],
            'price_list_id' => [$priceListRequired ? 'required' : 'nullable', 'integer', Rule::exists('price_lists', 'id')->where(fn ($query) => $query->where('company_id', $context['company_id'])->whereNull('deleted_at'))],
        ]);
        $branches = $this->context->allowedBranchQueryForCurrentCompany($request)->whereIn('branches.type', [Branch::TypeFactory, Branch::TypeWarehouse, Branch::TypeShowroom])->get();
        $branchIds = $branches->modelKeys();
        $stores = BranchStore::query()->whereIn('branch_id', $branchIds ?: [0])->with('branch:id,doc_num,name,type')->orderBy('position')->orderBy('name')->get();
        $halls = BranchHall::query()->whereIn('branch_id', $branchIds ?: [0])->with('branch:id,doc_num,name,type')->orderBy('position')->orderBy('name')->get();
        $locations = WarehouseLocation::query()->whereIn('branch_store_id', $stores->modelKeys() ?: [0])->with('branchStore.branch:id,doc_num,name,type')->orderBy('branch_store_id')->orderBy('position')->orderBy('code')->get();
        $selectedBranch = $this->selectedOption($branches, 'doc_num', $filters['branch_doc_num'] ?? null, 'branch_doc_num');
        $selectedStore = $this->selectedOption($stores, 'public_uuid', $filters['branch_store_uuid'] ?? null, 'branch_store_uuid');
        $selectedHall = $this->selectedOption($halls, 'public_uuid', $filters['branch_hall_uuid'] ?? null, 'branch_hall_uuid');
        $selectedLocation = $this->selectedOption($locations, 'public_id', $filters['warehouse_location_uuid'] ?? null, 'warehouse_location_uuid');
        if ($selectedBranch && $selectedStore && (int) $selectedStore->branch_id !== (int) $selectedBranch->getKey()) {
            throw ValidationException::withMessages(['branch_store_uuid' => __('stock_balance_inquiry.validation.store_branch')]);
        }
        if ($selectedBranch && $selectedHall && (int) $selectedHall->branch_id !== (int) $selectedBranch->getKey()) {
            throw ValidationException::withMessages(['branch_hall_uuid' => __('stock_balance_inquiry.validation.hall_branch')]);
        }
        if ($selectedStore && $selectedLocation && (int) $selectedLocation->branch_store_id !== (int) $selectedStore->getKey()) {
            throw ValidationException::withMessages(['warehouse_location_uuid' => __('stock_balance_inquiry.validation.location_store')]);
        }
        if ($selectedLocation && $selectedBranch && (int) $selectedLocation->branchStore?->branch_id !== (int) $selectedBranch->getKey()) {
            throw ValidationException::withMessages(['warehouse_location_uuid' => __('stock_balance_inquiry.validation.invalid_scope')]);
        }
        if ($selectedStore && $selectedHall && (int) $selectedStore->branch_id !== (int) $selectedHall->branch_id) {
            throw ValidationException::withMessages(['branch_hall_uuid' => __('stock_balance_inquiry.validation.hall_branch')]);
        }
        if ($selectedHall && $selectedLocation && (int) $selectedLocation->branchStore?->branch_id !== (int) $selectedHall->branch_id) {
            throw ValidationException::withMessages(['warehouse_location_uuid' => __('stock_balance_inquiry.validation.invalid_scope')]);
        }
        $queryFilters = [...$filters, 'branch_id' => $selectedBranch?->getKey(), 'branch_store_id' => $selectedStore?->getKey(), 'branch_hall_id' => $selectedHall?->getKey(), 'warehouse_location_id' => $selectedLocation?->getKey()];
        $valuation = filled($filters['price_list_id'] ?? null) ? $this->reports->salesValuation((int) $context['company_id'], $branchIds, $queryFilters) : null;
        $options = [
            'branches' => $branches, 'stores' => $stores, 'halls' => $halls, 'locations' => $locations,
            'selected_branch' => $selectedBranch, 'selected_store' => $selectedStore, 'selected_hall' => $selectedHall, 'selected_location' => $selectedLocation,
            'selected_product' => $this->selectedProduct((int) $context['company_id'], $filters['product_doc_num'] ?? null),
            'selected_lookups' => $this->selectedStockBalanceLookups((int) $context['company_id'], $filters),
            'priceLists' => PriceList::query()->forCompany((int) $context['company_id'])->orderBy('doc_num')->get(['id', 'doc_num', 'currency_id']),
            'selected_price_list' => $valuation['priceList'] ?? null,
            'currencies' => Currency::query()->forCompany((int) $context['company_id'])->active()->get(['id', 'doc_num', 'code', 'name']),
        ];

        return [$context, $filters, $options, $valuation];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function stockBalanceReport(StockBalanceInquiryRequest $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'], 422, 'Company context is required.');

        $filters = $request->validated();
        $branches = $this->context->allowedBranchQueryForCurrentCompany($request)
            ->whereIn('branches.type', [Branch::TypeFactory, Branch::TypeWarehouse, Branch::TypeShowroom])
            ->get();
        $branchIds = $branches->modelKeys();
        $stores = BranchStore::query()
            ->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0])
            ->with('branch:id,doc_num,name,type')
            ->orderBy('position')
            ->orderBy('name')
            ->get();
        $halls = BranchHall::query()
            ->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0])
            ->with('branch:id,doc_num,name,type')
            ->orderBy('position')
            ->orderBy('name')
            ->get();
        $locations = WarehouseLocation::query()
            ->whereIn('branch_store_id', $stores->modelKeys() !== [] ? $stores->modelKeys() : [0])
            ->with('branchStore.branch:id,doc_num,name,type')
            ->orderBy('branch_store_id')
            ->orderBy('position')
            ->orderBy('code')
            ->get();

        $selectedBranch = $this->selectedOption($branches, 'doc_num', $filters['branch_doc_num'] ?? null, 'branch_doc_num');
        $selectedStore = $this->selectedOption($stores, 'public_uuid', $filters['branch_store_uuid'] ?? null, 'branch_store_uuid');
        $selectedHall = $this->selectedOption($halls, 'public_uuid', $filters['branch_hall_uuid'] ?? null, 'branch_hall_uuid');
        $selectedLocation = $this->selectedOption($locations, 'public_id', $filters['warehouse_location_uuid'] ?? null, 'warehouse_location_uuid');

        if ($selectedBranch && $selectedStore && (int) $selectedStore->branch_id !== (int) $selectedBranch->getKey()) {
            throw ValidationException::withMessages(['branch_store_uuid' => __('stock_balance_inquiry.validation.store_branch')]);
        }
        if ($selectedBranch && $selectedHall && (int) $selectedHall->branch_id !== (int) $selectedBranch->getKey()) {
            throw ValidationException::withMessages(['branch_hall_uuid' => __('stock_balance_inquiry.validation.hall_branch')]);
        }
        if ($selectedStore && $selectedLocation && (int) $selectedLocation->branch_store_id !== (int) $selectedStore->getKey()) {
            throw ValidationException::withMessages(['warehouse_location_uuid' => __('stock_balance_inquiry.validation.location_store')]);
        }

        $queryFilters = [
            ...$filters,
            'branch_id' => $selectedBranch?->getKey(),
            'branch_store_id' => $selectedStore?->getKey(),
            'branch_hall_id' => $selectedHall?->getKey(),
            'warehouse_location_id' => $selectedLocation?->getKey(),
        ];
        $report = $this->reports->stockBalanceInquiry((int) $context['company_id'], $branchIds, $queryFilters);
        $options = [
            'branches' => $branches,
            'stores' => $stores,
            'halls' => $halls,
            'locations' => $locations,
            'selected_branch' => $selectedBranch,
            'selected_store' => $selectedStore,
            'selected_hall' => $selectedHall,
            'selected_location' => $selectedLocation,
            'selected_product' => $this->selectedProduct((int) $context['company_id'], $filters['product_doc_num'] ?? null),
            'selected_lookups' => $this->selectedStockBalanceLookups((int) $context['company_id'], $filters),
        ];

        return [$context, $filters, $options, $report];
    }

    private function selectedOption(Collection $options, string $attribute, mixed $value, string $field): mixed
    {
        if (! filled($value)) {
            return null;
        }

        $selected = $options->first(fn ($option): bool => (string) $option->{$attribute} === (string) $value);

        if (! $selected) {
            throw ValidationException::withMessages([$field => __('stock_balance_inquiry.validation.invalid_scope')]);
        }

        return $selected;
    }

    private function selectedProduct(int $companyId, mixed $docNum): ?Product
    {
        if (! filled($docNum)) {
            return null;
        }

        return Product::query()->withTrashed()
            ->where('company_id', $companyId)
            ->where('doc_num', $docNum)
            ->first();
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function selectedStockBalanceLookups(int $companyId, array $filters): array
    {
        $definitions = [
            'item_category_doc_num' => ItemCategory::class,
            'item_group_doc_num' => ItemGroup::class,
            'item_model_doc_num' => ItemModel::class,
            'item_size_doc_num' => ItemSize::class,
            'item_color_doc_num' => ItemColor::class,
            'item_decal_doc_num' => ItemDecal::class,
            'item_unit_doc_num' => ItemUnit::class,
            'item_origin_country_doc_num' => ItemOriginCountry::class,
        ];

        return collect($definitions)->mapWithKeys(function (string $modelClass, string $field) use ($companyId, $filters): array {
            $docNum = $filters[$field] ?? null;
            $record = filled($docNum)
                ? $modelClass::query()->withTrashed()->where('company_id', $companyId)->where('doc_num', $docNum)->first()
                : null;

            return [$field => $record];
        })->all();
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $options @return array<string, string> */
    private function stockBalanceFilterSummary(array $filters, array $options): array
    {
        $summary = [
            __('stock_balance_inquiry.filters.as_of') => (string) $filters['as_of'],
            __('stock_balance_inquiry.filters.branch') => (string) ($options['selected_branch']?->name ?? __('stock_balance_inquiry.options.all')),
            __('stock_balance_inquiry.filters.store') => (string) ($options['selected_store']?->name ?? __('stock_balance_inquiry.options.all')),
            __('stock_balance_inquiry.filters.hall') => (string) ($options['selected_hall']?->name ?? __('stock_balance_inquiry.options.all')),
            __('stock_balance_inquiry.filters.location') => (string) ($options['selected_location']?->name ?? __('stock_balance_inquiry.options.all')),
        ];

        if ($options['selected_product'] instanceof Product) {
            $summary[__('stock_balance_inquiry.filters.product')] = $options['selected_product']->doc_num.' — '.$options['selected_product']->name;
        }

        foreach ($options['selected_lookups'] as $field => $lookup) {
            if ($lookup) {
                $summary[__('stock_balance_inquiry.filters.'.$field)] = $lookup->doc_num.' — '.$lookup->name;
            }
        }

        if (filled($filters['item_classification'] ?? null)) {
            $summary[__('stock_balance_inquiry.filters.item_classification')] = __('products.classifications.'.$filters['item_classification']);
        }
        if (filled($filters['stock_status'] ?? null)) {
            $summary[__('stock_balance_inquiry.filters.stock_status')] = __('stock_balance_inquiry.stock_statuses.'.$filters['stock_status']);
        }

        return $summary;
    }

    /** @return array{0: array<string, mixed>, 1: bool, 2: array<string, mixed>} */
    private function report(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Company, financial period, and branch context are required.');
        $report = $this->reports->report(
            $context['company_id'],
            $context['financial_period_id'],
            $context['branch_id'],
            $request->only(['source_doc_num', 'branch_store_id', 'warehouse_location_id', 'product_id', 'classification', 'stock_status', 'batch_lot', 'transaction_type', 'from', 'to', 'as_of', 'expiry_within_days']),
        );
        $report['glReconciliation'] = null;
        $report['glReconciliationUnavailableReason'] = null;

        return [$context, false, $report];
    }
}
