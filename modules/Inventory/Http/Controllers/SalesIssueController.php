<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Http\Requests\StoreSalesIssueRequest;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Services\SalesIssueOrderService;

class SalesIssueController extends Controller
{
    public function __construct(private readonly OperatingContextService $context) {}

    public function create(Request $request): View
    {
        $context = $this->requiredContext($request);
        $selected = null;
        $orderNumber = $request->old('sales_issue_order_doc_num', $request->input('issue_order'));
        if (is_string($orderNumber) && $orderNumber !== '') {
            $selected = SalesIssueOrder::query()->with(['branchStore', 'invoice.lines.product'])
                ->where('company_id', $context['company_id'])
                ->where('status', SalesIssueOrder::StatusPending)
                ->where('doc_num', $orderNumber)
                ->first();
        }
        $storeUuid = $request->old('branch_store_uuid', $selected?->branchStore?->public_uuid);
        $store = is_string($storeUuid) ? BranchStore::query()->where('branch_id', $context['branch_id'])->where('public_uuid', $storeUuid)->first() : null;

        return view('modules.inventory.documents.sales-issue', [
            'selectedOrder' => $selected, 'selectedStore' => $store,
            'oldLayerSelections' => $this->oldLayerSelections($request, $selected, $store),
        ]);
    }

    /** @return array<int, list<array{layer_id: int, quantity: string, text: string}>> */
    private function oldLayerSelections(Request $request, ?SalesIssueOrder $order, ?BranchStore $store): array
    {
        $old = $request->old('layer_selections', []);
        if ($order === null || $store === null || ! is_array($old)) {
            return [];
        }
        $rows = collect($old)->filter(fn ($row): bool => is_array($row))->take(100);
        $layerIds = $rows->flatMap(fn (array $row): array => is_array($row['receipt_layers'] ?? null) ? array_slice($row['receipt_layers'], 0, 100) : [])
            ->filter(fn ($row): bool => is_array($row))->pluck('layer_id')->filter(fn ($id): bool => is_scalar($id) && ctype_digit((string) $id))->unique()->all();
        $lines = $order->invoice->lines->keyBy('id');
        $date = app(DateFormatService::class)->normalizeForStorage($request->old('document_date')) ?? now()->toDateString();
        $policyId = app(InventoryCostPolicyService::class)->resolve((int) $order->company_id, (int) $store->id, $date)['policy_id'];
        $layers = InventoryReceiptLayer::query()->with('receiptTransaction')
            ->withBookCostBasis($policyId)
            ->where('company_id', $order->company_id)->where('branch_store_id', $store->id)->where('stock_status', 'available')
            ->whereIn('product_id', $lines->pluck('product_id'))->whereIn('id', $layerIds)->get()->keyBy('id');
        $numbers = app(NumericFormatService::class);
        $result = [];
        foreach ($rows as $row) {
            $lineId = $row['invoice_line_id'] ?? null;
            $line = is_scalar($lineId) && ctype_digit((string) $lineId) ? $lines->get($lineId) : null;
            if ($line === null || isset($result[$line->id]) || ! is_array($row['receipt_layers'] ?? null)) {
                continue;
            }
            foreach (array_slice($row['receipt_layers'], 0, 100) as $slice) {
                $layerId = is_array($slice) ? ($slice['layer_id'] ?? null) : null;
                $layer = is_scalar($layerId) && ctype_digit((string) $layerId) ? $layers->get($layerId) : null;
                if ($layer === null || (int) $layer->product_id !== (int) $line->product_id || ! is_scalar($slice['quantity'] ?? null) || ! is_numeric($slice['quantity'])) {
                    continue;
                }
                $result[$line->id][] = ['layer_id' => $layer->id, 'quantity' => (string) $slice['quantity'],
                    'text' => $layer->receiptTransaction?->source_doc_num.' — '.($layer->batch_lot ?? __('inventory_cost_policy.no_batch')).' — '.$numbers->format($layer->remaining_quantity).' — '.$numbers->format($layer->bookUnitCostForPolicy($policyId))];
            }
        }

        return $result;
    }

