<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Sales\Http\Requests\StoreSalesDeliveryReceiptRequest;
use Modules\Sales\Models\SalesDeliveryReceipt;
use Modules\Sales\Services\SalesDeliveryReceiptService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesDeliveryReceiptController extends Controller
{
    public function __construct(private readonly OperatingContextService $context) {}

    public function create(Request $request, InventoryDocument $inventoryDocument): View
    {
        $this->assertIssueContext($request, $inventoryDocument);
        abort_unless($inventoryDocument->document_type === InventoryDocument::TypeSalesDelivery
            && $inventoryDocument->status === InventoryDocument::StatusPosted
            && $inventoryDocument->sales_issue_order_id !== null
            && ! $inventoryDocument->customerDeliveryReceipt()->exists(), 404);

        return view('modules.sales.issue-orders.receipt-form', ['issue' => $inventoryDocument->load('salesIssueOrder.invoice.customer')]);
    }

    public function store(StoreSalesDeliveryReceiptRequest $request, InventoryDocument $inventoryDocument, SalesDeliveryReceiptService $receipts): RedirectResponse
    {
        $this->assertIssueContext($request, $inventoryDocument);

        try {
            $receipt = $receipts->record($inventoryDocument, $request->safe()->except('signature'), $request->file('signature'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['signature' => $exception->getMessage()]);
        }

        return redirect()->route('admin.sales.delivery-receipts.show', $receipt)->with('success', __('sales_issue.messages.receipt_recorded'));
    }

    public function show(Request $request, SalesDeliveryReceipt $salesDeliveryReceipt): View
    {
        $this->assertReceiptContext($request, $salesDeliveryReceipt);

        return view('modules.sales.issue-orders.receipt-show', ['receipt' => $salesDeliveryReceipt->load(['invoice.customer', 'stockIssue.lines.product', 'stockIssue.lines.transactionUnit'])]);
    }

    public function signature(Request $request, SalesDeliveryReceipt $salesDeliveryReceipt): StreamedResponse
    {
        $this->assertReceiptContext($request, $salesDeliveryReceipt);
        abort_unless(Storage::disk('local')->exists($salesDeliveryReceipt->signature_path), 404);

        return Storage::disk('local')->download($salesDeliveryReceipt->signature_path, $salesDeliveryReceipt->doc_num.'.'.pathinfo($salesDeliveryReceipt->signature_path, PATHINFO_EXTENSION));
    }

    private function assertIssueContext(Request $request, InventoryDocument $issue): void
    {
        $context = $this->context->snapshot($request);
        $issueOrder = $issue->salesIssueOrder;
        abort_unless($context['company_id'] && $context['branch_id']
            && (int) $issue->company_id === (int) $context['company_id']
            && $issueOrder !== null
            && (int) $issueOrder->branch_id === (int) $context['branch_id'], 404);
    }

    private function assertReceiptContext(Request $request, SalesDeliveryReceipt $receipt): void
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['branch_id']
            && (int) $receipt->company_id === (int) $context['company_id']
            && (int) $receipt->branch_id === (int) $context['branch_id'], 404);
    }
}
