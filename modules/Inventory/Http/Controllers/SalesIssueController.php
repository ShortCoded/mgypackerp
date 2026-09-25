<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Models\BranchStore;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\Inventory\Http\Requests\StoreSalesIssueRequest;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Services\SalesIssueOrderService;

class SalesIssueController extends Controller
{
    public function __construct(private readonly OperatingContextService $context) {}

    public function create(Request $request): View
    {
        $context = $this->requiredContext($request);
        $selected = null;
        if ($request->filled('issue_order')) {
            $selected = SalesIssueOrder::query()->with('branchStore')
                ->where('company_id', $context['company_id'])
                ->where('status', SalesIssueOrder::StatusPending)
                ->where('doc_num', $request->string('issue_order')->toString())
                ->firstOrFail();
        }

        return view('modules.inventory.documents.sales-issue', ['selectedOrder' => $selected]);
    }

    public function orders(Request $request, DataTableSearchService $search, Select2ResponseService $select2): JsonResponse
    {
        $context = $this->requiredContext($request);
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
        abort_unless((int) $salesIssueOrder->company_id === $context['company_id']
            && $salesIssueOrder->status === SalesIssueOrder::StatusPending
            && ($salesIssueOrder->branch_store_id === null || (int) $salesIssueOrder->branch_store_id === (int) $store->getKey()), 404);

        $invoice = $salesIssueOrder->invoice->load(['lines.product', 'lines.unit', 'deliveries.lines']);
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

        $lines = collect($remaining)->map(function (array $row) use (&$freeByProduct, &$reservedBySource, $numbers): array {
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
            $issue = $issues->issue($order, $store, $request->validated('document_date'));
        } catch (DomainException $exception) {
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
        return BranchStore::query()
            ->where('branch_id', $context['branch_id'])
            ->where('public_uuid', $request->string('branch_store_uuid')->toString())
            ->firstOrFail();
    }
}
