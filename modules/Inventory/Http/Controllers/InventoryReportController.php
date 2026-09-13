<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
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
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Inventory\Exports\InventoryReportExport;
use Modules\Inventory\Exports\StockBalanceInquiryExport;
use Modules\Inventory\Http\Requests\StockBalanceInquiryRequest;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryReportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryReportController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly InventoryReportService $reports,
        private readonly InventoryGlReconciliationService $reconciliation,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request): View
    {
        [$context, $canViewFinancial, $report] = $this->report($request);
        $balances = $report['balances'];

        if (! $canViewFinancial) {
            $balances->each->makeHidden(['inventory_value', 'unvalued_receipt_quantity']);
        }

        return view('modules.inventory.reports.index', [
            ...$report,
            'balances' => $balances,
            'canViewFinancial' => $canViewFinancial,
            'agingSupported' => true,
            'expirySupported' => true,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [, $canViewFinancial, $report] = $this->report($request);

        return Excel::download(
            new InventoryReportExport($report, $canViewFinancial),
            'inventory-operations-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function print(Request $request): Response
    {
        [$context, $canViewFinancial, $report] = $this->report($request);
        $company = Company::query()->findOrFail($context['company_id']);

        return $this->pdf->stream('reports.inventory.operations', [
            ...$report,
            'canViewFinancial' => $canViewFinancial,
            'title' => __('Inventory Operations Report'),
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
        ], 'inventory-operations-report.pdf');
    }

    public function stockBalances(StockBalanceInquiryRequest $request): View
    {
        [$context, $filters, $options, $report] = $this->stockBalanceReport($request);
        $canViewFinancial = (bool) $request->user()?->can('inventory.reports.financial');

        if (! $canViewFinancial) {
            $report['rows']->each->makeHidden(['inventory_value']);
        }

        return view('modules.inventory.stock-balances.index', [
            'context' => $context,
            'filters' => $filters,
            'options' => $options,
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'reservationsAreHallScoped' => $report['reservations_are_hall_scoped'],
            'canViewFinancial' => $canViewFinancial,
            'filtersExpanded' => ! $request->boolean('run') || collect($request->except(['run', 'as_of']))->filter(fn ($value) => filled($value))->isNotEmpty(),
        ]);
    }

    public function stockBalancesExport(StockBalanceInquiryRequest $request): BinaryFileResponse
    {
        [, , , $report] = $this->stockBalanceReport($request);
        $canViewFinancial = (bool) $request->user()?->can('inventory.reports.financial');

        return Excel::download(
            new StockBalanceInquiryExport($report['rows'], $report['totals'], $canViewFinancial),
            'stock-balance-inquiry-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function stockBalancesPrint(StockBalanceInquiryRequest $request): Response
    {
        [$context, $filters, $options, $report] = $this->stockBalanceReport($request);
        $company = Company::query()->findOrFail($context['company_id']);
        $canViewFinancial = (bool) $request->user()?->can('inventory.reports.financial');

        return $this->pdf->stream('reports.inventory.stock-balance-inquiry', [
            'title' => __('stock_balance_inquiry.title'),
            'filters' => $filters,
            'filterSummary' => $this->stockBalanceFilterSummary($filters, $options),
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'reservationsAreHallScoped' => $report['reservations_are_hall_scoped'],
            'canViewFinancial' => $canViewFinancial,
            'companyPrintIdentity' => $this->printIdentity->forCompany($company),
            'printIdentityPolicy' => 'report',
        ], 'stock-balance-inquiry.pdf', 'L');
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
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
        $canViewFinancial = (bool) $request->user()?->can('inventory.reports.financial');
        $report = $this->reports->report(
            $context['company_id'],
            $context['financial_period_id'],
            $context['branch_id'],
            $request->only(['source_doc_num', 'branch_store_id', 'warehouse_location_id', 'product_id', 'classification', 'stock_status', 'batch_lot', 'transaction_type', 'from', 'to', 'as_of', 'expiry_within_days']),
        );
        $report['glReconciliation'] = null;
        $report['glReconciliationUnavailableReason'] = null;

        if ($canViewFinancial) {
            try {
                $report['glReconciliation'] = $this->reconciliation->reconcile(
                    $context['company_id'],
                    $context['financial_period_id'],
                    $context['branch_id'],
                );
            } catch (DomainException $exception) {
                $report['glReconciliationUnavailableReason'] = $exception->getMessage();
            }
        }

        return [$context, $canViewFinancial, $report];
    }
}