    public function orders(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $context = $this->requiredContext($request);
        if (! $request->filled('branch_store_uuid')) {
            return response()->json(['results' => [], 'pagination' => ['more' => false]]);
        }

        $store = $this->storeForContext($request, $context);
        $query = SalesIssueOrder::query()->with('invoice.customer')
            ->where('company_id', $context['company_id'])
            ->where('status', SalesIssueOrder::StatusPending)
            ->where(fn ($query) => $query->whereNull('branch_store_id')->orWhere('branch_store_id', $store->getKey()))
            ->orderByDesc('id');
        $terms = $search->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $search->applyMultiTermSearch($query, $terms, ['text' => ['doc_num']]);
        }

        return response()->json($select2->paginated($query, $request, fn (SalesIssueOrder $order): array => [
            'id' => $order->doc_num,
            'text' => $order->doc_num.' — '.$order->invoice?->doc_num.' — '.$order->invoice?->customer?->name,
        ]));
    }

    public function details(
        Request $request,
        SalesIssueOrder $salesIssueOrder,
        SalesIssueOrderService $issues,
        InventoryAvailabilityService $availability,
        NumericFormatService $numbers,
    ): JsonResponse {
        $context = $this->requiredContext($request);
        $store = $this->storeForContext($request, $context);
        $request->validate(['document_date' => ['nullable', 'string', 'max:50']]);
        $documentDate = app(DateFormatService::class)->normalizeForStorage($request->input('document_date')) ?? now()->toDateString();
        abort_unless((int) $salesIssueOrder->company_id === $context['company_id']
            && $salesIssueOrder->status === SalesIssueOrder::StatusPending
            && ($salesIssueOrder->branch_store_id === null || (int) $salesIssueOrder->branch_store_id === (int) $store->getKey()), 404);

        $invoice = $salesIssueOrder->invoice->load(['lines.product.unit', 'lines.unit', 'deliveries.lines']);
        $remaining = $issues->remainingLines($invoice);
        $productIds = [];
        $sourceLineIds = [];
        foreach ($remaining as $row) {
            $line = $row['line'];
            $productIds[] = (int) $line->product_id;
            if ($line->sales_order_line_id !== null) {
                $sourceLineIds[] = (int) $line->sales_order_line_id;
            }
        }

        $reservations = InventoryReservation::query()
            ->where('company_id', $invoice->company_id)
            ->where('branch_store_id', $store->getKey())
            ->where('status', InventoryReservation::StatusActive)
            ->where('stock_status', InventoryTransaction::StatusAvailable)
            ->whereIn('sales_order_line_id', array_unique($sourceLineIds))
            ->selectRaw('product_id, sales_order_line_id, coalesce(sum(quantity - consumed_quantity - released_quantity), 0) as reserved_quantity')
            ->groupBy('product_id', 'sales_order_line_id')
            ->get();
        $reservedBySource = [];
        foreach ($reservations as $reservation) {
            $reservedBySource[(int) $reservation->product_id][(int) $reservation->sales_order_line_id] = (string) $reservation->reserved_quantity;
        }
        $freeByProduct = [];
        foreach (array_unique($productIds) as $productId) {
            $stock = $availability->forProduct((int) $invoice->company_id, (int) $store->getKey(), $productId);
            $freeByProduct[$productId] = $stock['available'];
        }

        $specificCost = app(InventoryCostPolicyService::class)->resolve((int) $salesIssueOrder->company_id, (int) $store->id, $documentDate)['method'] === InventoryCostPolicy::SpecificIdentification;
        $lines = collect($remaining)->map(function (array $row) use (&$freeByProduct, &$reservedBySource, $numbers, $specificCost): array {
            $line = $row['line'];
            $productId = (int) $line->product_id;
            $sourceId = (int) $line->sales_order_line_id;
            $reserved = $reservedBySource[$productId][$sourceId] ?? '0';
            $free = $freeByProduct[$productId];
            $available = bcadd($free, $reserved, 8);
            $required = bcmul($row['remaining'], (string) $line->conversion_factor, 8);
            $fromReservation = bccomp($reserved, $required, 8) >= 0 ? $required : $reserved;
            $fromFree = bcsub($required, $fromReservation, 8);
            $enough = bccomp($free, '0', 8) >= 0 && bccomp($fromFree, $free, 8) <= 0;

            if ($sourceId > 0) {
                $reservedBySource[$productId][$sourceId] = bcsub($reserved, $fromReservation, 8);
            }
            $freeByProduct[$productId] = bccomp($fromFree, $free, 8) >= 0 ? '0' : bcsub($free, $fromFree, 8);

            return [
                'invoice_line_id' => $line->id,
                'product_doc_num' => $line->product?->doc_num,
                'tracks_serials' => (bool) $line->product?->tracks_serials,
                'requires_specific_layer' => $specificCost || $line->product?->tracks_serials,
                'base_quantity' => $required,
                'base_quantity_display' => $numbers->format($required),
                'base_unit' => $line->product?->unit?->name,
                'product' => trim($line->product?->doc_num.' — '.$line->product?->name, ' —'),
                'quantity' => $numbers->format($row['remaining']),
                'unit' => $line->unit?->name,
                'available' => $numbers->format(bcdiv($available, (string) $line->conversion_factor, 8)),
                'enough' => $enough,
            ];
        })->values();

        return response()->json(['data' => [
            'order' => $salesIssueOrder->doc_num,
            'invoice' => $invoice->doc_num,
            'lines' => $lines,
            'can_issue' => $lines->isNotEmpty() && $lines->every(fn (array $line): bool => $line['enough']),
            'requires_specific_layer' => $lines->contains(fn (array $line): bool => $line['requires_specific_layer']),
        ]]);
    }

    public function store(StoreSalesIssueRequest $request, SalesIssueOrderService $issues): RedirectResponse|JsonResponse
    {
        $context = $this->requiredContext($request);
        $order = SalesIssueOrder::query()
            ->where('company_id', $context['company_id'])
            ->where('doc_num', $request->validated('sales_issue_order_doc_num'))
            ->firstOrFail();
        $store = $this->storeForContext($request, $context);

        try {
            $choices = collect($request->validated('layer_selections', []))->mapWithKeys(fn (array $line): array => [$line['invoice_line_id'] => $line['receipt_layers']])->all();
            $issue = $issues->issue($order, $store, $request->validated('document_date'), $choices);
        } catch (DomainException $exception) {
            if ($request->expectsJson()) {
                throw ValidationException::withMessages(['layer_selections' => $exception->getMessage()]);
            }

            return back()->withInput()->withErrors(['sales_issue_order_doc_num' => $exception->getMessage()]);
        }

        $url = route('admin.inventory.documents.show', $issue);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'doc_num' => $issue->doc_num, 'url' => $url], 201)
            : redirect($url)->with('success', __('sales_issue.messages.issued'));
    }

    /** @return array{company_id: int, branch_id: int, financial_period_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id'] && $context['financial_period_id'], 422);

        return ['company_id' => (int) $context['company_id'], 'branch_id' => (int) $context['branch_id'], 'financial_period_id' => (int) $context['financial_period_id']];
    }

    /** @param array{company_id: int, branch_id: int, financial_period_id: int} $context */
    private function storeForContext(Request $request, array $context): BranchStore
    {
        $uuid = trim($request->string('branch_store_uuid')->toString());
        if (! Str::isUuid($uuid)) {
            throw ValidationException::withMessages(['branch_store_uuid' => __('inventory.movements.messages.store_invalid')]);
        }

        $store = BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->where('public_uuid', $uuid)
            ->first();

        if (! $store instanceof BranchStore) {
            throw ValidationException::withMessages(['branch_store_uuid' => __('inventory.movements.messages.store_invalid')]);
        }

        return $store;
    }
}
