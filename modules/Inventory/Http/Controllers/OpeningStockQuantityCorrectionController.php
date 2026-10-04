<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Http\Requests\OpeningStockQuantityCorrectionRequest;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockLine;
use Modules\Inventory\Models\OpeningStockQuantityCorrection;
use Modules\Inventory\Services\OpeningStockQuantityCorrectionService;

class OpeningStockQuantityCorrectionController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly OperatingScopeAccessService $access,
        private readonly OpeningStockQuantityCorrectionService $corrections,
    ) {}

    public function index(Request $request): View
    {
        $scope = $this->scope($request);
        $records = OpeningStock::query()->where('company_id', $scope['company_id'])->where('branch_id', $scope['branch_id'])
            ->where('approved', true)->where('status', OpeningStock::StatusApproved)
            ->whereIn('financial_period_id', $this->access->allowedFinancialPeriodQuery($request->user())->select('financial_periods.id'))
            ->latest('document_date')->latest('id')->paginate(25);

        return view('modules.inventory.opening-stock-quantity-corrections.index', compact('records'));
    }

    public function show(Request $request, OpeningStock $openingStock): View
    {
        $this->assertSource($request, $openingStock);
        $record = $openingStock->load('lines.product');
        $proposals = OpeningStockQuantityCorrection::query()->where('company_id', $record->company_id)
            ->where('opening_stock_id', $record->id)->with(['preparedBy', 'approvedBy'])->latest('id')->get();
        $selectedLayers = [];
        $selectedSerialLayers = [];
        $oldPostingDate = old('posting_date', now()->toDateString());
        $postingDate = is_string($oldPostingDate) ? app(DateFormatService::class)->parseDate($oldPostingDate)?->toDateString() : null;
        foreach ($record->lines as $line) {
            $serialIds = old('targets.'.$line->id.'.serial_receipt_layer_ids', []);
            $serialIds = is_array($serialIds) ? array_values(array_filter(array_map(
                fn ($value) => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
                array_slice($serialIds, 0, 10000),
            ))) : [];
            $selectedSerialLayers[$line->id] = $serialIds !== [] ? $this->layerQuery($record, $line)
                ->with('serialIdentity')->whereDate('original_receipt_date', '<=', $postingDate ?? now()->toDateString())
                ->whereKey($serialIds)->get() : collect();
            $selected = filter_var(old('targets.'.$line->id.'.selected_receipt_layer_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $selectedLayers[$line->id] = $selected ? $this->layerQuery($record, $line)
                ->whereDate('original_receipt_date', '<=', $postingDate ?? now()->toDateString())->whereKey($selected)->first() : null;
        }

        return view('modules.inventory.opening-stock-quantity-corrections.show', compact('record', 'proposals', 'selectedLayers', 'selectedSerialLayers'));
    }

    public function layers(Request $request, OpeningStock $openingStock, OpeningStockLine $line, Select2ResponseService $select2): JsonResponse
    {
        $this->assertSource($request, $openingStock);
        abort_unless((int) $line->opening_stock_id === (int) $openingStock->id, 404);
        $postingDate = app(DateFormatService::class)->parseDate((string) $request->input('posting_date'))?->toDateString();
        abort_unless($postingDate !== null, 422);
        $query = $this->layerQuery($openingStock, $line)->with('serialIdentity')->whereDate('original_receipt_date', '<=', $postingDate)
            ->when($request->filled('q'), fn ($query) => $query
                ->where(fn ($query) => $query->where('source_doc_num', 'like', '%'.trim((string) $request->input('q')).'%')
                    ->orWhereHas('serialIdentity', fn ($serials) => $serials->where('serial_number', 'like', '%'.trim((string) $request->input('q')).'%'))))->orderBy('original_receipt_date')->orderBy('id');
        $numbers = app(NumericFormatService::class);

        return response()->json($select2->paginated($query, $request, fn (InventoryReceiptLayer $layer): array => [
            'id' => (string) $layer->id, 'text' => ($layer->serialIdentity?->serial_number ? $layer->serialIdentity->serial_number.' · ' : '').$layer->source_doc_num.' · '.__('opening_stock_quantity_correction.available')
                .': '.$numbers->format($layer->remaining_quantity),
        ]));
    }

    public function prepare(OpeningStockQuantityCorrectionRequest $request, OpeningStock $openingStock): RedirectResponse
    {
        $this->assertSource($request, $openingStock);
        $data = $request->validated();
        $this->guard(fn () => $this->corrections->prepare($request, $openingStock, $data, $data['targets']));

        return to_route('admin.inventory.opening-stock-quantity-corrections.show', $openingStock)
            ->with('success', __('opening_stock_quantity_correction.messages.prepared'));
    }

    public function approve(Request $request, OpeningStock $openingStock, OpeningStockQuantityCorrection $quantityCorrection): RedirectResponse
    {
        $this->assertSource($request, $openingStock);
        $data = $request->validate(['approval_reference' => ['required', 'string', 'min:5', 'max:255']]);
        $this->guard(fn () => $this->corrections->approve($request, $openingStock, $quantityCorrection, $data['approval_reference']));

        return to_route('admin.inventory.opening-stock-quantity-corrections.show', $openingStock)
            ->with('success', __('opening_stock_quantity_correction.messages.approved'));
    }

    public function reject(Request $request, OpeningStock $openingStock, OpeningStockQuantityCorrection $quantityCorrection): RedirectResponse
    {
        $this->assertSource($request, $openingStock);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->guard(fn () => $this->corrections->reject($request, $openingStock, $quantityCorrection, $data['reason']));

        return to_route('admin.inventory.opening-stock-quantity-corrections.show', $openingStock)
            ->with('success', __('opening_stock_quantity_correction.messages.rejected'));
    }

    /** @return Builder<InventoryReceiptLayer> */
    private function layerQuery(OpeningStock $source, OpeningStockLine $line): Builder
    {
        $root = InventoryTransaction::query()->where('company_id', $source->company_id)->where('source_type', OpeningStock::class)
            ->where('source_id', $source->id)->where('posting_key', "opening-stock:{$source->id}:line:{$line->id}")->first();
        abort_unless($root !== null, 404);

        return InventoryReceiptLayer::query()->where('company_id', $source->company_id)->where('branch_id', $source->branch_id)
            ->where('branch_store_id', $source->branch_store_id)->where('product_id', $line->product_id)
            ->where('unit_id', $root->unit_id)->where('stock_status', $root->stock_status)
            ->where('batch_lot', $root->batch_lot)->where('warehouse_location_id', $root->warehouse_location_id)
            ->where('remaining_quantity', '>', 0)
            ->when($line->product?->tracks_serials, fn ($query) => $query->where('remaining_quantity', '1')
                ->whereHas('serialIdentity', fn ($identities) => $identities->whereColumn('inventory_serial_identities.current_receipt_layer_id', 'inventory_receipt_layers.id')),
                fn ($query) => $query->whereNull('inventory_serial_identity_id'));
    }

    /** @return array{company_id: int, branch_id: int, financial_period_id: int} */
    private function scope(Request $request): array
    {
        abort_unless($request->user()?->canAny(['inventory.opening_stock_quantity_corrections.prepare', 'inventory.opening_stock_quantity_corrections.approve']), 403);
        $scope = $this->context->snapshot($request);
        abort_unless($scope['company_id'] && $scope['branch_id'] && $scope['financial_period_id'], 422);
        abort_unless($this->context->allowedBranchQueryForCurrentCompany($request)->whereKey($scope['branch_id'])->exists(), 403);
        abort_unless($this->access->allowedFinancialPeriodQuery($request->user())->where('financial_periods.company_id', $scope['company_id'])
            ->whereKey($scope['financial_period_id'])->exists(), 403);

        return $scope;
    }

    private function assertSource(Request $request, OpeningStock $source): void
    {
        $scope = $this->scope($request);
        abort_unless((int) $source->company_id === $scope['company_id'] && (int) $source->branch_id === $scope['branch_id']
            && $this->access->allowedFinancialPeriodQuery($request->user())->whereKey($source->financial_period_id)->exists(), 404);
    }

    private function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $error) {
            throw ValidationException::withMessages(['opening_stock_quantity' => $error->getMessage()]);
        }
    }
}
