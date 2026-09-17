<?php

namespace Modules\Purchases\Services\Reports;

use App\Models\User;
use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\GoodsReceiptInspectionLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderDeliverySchedule;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseRequisitionLine;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\PurchaseReturnLine;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Models\SupplyOrderLine;

class ProcurementCycleReport
{
    public const SupplierStatement = 'supplier_statement';

    public const PurchaseLedger = 'purchase_ledger';

    public function __construct(private readonly PostingAccountResolver $accounts) {}

    /** @return array<string, mixed> */
    public function supplierOverview(Supplier $supplier): array
    {
        $context = app(OperatingContextService::class)->snapshot(request());
        abort_unless((int) $supplier->company_id === (int) $context['company_id'], 404);
        $scope = fn ($query) => $query->where('company_id', $supplier->company_id)->where('branch_id', $context['branch_id'])->where('supplier_id', $supplier->id);
        $sections = [];
        $definitions = [
            [SupplierQuotation::class, 'Supplier Quotations', 'purchases.supplier_quotation_entry.view', 'admin.purchases.supplier-quotation-entry.show'],
            [PurchaseOrder::class, 'Purchase Orders', 'purchase_orders.view', 'admin.purchases.purchase-orders.show'],
            [SupplyOrder::class, 'Supply Orders', 'purchases.supply_orders.view', 'admin.purchases.supply-orders.show'],
            [UnpricedInventoryReceipt::class, 'Goods Receipts', 'purchases.goods_receipt_notes.view', 'admin.purchases.goods-receipt-notes.show'],
            [PurchaseInvoice::class, 'Purchase Invoices', 'purchase_invoices.view', 'admin.purchases.purchase-invoices.show'],
            [PurchaseReturn::class, 'Purchase Returns', 'purchases.purchase_returns.view', 'admin.purchases.purchase-returns.show'],
            [SupplierPaymentContext::class, 'Supplier Payments', 'supplier_payments.view', 'admin.purchases.supplier-payments.show'],
        ];
        foreach ($definitions as [$model, $label, $permission, $route]) {
            if (! auth()->user()?->can($permission)) {
                continue;
            }
            $records = $scope($model::query())->latest('id')->limit(25)->get();
            $sections[] = compact('label', 'records', 'route');
        }
        $openOrders = auth()->user()?->can('purchase_orders.view') ? $scope(PurchaseOrder::query())->where('status', 'approved')->with(['lines' => fn ($query) => $query->withQuantityProgress()->with('product')])->get()->filter(fn ($order) => in_array($order->fulfillmentStatus(), ['approved', 'sent', 'partially_received'], true))->count() : null;
        $cheques = auth()->user()?->can('cheques.view') && auth()->user()?->can('supplier_payments.view')
            ? $scope(SupplierPaymentContext::query())->whereNotNull('cheque_id')->with('cheque.bankAccount')->latest('id')->limit(25)->get()->pluck('cheque')->filter() : collect();
        $metrics = [];
        if (auth()->user()?->can('purchase_invoices.view') && auth()->user()?->can('purchases.prices.view')) {
            $overdue = PurchaseInvoicePaymentSchedule::query()->where('company_id', $supplier->company_id)
                ->whereDate('due_date', '<', today())->whereNotIn('status', ['paid', 'settled', 'cancelled'])
                ->whereHas('purchaseInvoice', fn ($query) => $scope($query)->whereIn('status', ['approved', 'closed']))
                ->with('purchaseInvoice:id,currency_id')->get()->groupBy('purchaseInvoice.currency_id')
                ->map(fn ($group) => $group->sum(fn ($schedule) => max(0, (float) $schedule->amount - (float) $schedule->paid_amount)));
            $invoices = $scope(PurchaseInvoice::query())->whereIn('status', ['approved', 'closed']);
            $metrics = $invoices->selectRaw('currency_id, sum(total_amount) as purchases, sum(remaining_amount) as outstanding, max(invoice_date) as last_purchase_date')
                ->groupBy('currency_id')->with('currency')->get()->map(fn ($row): array => [
                    'currency' => $row->currency?->code, 'purchases' => $row->purchases, 'outstanding' => $row->outstanding, 'overdue' => $overdue[$row->currency_id] ?? 0, 'last_purchase_date' => $row->last_purchase_date,
                ])->all();
        }

        $attachments = collect();
        if (auth()->user()?->can('file_manager.view')) {
            foreach ($sections as $section) {
                $records = $section['records'];
                if ($records->isEmpty()) {
                    continue;
                }
                $attachments = $attachments->merge(ArchiveFileUsage::query()
                    ->where('usable_type', $records->first()->getMorphClass())->whereIn('usable_id', $records->modelKeys())
                    ->where('company_attachable_id', $supplier->company_id)
                    ->whereHas('file', fn ($query) => $query->where('attachable_type', (new Company)->getMorphClass())->where('attachable_id', $supplier->company_id))
                    ->with('file')->get());
            }
            $attachments = $attachments->unique('archive_file_id')->values();
        }

        return ['attachments' => $attachments, 'sections' => $sections, 'metrics' => $metrics, 'open_orders' => $openOrders, 'cheques' => $cheques, 'supplier_doc_num' => $supplier->doc_num];
    }

    public const OpenRequirements = 'open_requirements';

    public const PurchaseOrderStatus = 'purchase_order_status';

    public const RequestedVsOrdered = 'requested_vs_ordered';

    public const RfqQuotationStatus = 'rfq_quotation_status';

    public const PendingSourcingActions = 'pending_sourcing_actions';

    public const OrderedVsReceived = 'ordered_vs_received';

    public const OverduePoDeliveries = 'overdue_po_deliveries';

    public const DeliverySchedule = 'delivery_schedule';

    public const ReceiptQualityStatus = 'receipt_quality_status';

    public const IncomingQcPending = 'incoming_qc_pending';

    public const QcRejection = 'qc_rejection';

    public const PurchasesBySupplier = 'purchases_by_supplier';

    public const PurchasesByProduct = 'purchases_by_product';

    public const PurchasesByPeriod = 'purchases_by_period';

    public const OutstandingSupplierInvoices = 'outstanding_supplier_invoices';

    public const SupplierPayables = self::OutstandingSupplierInvoices;

    public const DueSupplierInstallments = 'due_supplier_installments';

    public const SupplierAging = 'supplier_aging';

    public const UpcomingSupplierPayments = 'upcoming_supplier_payments';

    public const Returns = 'returns';

    public const ProductionAnalysis = 'production_analysis';

    public const GoodsReceivedNotInvoiced = 'goods_received_not_invoiced';

    public const PurchaseRequests = 'purchase_requests';

    public const PendingPurchaseRequests = 'pending_purchase_requests';

    public const OpenPurchaseOrders = 'open_purchase_orders';

    public const PartiallyReceivedOrders = 'partially_received_orders';

    public const SupplyOrders = 'supply_orders';

    public const SupplierDeliveries = 'supplier_deliveries';

    public const PurchaseReceipts = 'purchase_receipts';

    public const PurchaseInvoices = 'purchase_invoices';

    public const ReceivedVsInvoiced = 'received_vs_invoiced';

    public const PurchasesByCategory = 'purchases_by_category';

    public const PurchasesByWarehouse = 'purchases_by_warehouse';

    public const PriceHistory = 'price_history';

    /** @return Collection<int, array<string, mixed>> */
    public function documentChain(Model $document): Collection
    {
        if ($document instanceof FixedAssetMovement
            && $document->source_type === FixedAssetPurchaseIntegrationService::ImprovementSourceType) {
            $invoice = $document->purchaseInvoiceLine()->with('purchaseInvoice')->first()?->purchaseInvoice;

            return $invoice ? $this->documentChain($invoice) : collect();
        }

        if ($document instanceof FixedAsset) {
            if ($document->source_type === FixedAssetPurchaseIntegrationService::SourceType) {
                $invoice = $document->purchaseInvoiceLine()->with('purchaseInvoice')->first()?->purchaseInvoice;

                return $invoice ? $this->documentChain($invoice) : collect();
            }

            $invoiceIds = PurchaseInvoiceLine::query()
                ->whereIn('id', $document->movements()->where('source_type', FixedAssetPurchaseIntegrationService::ImprovementSourceType)->pluck('source_id'))
                ->pluck('purchase_invoice_id');

            return PurchaseInvoice::query()->whereIn('id', $invoiceIds)->get()
                ->flatMap(fn (PurchaseInvoice $invoice) => $this->documentChain($invoice))
                ->unique('url')
                ->values();
        }

        if ($document instanceof CashVoucher || $document instanceof Cheque) {
            $field = $document instanceof CashVoucher ? 'cash_voucher_id' : 'cheque_id';
            $payment = SupplierPaymentContext::query()->where('company_id', $document->company_id)
                ->where($field, $document->getKey())->first();

            return $payment ? $this->documentChain($payment) : collect();
        }
        $companyId = (int) $document->company_id;
        $scope = fn ($query) => $query->where('company_id', $companyId);
        $orderIds = collect();
        $requestIds = collect();
        if ($document instanceof RequestForQuotation) {
            $requestIds->push($document->purchase_requisition_id);
            $orderIds = PurchaseOrder::query()->where('request_for_quotation_id', $document->id)->pluck('id');
        } elseif ($document instanceof SupplierQuotation) {
            if ($document->purchase_requisition_id) {
                $requestIds->push($document->purchase_requisition_id);
            }
            if ($document->purchase_order_id) {
                $orderIds->push($document->purchase_order_id);
            }
            if ($document->request_for_quotation_id) {
                $requestIds->push($document->requestForQuotation?->purchase_requisition_id);
                $orderIds = $orderIds->merge(PurchaseOrder::query()->where('request_for_quotation_id', $document->request_for_quotation_id)->pluck('id'));
            }
        } elseif ($document instanceof SupplierSelection) {
            $requestIds->push($document->requestForQuotation?->purchase_requisition_id);
            $orderIds = PurchaseOrder::query()->where('request_for_quotation_id', $document->request_for_quotation_id)->pluck('id');
        }
        if ($document instanceof PurchaseRequisition) {
            $requestIds->push($document->getKey());
            $orderIds = PurchaseOrderLine::query()->whereIn('purchase_requisition_line_id', $document->lines()->pluck('id'))->pluck('purchase_order_id');
        } elseif ($document instanceof PurchaseOrder) {
            $orderIds->push($document->getKey());
        } elseif ($document->purchase_order_id) {
            $orderIds->push($document->purchase_order_id);
        }
        if ($document instanceof SupplierPaymentContext) {
            $orderIds = $orderIds->merge($document->allocations()->with('purchaseInvoice')->get()->pluck('purchaseInvoice.purchase_order_id')->filter());
        }
        $orders = $scope(PurchaseOrder::query())->whereIn('id', $orderIds->unique())->with(['lines' => fn ($query) => $query->withQuantityProgress()->with(['requisitionLine', 'product'])])->get();
        $requestIds = $requestIds->merge($orders->pluck('purchase_requisition_id')->filter())
            ->merge($orders->flatMap(fn ($order) => $order->lines->pluck('requisitionLine.purchase_requisition_id')->filter()))->unique();
        $requests = PurchaseRequisition::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $requestIds)
            ->get();
        $receipts = $scope(UnpricedInventoryReceipt::query())->whereIn('purchase_order_id', $orders->modelKeys())->with('lines')->get();
        $inspections = $scope(GoodsReceiptInspection::query())->whereIn('purchase_order_id', $orders->modelKeys())->with('lines')->get();
        $supplyOrders = $scope(SupplyOrder::query())->whereIn('purchase_order_id', $orders->modelKeys())->with('lines')->get();
        $invoices = $scope(PurchaseInvoice::query())->where(function ($query) use ($orders, $document): void {
            $query->whereIn('purchase_order_id', $orders->modelKeys());
            if ($document instanceof PurchaseInvoice) {
                $query->orWhere('id', $document->getKey());
            }
        })->get();
        $invoiceLineIds = PurchaseInvoiceLine::query()->whereIn('purchase_invoice_id', $invoices->modelKeys())->pluck('id');
        $fixedAssets = FixedAsset::query()
            ->forCompany($companyId)
            ->where('source_type', FixedAssetPurchaseIntegrationService::SourceType)
            ->whereIn('source_id', $invoiceLineIds)
            ->get()
            ->concat(FixedAsset::query()
                ->forCompany($companyId)
                ->whereIn('id', PurchaseInvoiceLine::query()
                    ->whereIn('id', $invoiceLineIds)
                    ->where('asset_treatment', FixedAssetPurchaseIntegrationService::TreatmentCapitalImprovement)
                    ->whereNotNull('target_fixed_asset_id')
                    ->pluck('target_fixed_asset_id'))
                ->get())
            ->unique('id')
            ->values();
        $assetImprovementMovements = FixedAssetMovement::query()
            ->where('company_id', $companyId)
            ->where('source_type', FixedAssetPurchaseIntegrationService::ImprovementSourceType)
            ->whereIn('source_id', $invoiceLineIds)
            ->get();
        $payments = $scope(SupplierPaymentContext::query())->where(function ($query) use ($orders, $invoices, $document): void {
            $query->whereIn('purchase_order_id', $orders->modelKeys())->orWhereHas('allocations', fn ($query) => $query->whereIn('purchase_invoice_id', $invoices->modelKeys()));
            if ($document instanceof SupplierPaymentContext) {
                $query->orWhere('id', $document->getKey());
            }
        })->with(['cashVoucher', 'cheque'])->get();
        $returns = $scope(PurchaseReturn::query())->whereIn('purchase_order_id', $orders->modelKeys())->get();
        $rfqs = $scope(RequestForQuotation::query())->whereIn('purchase_requisition_id', $requestIds)->get();
        $quotations = $scope(SupplierQuotation::query())->where(function ($query) use ($rfqs, $requestIds, $orders): void {
            $query->whereIn('request_for_quotation_id', $rfqs->modelKeys())
                ->orWhereIn('purchase_requisition_id', $requestIds)
                ->orWhereIn('purchase_order_id', $orders->modelKeys());
        })->get();
        $selections = $scope(SupplierSelection::query())
            ->whereIn('request_for_quotation_id', $rfqs->modelKeys())
            ->get();
        $allDocuments = $requests->concat($rfqs)->concat($quotations)->concat($selections)->concat($orders)->concat($supplyOrders)->concat($inspections)->concat($receipts)->concat($invoices)->concat($fixedAssets)->concat($assetImprovementMovements)->concat($payments)->concat($returns);
        $users = User::query()->whereIn('id', $allDocuments->flatMap(fn ($record) => [$record->posted_by, $record->approved_by, $record->finalized_by, $record->inspected_by, $record->created_by, $record->requested_by])->filter()->unique())->pluck('name', 'id');
        $nodes = collect();
        $append = function ($records, string $label, string $route, string $permission) use ($nodes, $users): void {
            foreach ($records as $record) {
                $date = $record->request_date ?? $record->inspection_at ?? $record->document_date ?? $record->invoice_date ?? $record->asset_date ?? $record->movement_date ?? $record->payment_date ?? $record->return_date ?? $record->entry_date ?? $record->voucher_date ?? $record->created_at;
                $nodes->push(['label' => __($label), 'doc_num' => $record->doc_num, 'url' => route($route, $record->doc_num),
                    'permission' => $permission, 'status' => $record instanceof PurchaseOrder ? $record->fulfillmentStatus() : ($record->posting_status ?? $record->status),
                    'date' => $date?->format('Y-m-d'), 'user' => $users[$record->posted_by ?? $record->approved_by ?? $record->finalized_by ?? $record->inspected_by ?? $record->created_by ?? $record->requested_by] ?? null,
                    'amount' => $record->total_amount ?? $record->amount ?? $record->purchase_value,
                    'quantity' => $record instanceof GoodsReceiptInspection ? $record->lines->sum('inspected_quantity') : $record->total_ordered_quantity]);
            }
        };
        $append($requests, 'Purchase Requisition', 'admin.purchases.purchase-requisitions.show', 'purchases.purchase_requisitions.view');
        $append($rfqs, 'Request for Quotation', 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view');
        $append($quotations, 'Supplier Quotation', 'admin.purchases.supplier-quotation-entry.show', 'purchases.supplier_quotation_entry.view');
        $append($selections, 'Supplier Selection', 'admin.purchases.supplier-selection.show', 'purchases.supplier_selection.view');
        $append($orders, 'Purchase Order', 'admin.purchases.purchase-orders.show', 'purchase_orders.view');
        $append($supplyOrders, 'Supply Order', 'admin.purchases.supply-orders.show', 'purchases.supply_orders.view');
        $append($inspections, 'Purchase Inspection', 'admin.purchases.goods-receipt-inspection.show', 'purchases.goods_receipt_inspection.view');
        $append($receipts, 'Goods Receipt', 'admin.purchases.goods-receipt-notes.show', 'purchases.goods_receipt_notes.view');
        $append($invoices, 'Purchase Invoice', 'admin.purchases.purchase-invoices.show', 'purchase_invoices.view');
        $append($fixedAssets, 'fixed_assets.singular', 'admin.fixed-assets.assets.show', 'fixed_assets.view');
        $append($assetImprovementMovements, 'fixed_assets.cycle.addition', 'admin.fixed-assets.prints.movement', 'fixed_assets.print');
        $append($payments, 'Supplier Payment', 'admin.purchases.supplier-payments.show', 'supplier_payments.view');
        $append($returns, 'Purchase Return', 'admin.purchases.purchase-returns.show', 'purchases.purchase_returns.view');
        $append($payments->pluck('cashVoucher')->filter(), 'Cash Payment Voucher', 'admin.finance.cash-payment-vouchers.show', 'cash_payment_vouchers.view');
        $append($payments->pluck('cheque')->filter(), 'Cheque', 'admin.finance.cheques.show', 'cheques.view');
        $journalIds = $invoices->pluck('journal_entry_id')->merge($payments->pluck('journal_entry_id'))
            ->merge($payments->pluck('cashVoucher.journal_entry_id'))->merge($returns->pluck('journal_entry_id'))
            ->merge($returns->pluck('grni_reversal_journal_entry_id'))->merge($returns->pluck('reversal_journal_entry_id'))
            ->merge($receipts->flatMap(fn ($receipt) => $receipt->lines->pluck('grni_journal_entry_id')))->filter()->unique();
        $journals = $scope(JournalEntry::query())->whereIn('id', $journalIds)->get();
        $append($journals, 'Journal Entry', 'admin.accounting.journal-entries.show', 'journal_entries.view');
        $receiptLineIds = $receipts->flatMap(fn ($receipt) => $receipt->lines->modelKeys());
        $reversalJournals = $scope(JournalEntry::query())->where(function ($query) use ($receiptLineIds, $returns): void {
            $query->where(fn ($query) => $query->where('source_type', 'grni_receipt_reversal')->whereIn('source_id', $receiptLineIds))
                ->orWhere(fn ($query) => $query->where('source_type', 'grni_purchase_return_reversal')->whereIn('source_id', $returns->modelKeys()));
        })->get();
        $append($reversalJournals, 'Reversal journal', 'admin.accounting.journal-entries.show', 'journal_entries.view');
        $movementSources = InventoryTransaction::query()->where('company_id', $companyId)->whereIn('source_type', [UnpricedInventoryReceipt::class, PurchaseReturn::class])
            ->whereIn('source_id', $receipts->concat($returns)->pluck('id'))->get(['source_type', 'source_id'])->keyBy(fn ($movement) => $movement->source_type.':'.$movement->source_id);
        foreach ($receipts->concat($returns) as $stockDocument) {
            if ($movementSources->has($stockDocument::class.':'.$stockDocument->getKey())) {
                $nodes->push(['label' => __('Inventory movements'), 'doc_num' => $stockDocument->doc_num,
                    'url' => route('admin.inventory.reports.index', ['source_doc_num' => $stockDocument->doc_num]),
                    'permission' => 'inventory.reports.operational', 'status' => $stockDocument->status,
                    'date' => $stockDocument->document_date?->format('Y-m-d') ?? $stockDocument->return_date?->format('Y-m-d'), 'user' => null]);
            }
        }

        return $nodes->unique('url')->values();
    }

    public static function types(): array
    {
        return [
            self::SupplierStatement, self::PurchaseLedger, self::PurchaseRequests, self::PendingPurchaseRequests, self::OpenPurchaseOrders,
            self::PartiallyReceivedOrders, self::SupplyOrders, self::SupplierDeliveries, self::PurchaseReceipts,
            self::PurchaseInvoices, self::ReceivedVsInvoiced, self::PurchasesByCategory,
            self::PurchasesByWarehouse, self::PriceHistory, self::ReceiptQualityStatus,
            self::OpenRequirements,
            self::RequestedVsOrdered,
            self::RfqQuotationStatus,
            self::PendingSourcingActions,
            self::PurchaseOrderStatus,
            self::OrderedVsReceived,
            self::OverduePoDeliveries,
            self::DeliverySchedule,
            self::IncomingQcPending,
            self::GoodsReceivedNotInvoiced,
            self::QcRejection,
            self::PurchasesBySupplier,
            self::PurchasesByProduct,
            self::PurchasesByPeriod,
            self::OutstandingSupplierInvoices,
            self::DueSupplierInstallments,
            self::SupplierAging,
            self::UpcomingSupplierPayments,
            self::Returns,
            self::ProductionAnalysis,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $type, array $filters, int $companyId, int $financialPeriodId): Collection
    {
        if ($type === self::SupplierStatement) {
            return $this->supplierStatement($companyId, $financialPeriodId, $filters);
        }
        $rows = match ($type) {
            self::PurchaseLedger => $this->purchaseLedger($companyId, $financialPeriodId, $filters),
            self::PurchaseRequests => $this->requestedVsOrdered($companyId, $financialPeriodId),
            self::PendingPurchaseRequests => $this->requestedVsOrdered($companyId, $financialPeriodId, true)->whereIn('status', ['draft', 'pending_approval', 'approved', 'partially_converted'])->where('outstanding', '>', 0)->values(),
            self::OpenPurchaseOrders => $this->purchaseOrderStatus($companyId, $financialPeriodId, true)->whereIn('status', ['approved', 'sent', 'partially_received'])->where('outstanding', '>', 0)->values(),
            self::PartiallyReceivedOrders => $this->purchaseOrderStatus($companyId, $financialPeriodId, true)->where('status', 'partially_received')->values(),
            self::SupplyOrders => $this->supplyOrderStatus($companyId, $financialPeriodId),
            self::PurchaseReceipts, self::SupplierDeliveries => $this->receiptQualityStatus($companyId, $financialPeriodId),
            self::ReceiptQualityStatus => $this->purchaseInspectionStatus($companyId, $financialPeriodId),
            self::PurchaseInvoices => $this->supplierPayables($companyId, $financialPeriodId, $filters),
            self::ReceivedVsInvoiced => $this->purchaseOrderStatus($companyId, $financialPeriodId, true)->whereNotIn('status', ['draft', 'cancelled'])->values(),
            self::OpenRequirements => $this->openRequirements($companyId, $financialPeriodId),
            self::RequestedVsOrdered => $this->requestedVsOrdered($companyId, $financialPeriodId),
            self::RfqQuotationStatus => $this->rfqQuotationStatus($companyId, $financialPeriodId),
            self::PendingSourcingActions => $this->rfqQuotationStatus($companyId, $financialPeriodId)->where('action_pending', true)->values(),
            self::PurchaseOrderStatus, self::OrderedVsReceived => $this->purchaseOrderStatus($companyId, $financialPeriodId),
            self::PurchasesBySupplier, self::PurchasesByProduct, self::PurchasesByPeriod, self::PurchasesByCategory, self::PurchasesByWarehouse => $this->invoicePurchases($companyId, $financialPeriodId),
            self::PriceHistory => $this->invoicePurchases($companyId, $financialPeriodId, true),
            self::OverduePoDeliveries => $this->purchaseOrderStatus($companyId, $financialPeriodId, true)->where('overdue', true)->values(),
            self::DeliverySchedule => $this->deliverySchedule($companyId, $financialPeriodId),
            self::IncomingQcPending => $this->purchaseInspectionStatus($companyId, $financialPeriodId)->where('receipt_pending', true)->values(),
            self::QcRejection => $this->purchaseInspectionStatus($companyId, $financialPeriodId)
                ->filter(fn (array $row): bool => (float) $row['rejected'] > 0)
                ->map(fn (array $row): array => [...$row, 'outstanding' => $row['rejected']])
                ->values(),
            self::GoodsReceivedNotInvoiced => $this->goodsReceivedNotInvoiced($companyId, $financialPeriodId),
            self::OutstandingSupplierInvoices, self::SupplierAging => $this->supplierPayables($companyId, $financialPeriodId, $filters, true)->filter(fn (array $row): bool => (float) $row['outstanding'] > 0)->values(),
            self::DueSupplierInstallments => $this->supplierInstallments($companyId, $financialPeriodId, false),
            self::UpcomingSupplierPayments => $this->supplierInstallments($companyId, $financialPeriodId, true),
            self::Returns => $this->returns($companyId, $financialPeriodId),
            self::ProductionAnalysis => $this->productionAnalysis($companyId, $financialPeriodId),
            default => collect(),
        };

        $geographySupplierDocNums = $this->supplierGeographyDocNums($companyId, $filters);
        if ($geographySupplierDocNums !== null) {
            $rows = $rows->whereIn('supplier_doc_num', $geographySupplierDocNums)->values();
        }

        $currencyCodes = Currency::query()->where('company_id', $companyId)->pluck('code', 'doc_num');
        $rows = $rows->map(fn (array $row): array => [...$row,
            'currency_doc_num' => $currencyCodes->has($row['currency']) ? $row['currency'] : $currencyCodes->search($row['currency'], true),
            'currency' => $currencyCodes[$row['currency']] ?? $row['currency']]);
        $rows = $this->applyFilters($rows, $filters);
        $documentTarget = match ($type) {
            self::PurchaseRequests, self::PendingPurchaseRequests, self::RequestedVsOrdered, self::OpenRequirements, self::ProductionAnalysis => ['admin.purchases.purchase-requisitions.show', 'purchases.purchase_requisitions.view'],
            self::RfqQuotationStatus, self::PendingSourcingActions => ['admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view'],
            self::PurchaseOrderStatus, self::OpenPurchaseOrders, self::PartiallyReceivedOrders, self::OrderedVsReceived, self::ReceivedVsInvoiced, self::OverduePoDeliveries, self::DeliverySchedule => ['admin.purchases.purchase-orders.show', 'purchase_orders.view'],
            self::SupplyOrders => ['admin.purchases.supply-orders.show', 'purchases.supply_orders.view'],
            self::PurchaseReceipts, self::SupplierDeliveries, self::GoodsReceivedNotInvoiced => ['admin.purchases.goods-receipt-notes.show', 'purchases.goods_receipt_notes.view'],
            self::ReceiptQualityStatus, self::IncomingQcPending, self::QcRejection => ['admin.purchases.goods-receipt-inspection.show', 'purchases.goods_receipt_inspection.view'],
            self::Returns => ['admin.purchases.purchase-returns.show', 'purchases.purchase_returns.view'],
            default => null,
        };
        if ($documentTarget) {
            $rows = $rows->map(fn (array $row): array => [...$row, 'document_url' => filled($row['document']) ? route($documentTarget[0], $type === self::DeliverySchedule ? $row['purchase_order'] : $row['document']) : null, 'document_permission' => $documentTarget[1]]);
        }
        $groupKey = match ($type) {
            self::PurchasesBySupplier => 'supplier_doc_num', self::PurchasesByProduct => 'product_doc_num',
            self::PurchasesByPeriod => 'month', self::PurchasesByCategory => 'category_key', self::PurchasesByWarehouse => 'warehouse_uuid',
            default => null,
        };
        if ($groupKey && ($filters['detail_level'] ?? 'summary') !== 'lines') {
            return $rows->groupBy(fn (array $row): string => implode('|', [$row[$groupKey] ?? '', $row['currency'], $row['unit']]))
                ->map(function (Collection $group) use ($groupKey): array {
                    $first = $group->first();

                    return $this->row([
                        $groupKey => $first[$groupKey], 'supplier' => $group->pluck('supplier')->filter()->unique()->join(' / '),
                        'product' => $group->pluck('product')->filter()->unique()->join(' / '),
                        'category' => $first['category'], 'warehouse' => $group->pluck('warehouse')->filter()->unique()->join(' / '),
                        'branch' => $group->pluck('branch')->filter()->unique()->join(' / '), 'month' => $first['month'],
                        'currency' => $first['currency'], 'unit' => $first['unit'],
                        'quantity' => $group->sum('quantity'), 'amount' => $group->sum('amount'),
                    ]);
                })->values();
        }

        return $rows;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function supplierStatement(int $companyId, int $periodId, array $filters): Collection
    {
        $period = FinancialPeriod::query()->where('company_id', $companyId)->findOrFail($periodId);
        $from = $filters['date_from'] ?? $period->from_date->toDateString();
        $to = $filters['date_to'] ?? $period->to_date->toDateString();
        $geographySupplierDocNums = $this->supplierGeographyDocNums($companyId, $filters);
        $suppliers = Supplier::query()->where('company_id', $companyId)->whereNotNull('account_id')
            ->when(filled($filters['supplier_doc_num'] ?? null), fn ($query) => $query->where('doc_num', $filters['supplier_doc_num']))
            ->when($geographySupplierDocNums !== null, fn ($query) => $query->whereIn('doc_num', $geographySupplierDocNums))
            ->get()->keyBy('account_id');
        $currencies = Currency::query()->where('company_id', $companyId)->get()->keyBy('id');
        $entries = JournalEntryLine::query()->with('journalEntry')
            ->whereIn('account_id', $suppliers->keys())
            ->when(filled($filters['branch_id'] ?? null), fn ($query) => $query->where(fn ($query) => $query->where('branch_id', $filters['branch_id'])->orWhere(fn ($query) => $query->whereNull('branch_id')->whereHas('journalEntry', fn ($query) => $query->where('branch_id', $filters['branch_id'])))))
            ->whereHas('journalEntry', fn ($query) => $query->where('company_id', $companyId)->where('status', JournalEntry::StatusPosted)
                ->whereDate('entry_date', '<=', $to))
            ->get()->sortBy(fn ($line) => $line->journalEntry->entry_date->toDateString().sprintf('%015d%015d', $line->journal_entry_id, $line->id));
        $rows = collect();
        foreach ($entries->groupBy(fn ($line) => $line->account_id.':'.$line->journalEntry->currency_id) as $group) {
            $first = $group->first();
            $supplier = $suppliers[$first->account_id];
            $currency = $currencies[$first->journalEntry->currency_id]?->doc_num;
            if (filled($filters['currency_doc_num'] ?? null) && $filters['currency_doc_num'] !== $currency) {
                continue;
            }
            $balance = '0.0000';
            $defaults = ['supplier_doc_num' => $supplier->doc_num, 'supplier' => $supplier->name, 'currency_doc_num' => $currency, 'currency' => $currencies[$first->journalEntry->currency_id]?->code];
            foreach ($group->filter(fn ($line) => $line->journalEntry->entry_date->toDateString() < $from) as $line) {
                $balance = bcadd($balance, bcsub((string) $line->credit_amount, (string) $line->debit_amount, 4), 4);
            }
            $rows->push($this->row([...$defaults, 'date' => $from, 'document_type' => __('Opening balance'), 'document' => __('Opening balance'), 'debit' => 0, 'credit' => 0, 'balance' => $balance]));
            foreach ($group->filter(fn ($line) => $line->journalEntry->entry_date->toDateString() >= $from) as $line) {
                $entry = $line->journalEntry;
                $balance = bcadd($balance, bcsub((string) $line->credit_amount, (string) $line->debit_amount, 4), 4);
                $sourceType = preg_replace('/_reversal(?:_\d+)?$/', '', (string) $entry->source_type);
                if (filled($filters['document_type'] ?? null) && $filters['document_type'] !== $sourceType) {
                    continue;
                }
                $rows->push($this->row([...$defaults, 'date' => $entry->entry_date->toDateString(),
                    'document' => $entry->doc_num, 'document_url' => route('admin.accounting.journal-entries.show', $entry->doc_num),
                    'document_permission' => 'journal_entries.view', ...$this->statementSource($entry),
                    'reference' => $entry->source_doc_num, 'description' => $line->description ?: $entry->description,
                    'debit' => $line->debit_amount, 'credit' => $line->credit_amount, 'balance' => $balance,
                    'branch_id' => $entry->branch_id]));
            }
            if (filled($filters['document_type'] ?? null)) {
                $rows->push($this->row([...$defaults, 'date' => $to, 'document_type' => __('Closing balance'), 'document' => __('Closing balance'), 'debit' => 0, 'credit' => 0, 'balance' => $balance]));
            }
        }

        return $rows;
    }

    /** @return array<string, string|null> */
    private function statementSource(JournalEntry $entry): array
    {
        $type = preg_replace('/_reversal(?:_\d+)?$/', '', (string) $entry->source_type);
        $source = match ($type) {
            'purchase_invoice' => ['Purchase Invoice', 'admin.purchases.purchase-invoices.show', 'purchase_invoices.view'],
            'purchase_return' => ['Purchase Return', 'admin.purchases.purchase-returns.show', 'purchases.purchase_returns.view'],
            'supplier_payment', 'supplier_cheque_issue', 'supplier_cheque_clearing' => ['Supplier Payment', 'admin.purchases.supplier-payments.show', 'supplier_payments.view'],
            'cash_voucher', 'cash_payment_voucher' => ['Cash Payment Voucher', 'admin.finance.cash-payment-vouchers.show', 'cash_payment_vouchers.view'],
            'cheque', 'cheque_payment' => ['Cheque', 'admin.finance.cheques.show', 'cheques.view'],
            'opening_balance' => ['Opening balance', null, null],
            default => ['Journal Entry', null, null],
        };

        return ['document_type' => __($source[0]).(str_contains((string) $entry->source_type, '_reversal') ? ' — '.__('Reversal') : ''),
            'reference_url' => $source[1] && $entry->source_doc_num ? route($source[1], $entry->source_doc_num) : null,
            'reference_permission' => $source[2]];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function purchaseLedger(int $companyId, int $periodId, array $filters = [], bool $carryForward = false): Collection
    {
        $cutoff = $filters['date_to'] ?? FinancialPeriod::query()->where('company_id', $companyId)->findOrFail($periodId)->to_date->toDateString();

        return PurchaseInvoice::query()->where('company_id', $companyId)
            ->when($carryForward, fn ($query) => $query->whereDate('invoice_date', '<=', $cutoff), fn ($query) => $query->where('financial_period_id', $periodId))
            ->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])
            ->when(filled($filters['product_doc_num'] ?? null), fn ($query) => $query->whereHas('lines.product', fn ($query) => $query->where('doc_num', $filters['product_doc_num'])))
            ->when(filled($filters['category_key'] ?? null), fn ($query) => $query->whereHas('lines.product', fn ($query) => is_numeric($filters['category_key']) ? $query->where('item_category_id', $filters['category_key']) : $query->where('item_classification', $filters['category_key'])))
            ->with(['supplier', 'currency', 'purchaseOrder.branchStore', 'lines.receiptLine.receipt', 'purchaseReturns', 'paymentSchedules', 'paymentAllocations.paymentContext'])
            ->orderBy('invoice_date')->orderBy('id')->get()->map(function (PurchaseInvoice $invoice) use ($cutoff, $filters): array {
                $returned = $invoice->purchaseReturns->where('status', 'posted')->filter(fn ($return) => $return->return_date->toDateString() <= $cutoff)->sum('total_amount');
                $paid = $invoice->paymentAllocations->filter(fn ($allocation) => $allocation->paymentContext?->isApproved() && $allocation->paymentContext->payment_date->toDateString() <= $cutoff)->sum('amount');
                $outstanding = max(0, (float) $invoice->total_amount - $returned - $paid);
                $paymentStatus = $outstanding <= 0.0001 ? PurchaseInvoice::PaymentStatusPaid : ($paid > 0 ? PurchaseInvoice::PaymentStatusPartiallyPaid : PurchaseInvoice::PaymentStatusUnpaid);

                return $this->row(['date' => $invoice->invoice_date->toDateString(), 'document' => $invoice->doc_num,
                    'document_url' => route('admin.purchases.purchase-invoices.show', $invoice->doc_num), 'document_permission' => 'purchase_invoices.view',
                    'supplier' => $invoice->supplier?->name, 'supplier_doc_num' => $invoice->supplier?->doc_num,
                    'receipt_references' => $invoice->lines->pluck('receiptLine.receipt.doc_num')->filter()->unique()->values()->all(),
                    'purchase_order' => $invoice->purchaseOrder?->doc_num, 'receipt' => $invoice->lines->pluck('receiptLine.receipt.doc_num')->filter()->unique()->join(' / '),
                    'taxable' => $invoice->taxable_amount, 'discount' => bcadd((string) $invoice->header_discount_amount, (string) $invoice->line_discount_amount, 4),
                    'tax' => $invoice->tax_amount, 'amount' => $invoice->total_amount, 'returned_value' => $returned,
                    'net_purchases' => bcsub((string) $invoice->total_amount, (string) $returned, 4),
                    'paid' => $paid, 'outstanding' => $outstanding, 'payment_status' => $paymentStatus,
                    'overdue' => $outstanding > 0 && $invoice->paymentSchedules->contains(fn ($schedule) => $schedule->due_date?->isPast() && ! in_array($schedule->status, ['paid', 'settled', 'cancelled'], true)),
                    'product_doc_num' => $filters['product_doc_num'] ?? null, 'category_key' => $filters['category_key'] ?? null,
                    'currency' => $invoice->currency?->doc_num, 'branch_id' => $invoice->branch_id,
                    'warehouse_uuid' => $invoice->purchaseOrder?->branchStore?->public_uuid, 'status' => $invoice->status]);
            });
    }

    /** @return array<string, string> */
    public function columns(?string $type, bool $showPrices = true, string $detailLevel = 'summary'): array
    {
        if ($detailLevel === 'lines' && in_array($type, [self::PurchasesBySupplier, self::PurchasesByProduct, self::PurchasesByCategory, self::PurchasesByWarehouse, self::PurchasesByPeriod], true)) {
            $type = self::PriceHistory;
        }
        $base = ['date' => 'Date', 'document' => 'Document', 'status' => 'Status'];
        $item = ['product' => 'Item', 'warehouse' => 'Warehouse'];
        $columns = match ($type) {
            self::SupplierStatement => ['supplier' => 'Supplier', 'date' => 'Date', 'document_type' => 'Document type', 'document' => 'Document', 'reference' => 'Reference', 'description' => 'Description', 'debit' => 'Debit', 'credit' => 'Credit', 'balance' => 'Running balance', 'currency' => 'Currency'],
            self::PurchaseLedger => [...$base, 'supplier' => 'Supplier', 'purchase_order' => 'Purchase Order', 'receipt' => 'Receipt', 'taxable' => 'Taxable value', 'discount' => 'Discount', 'tax' => 'Tax', 'amount' => 'Invoice total', 'returned_value' => 'Returned value', 'net_purchases' => 'Net purchases', 'paid' => 'Paid', 'outstanding' => 'Remaining', 'currency' => 'Currency'],
            self::PurchaseRequests, self::PendingPurchaseRequests, self::RequestedVsOrdered => [...$base, ...$item, 'requested' => 'Requested', 'approved' => 'Approved', 'ordered' => 'Ordered', 'draft_order_quantity' => 'Quantity in draft orders', 'remaining_to_order' => 'Remaining to order'],
            self::RfqQuotationStatus, self::PendingSourcingActions => [...$base,
                'requisition' => __('procurement.reports.columns.requisition'),
                'invited_suppliers' => __('procurement.reports.columns.invited_suppliers'),
                'submitted_responses' => __('procurement.reports.columns.submitted_responses'),
                'selection_status' => __('procurement.reports.columns.selection_status'),
                'action_stage' => __('procurement.reports.columns.action_stage'),
                'outstanding' => __('procurement.reports.columns.remaining_actions'),
            ],
            self::OrderedVsReceived, self::OpenPurchaseOrders, self::PartiallyReceivedOrders, self::ReceivedVsInvoiced => [...$base, 'supplier' => 'Supplier', ...$item, 'ordered' => 'Ordered', 'received' => 'Received', 'returned' => 'Returned', 'net_received' => 'Net received', 'invoiced' => 'Invoiced', 'remaining' => 'Remaining to receive', 'remaining_to_invoice' => 'Remaining to invoice'],
            self::PurchaseReceipts, self::SupplierDeliveries, self::ReceiptQualityStatus => [...$base, 'supplier' => 'Supplier', 'purchase_order' => 'Purchase Order', ...$item, 'quantity' => 'Received', 'accepted' => 'Accepted', 'rejected' => 'Rejected'],
            self::PurchaseInvoices => [...$base, 'supplier' => 'Supplier', 'purchase_order' => 'Purchase Order', 'amount' => 'Invoice total', 'paid' => 'Paid', 'outstanding' => 'Remaining', 'currency' => 'Currency', 'payment_status' => 'Payment status'],
            self::PriceHistory => [...$base, 'supplier' => 'Supplier', 'purchase_order' => 'Purchase Order', 'product' => 'Item', 'unit' => 'Unit', 'quantity' => 'Quantity', 'returned' => 'Returned', 'net_quantity' => 'Net purchased quantity', 'unit_price' => 'Unit price', 'discount' => 'Discount', 'tax' => 'Tax', 'net_price' => 'Net price', 'currency' => 'Currency', 'exchange_rate' => 'Exchange rate', 'amount' => 'Net value'],
            self::PurchasesBySupplier => ['supplier' => 'Supplier', 'unit' => 'Unit', 'quantity' => 'Quantity', 'amount' => 'Net value', 'currency' => 'Currency'],
            self::PurchasesByProduct => ['product' => 'Item', 'unit' => 'Unit', 'quantity' => 'Quantity', 'amount' => 'Net value', 'currency' => 'Currency'],
            self::PurchasesByCategory => ['category' => 'Item category', 'unit' => 'Unit', 'quantity' => 'Quantity', 'amount' => 'Net value', 'currency' => 'Currency'],
            self::PurchasesByWarehouse => ['branch' => 'Branch', 'warehouse' => 'Warehouse', 'unit' => 'Unit', 'quantity' => 'Quantity', 'amount' => 'Net value', 'currency' => 'Currency'],
            self::PurchasesByPeriod => ['month' => 'Month', 'unit' => 'Unit', 'quantity' => 'Quantity', 'amount' => 'Net value', 'currency' => 'Currency'],
            default => [],
        };

        return $showPrices ? $columns : array_diff_key($columns, array_flip(['amount', 'paid', 'unit_price', 'net_price', 'discount', 'tax', 'exchange_rate', 'taxable', 'returned_value', 'net_purchases', 'currency']));
    }

    /** @return list<string> */
    public function headings(bool $showPrices = true, ?string $type = null, string $detailLevel = 'summary'): array
    {
        if ($columns = $this->columns($type, $showPrices, $detailLevel)) {
            return array_values($columns);
        }
        if ($type === self::GoodsReceivedNotInvoiced) {
            return ['Receipt Date', 'GRN', 'Supplier', 'PO', 'Product', 'Store', 'Received Qty', 'Invoiced Qty', 'Returned Qty', 'Remaining Qty', 'Provisional Unit Value', 'Remaining GRNI Value', 'Currency', 'Days Outstanding', 'Status'];
        }
        $headings = [
            'Date', 'Document', 'Status', 'Supplier', 'Product', 'Purchase Requisition', 'Purchase Order',
            'Branch', 'Warehouse', 'QC Status', 'Production Order', 'Work Order', 'Quantity',
        ];

        return [
            ...$headings,
            ...($showPrices ? ['Amount'] : []),
            'Outstanding',
            'Overdue',
        ];
    }

    /** @return array{subledger: string, gl: string, difference: string, status: string, account: string|null} */
    public function grniReconciliation(int $companyId, int $financialPeriodId, ?int $branchId = null): array
    {
        try {
            $grniAccount = $this->accounts->resolve(
                $companyId,
                PostingAccountResolver::GoodsReceivedNotInvoiced,
                __('GRNI reconciliation'),
            );
        } catch (DomainException) {
            return ['subledger' => '0.0000', 'gl' => '0.0000', 'difference' => '0.0000', 'status' => 'not_configured', 'account' => null];
        }

        $subledger = $this->goodsReceivedNotInvoiced($companyId, $financialPeriodId)
            ->when($branchId, fn ($rows) => $rows->where('branch_id', $branchId))
            ->reduce(fn (string $total, array $row): string => bcadd($total, (string) $row['remaining_grni_value'], 4), '0.0000');
        $gl = bcadd((string) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->whereDate('journal_entries.entry_date', '<=', FinancialPeriod::query()->findOrFail($financialPeriodId)->to_date->toDateString())
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entry_lines.account_id', $grniAccount->getKey())
            ->when($branchId, fn ($query) => $query->where(fn ($query) => $query->where('journal_entry_lines.branch_id', $branchId)->orWhere(fn ($query) => $query->whereNull('journal_entry_lines.branch_id')->where('journal_entries.branch_id', $branchId))))
            ->selectRaw('coalesce(sum((journal_entry_lines.credit_amount - journal_entry_lines.debit_amount) * journal_entries.exchange_rate), 0) as balance')
            ->value('balance'), '0', 4);
        $difference = bcsub($subledger, $gl, 4);

        return [
            'subledger' => $subledger,
            'gl' => $gl,
            'difference' => $difference,
            'status' => bccomp($difference, '0', 4) === 0 ? 'reconciled' : 'difference',
            'account' => $grniAccount->doc_num,
        ];
    }

    /** @param array<string, mixed> $row
     * @return list<mixed>
     */
    public function exportMap(array $row, bool $showPrices = true, ?string $type = null, string $detailLevel = 'summary'): array
    {
        if ($columns = $this->columns($type, $showPrices, $detailLevel)) {
            return array_map(fn (string $key) => $row[$key] ?? null, array_keys($columns));
        }
        if ($type === self::GoodsReceivedNotInvoiced) {
            return [
                $row['date'], $row['document'], $row['supplier'], $row['purchase_order'], $row['product'], $row['warehouse'],
                $row['received_quantity'], $row['invoiced_quantity'], $row['returned_quantity'], $row['remaining_quantity'],
                $row['provisional_unit_value'], $row['remaining_grni_value'], $row['currency'], $row['age_days'], $row['status'],
            ];
        }
        $values = [
            $row['date'], $row['document'], $row['status'], $row['supplier'], $row['product'],
            $row['requisition'], $row['purchase_order'], $row['branch'], $row['warehouse'], $row['qc_status'],
            $row['production_order'], $row['work_order'], $row['quantity'],
        ];

        return [
            ...$values,
            ...($showPrices ? [$row['amount']] : []),
            $row['outstanding'],
            $row['overdue'] ? 'Yes' : 'No',
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function openRequirements(int $companyId, int $periodId): Collection
    {
        return PurchaseRequisitionLine::query()
            ->with(['requisition.branch', 'requisition.branchStore', 'product', 'purchaseOrderLines'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->whereHas('requisition', fn ($query) => $query->whereIn('status', ['approved', 'partially_converted']))
            ->get()->map(function (PurchaseRequisitionLine $line): array {
                $ordered = $line->orderedQuantity();
                $outstanding = max(0, (float) $line->approved_quantity - $ordered);

                return $this->row([
                    'date' => $line->requisition?->request_date?->toDateString(),
                    'document' => $line->requisition?->doc_num,
                    'status' => $line->requisition?->status,
                    'product_doc_num' => $line->product?->doc_num,
                    'product' => $line->product?->name,
                    'requisition' => $line->requisition?->doc_num,
                    'branch_id' => $line->requisition?->branch_id,
                    'branch' => $line->requisition?->branch?->name,
                    'warehouse_uuid' => $line->requisition?->branchStore?->public_uuid,
                    'warehouse' => $line->requisition?->branchStore?->name,
                    'production_order' => $line->source_type === 'production_order' ? $line->source_doc_num : null,
                    'work_order' => $line->source_type === 'work_order' ? $line->source_doc_num : null,
                    'quantity' => $line->approved_quantity,
                    'outstanding' => $outstanding,
                    'overdue' => $outstanding > 0 && $line->required_date?->isPast(),
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function requestedVsOrdered(int $companyId, int $periodId, bool $carryForward = false): Collection
    {
        $cutoff = FinancialPeriod::query()->where('company_id', $companyId)->findOrFail($periodId)->to_date->toDateString();

        return PurchaseRequisitionLine::query()
            ->with(['requisition.branch', 'requisition.branchStore', 'product', 'purchaseOrderLines'])
            ->where('company_id', $companyId)->when($carryForward, fn ($query) => $query->whereHas('requisition', fn ($query) => $query->whereDate('request_date', '<=', $cutoff)), fn ($query) => $query->where('financial_period_id', $periodId))->get()
            ->map(function (PurchaseRequisitionLine $line): array {
                $ordered = $line->orderedQuantity();

                return $this->row([
                    'date' => $line->requisition?->request_date?->toDateString(),
                    'document' => $line->requisition?->doc_num,
                    'status' => $line->requisition?->status,
                    'product_doc_num' => $line->product?->doc_num,
                    'product' => $line->product?->name,
                    'requisition' => $line->requisition?->doc_num,
                    'branch_id' => $line->requisition?->branch_id,
                    'branch' => $line->requisition?->branch?->name,
                    'warehouse_uuid' => $line->requisition?->branchStore?->public_uuid,
                    'warehouse' => $line->requisition?->branchStore?->name,
                    'production_order' => $line->source_type === 'production_order' ? $line->source_doc_num : null,
                    'work_order' => $line->source_type === 'work_order' ? $line->source_doc_num : null,
                    'quantity' => $line->approved_quantity ?: $line->requested_quantity,
                    'outstanding' => max(0, (float) ($line->approved_quantity ?: $line->requested_quantity) - $ordered),
                    ...$line->quantityProgress(),
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rfqQuotationStatus(int $companyId, int $periodId): Collection
    {
        return RequestForQuotation::query()->with(['requisition.branch', 'suppliers', 'quotations', 'supplierSelections', 'lines'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)->get()
            ->map(function (RequestForQuotation $rfq): array {
                $submittedResponses = $rfq->quotations->where('status', 'submitted')->count();
                $invitedSuppliers = $rfq->suppliers->count();
                $latestSelection = $rfq->supplierSelections->sortByDesc('id')->first();
                $hasDraftSelection = $rfq->supplierSelections->contains('status', 'draft');
                $actionStage = match (true) {
                    $rfq->status === 'draft' => 'issue_rfq',
                    $rfq->status !== 'issued' => 'complete',
                    $submittedResponses < $invitedSuppliers => 'awaiting_quotations',
                    $latestSelection === null => 'select_supplier',
                    $hasDraftSelection => 'approve_selection',
                    default => 'complete',
                };
                $remainingActions = match ($actionStage) {
                    'awaiting_quotations' => max(0, $invitedSuppliers - $submittedResponses),
                    'complete' => 0,
                    default => 1,
                };

                return $this->row([
                    'date' => $rfq->issue_date?->toDateString(),
                    'document' => $rfq->doc_num,
                    'status' => $rfq->status,
                    'supplier' => $rfq->suppliers->pluck('name')->join(', '),
                    'requisition' => $rfq->requisition?->doc_num,
                    'branch_id' => $rfq->branch_id,
                    'branch' => $rfq->requisition?->branch?->name,
                    'quantity' => $rfq->lines->sum('quantity'),
                    'invited_suppliers' => $invitedSuppliers,
                    'submitted_responses' => $submittedResponses,
                    'selection_status' => $latestSelection?->status,
                    'action_stage' => __('procurement.reports.action_stages.'.$actionStage),
                    'action_pending' => $actionStage !== 'complete',
                    'outstanding' => $remainingActions,
                    'overdue' => $rfq->quotation_due_date?->isPast() && $actionStage === 'awaiting_quotations',
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function purchaseOrderStatus(int $companyId, int $periodId, bool $carryForward = false): Collection
    {
        $cutoff = FinancialPeriod::query()->where('company_id', $companyId)->findOrFail($periodId)->to_date->toDateString();
        $statuses = [];
        $lines = PurchaseOrderLine::query()->withQuantityProgress($cutoff)->with(['purchaseOrder.lines' => fn ($query) => $query->withQuantityProgress($cutoff)->with('product'), 'purchaseOrder.supplier', 'purchaseOrder.branch', 'purchaseOrder.currency', 'purchaseOrder.branchStore', 'product', 'unit', 'requisitionLine.requisition'])
            ->where('company_id', $companyId)->when($carryForward, fn ($query) => $query->whereHas('purchaseOrder', fn ($query) => $query->whereDate('document_date', '<=', $cutoff)), fn ($query) => $query->where('financial_period_id', $periodId))->get();

        return $lines->map(function (PurchaseOrderLine $line) use (&$statuses): array {
            $progress = $line->quantityProgress();
            $status = $statuses[$line->purchase_order_id] ??= $line->purchaseOrder?->fulfillmentStatus();

            return $this->row([
                'date' => $line->purchaseOrder?->document_date?->toDateString(),
                'document' => $line->purchaseOrder?->doc_num,
                'status' => $status,
                'supplier_doc_num' => $line->purchaseOrder?->supplier?->doc_num,
                'supplier' => $line->purchaseOrder?->supplier?->name,
                'product_doc_num' => $line->product?->doc_num,
                'product' => $line->product?->name,
                'requisition' => $line->requisitionLine?->requisition?->doc_num,
                'purchase_order' => $line->purchaseOrder?->doc_num,
                'branch_id' => $line->purchaseOrder?->branch_id,
                'branch' => $line->purchaseOrder?->branch?->name,
                'warehouse_uuid' => $line->purchaseOrder?->branchStore?->public_uuid,
                'warehouse' => $line->purchaseOrder?->branchStore?->name,
                'production_order' => $line->requisitionLine?->source_type === 'production_order' ? $line->requisitionLine?->source_doc_num : null,
                'work_order' => $line->requisitionLine?->source_type === 'work_order' ? $line->requisitionLine?->source_doc_num : null,
                'quantity' => $line->ordered_quantity,
                'unit' => $line->unit?->name,
                'amount' => $line->total_after_tax, 'currency' => $line->purchaseOrder?->currency?->doc_num,
                'outstanding' => $progress['remaining'],
                ...$progress,
                'overdue' => $progress['remaining'] > 0
                    && ($line->required_delivery_date ?? $line->purchaseOrder?->expected_delivery_date)?->isPast(),
            ]);
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function supplyOrderStatus(int $companyId, int $periodId): Collection
    {
        return SupplyOrderLine::query()
            ->with(['supplyOrder.supplier', 'supplyOrder.branch', 'supplyOrder.branchStore', 'supplyOrder.purchaseOrder', 'product'])
            ->where('company_id', $companyId)
            ->where('financial_period_id', $periodId)
            ->get()
            ->map(function (SupplyOrderLine $line): array {
                $received = $line->receivedQuantity();

                return $this->row([
                    'date' => $line->supplyOrder?->issue_date?->toDateString(),
                    'document' => $line->supplyOrder?->doc_num,
                    'status' => $line->supplyOrder?->status,
                    'supplier_doc_num' => $line->supplyOrder?->supplier?->doc_num,
                    'supplier' => $line->supplyOrder?->supplier?->name,
                    'product_doc_num' => $line->product?->doc_num,
                    'product' => $line->product?->name,
                    'purchase_order' => $line->supplyOrder?->purchaseOrder?->doc_num,
                    'branch_id' => $line->supplyOrder?->branch_id,
                    'branch' => $line->supplyOrder?->branch?->name,
                    'warehouse_uuid' => $line->supplyOrder?->branchStore?->public_uuid,
                    'warehouse' => $line->supplyOrder?->branchStore?->name,
                    'quantity' => $line->ordered_quantity,
                    'received' => $received,
                    'outstanding' => max(0, (float) $line->ordered_quantity - $received),
                    'overdue' => $line->supplyOrder?->expected_delivery_date?->isPast()
                        && $received + 0.00000001 < (float) $line->ordered_quantity,
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function deliverySchedule(int $companyId, int $periodId): Collection
    {
        return PurchaseOrderDeliverySchedule::query()
            ->with(['purchaseOrder.supplier', 'purchaseOrder.branch', 'purchaseOrder.branchStore', 'purchaseOrderLine.product'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)->get()
            ->map(fn (PurchaseOrderDeliverySchedule $schedule): array => $this->row([
                'date' => $schedule->scheduled_date?->toDateString(),
                'document' => $schedule->purchaseOrder?->doc_num.' / '.$schedule->sequence,
                'status' => $schedule->status,
                'supplier_doc_num' => $schedule->purchaseOrder?->supplier?->doc_num,
                'supplier' => $schedule->purchaseOrder?->supplier?->name,
                'product_doc_num' => $schedule->purchaseOrderLine?->product?->doc_num,
                'product' => $schedule->purchaseOrderLine?->product?->name,
                'purchase_order' => $schedule->purchaseOrder?->doc_num,
                'branch_id' => $schedule->purchaseOrder?->branch_id,
                'branch' => $schedule->purchaseOrder?->branch?->name,
                'warehouse_uuid' => $schedule->purchaseOrder?->branchStore?->public_uuid,
                'warehouse' => $schedule->purchaseOrder?->branchStore?->name,
                'quantity' => $schedule->scheduled_quantity,
                'outstanding' => max(0, (float) $schedule->scheduled_quantity - (float) $schedule->received_quantity),
                'overdue' => $schedule->scheduled_date?->isPast() && $schedule->status !== 'received',
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function purchaseInspectionStatus(int $companyId, int $periodId): Collection
    {
        return GoodsReceiptInspectionLine::query()
            ->with([
                'inspection.branch', 'inspection.purchaseOrder.supplier', 'inspection.purchaseOrder.branchStore',
                'inspection.supplyOrder', 'inspection.receipt', 'inspection.receipts', 'receiptLines.receipt', 'product', 'unit',
            ])
            ->whereHas('inspection', fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('financial_period_id', $periodId))
            ->get()
            ->map(function (GoodsReceiptInspectionLine $line): array {
                $inspection = $line->inspection;
                $order = $inspection?->purchaseOrder;
                $remainingReceiptQuantity = $line->remainingReceiptQuantity();
                $receiptDocuments = collect([$inspection?->receipt?->doc_num])
                    ->merge($inspection?->receipts?->pluck('doc_num') ?? [])
                    ->filter()
                    ->unique()
                    ->join('، ');

                return $this->row([
                    'date' => $inspection?->inspection_at?->toDateString(),
                    'document' => $inspection?->doc_num,
                    'status' => $inspection?->result,
                    'supplier_doc_num' => $order?->supplier?->doc_num,
                    'supplier' => $order?->supplier?->name,
                    'product_doc_num' => $line->product?->doc_num,
                    'product' => $line->product?->name,
                    'unit' => $line->unit?->name,
                    'purchase_order' => $order?->doc_num,
                    'supply_order' => $inspection?->supplyOrder?->doc_num,
                    'goods_receipt' => $receiptDocuments,
                    'branch_id' => $inspection?->branch_id,
                    'branch' => $inspection?->branch?->name,
                    'warehouse_uuid' => $order?->branchStore?->public_uuid,
                    'warehouse' => $order?->branchStore?->name,
                    'qc_status' => $inspection?->result,
                    'quantity' => $line->inspected_quantity,
                    'accepted' => $line->accepted_quantity,
                    'rejected' => $line->rejected_quantity,
                    'outstanding' => $remainingReceiptQuantity,
                    'receipt_pending' => $remainingReceiptQuantity > 0.00000001,
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function receiptQualityStatus(int $companyId, int $periodId): Collection
    {
        return UnpricedInventoryReceiptLine::query()->with(['receipt.supplier', 'receipt.branch', 'receipt.branchStore', 'product', 'purchaseOrderLine.purchaseOrder'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)->whereNotNull('purchase_order_line_id')->get()
            ->map(fn (UnpricedInventoryReceiptLine $line): array => $this->row([
                'date' => $line->receipt?->document_date?->toDateString(),
                'document' => $line->receipt?->doc_num,
                'status' => $line->receipt?->status,
                'supplier_doc_num' => $line->receipt?->supplier?->doc_num,
                'supplier' => $line->receipt?->supplier?->name,
                'product_doc_num' => $line->product?->doc_num,
                'product' => $line->product?->name,
                'purchase_order' => $line->purchaseOrderLine?->purchaseOrder?->doc_num,
                'branch_id' => $line->receipt?->branch_id,
                'branch' => $line->receipt?->branch?->name,
                'warehouse_uuid' => $line->receipt?->branchStore?->public_uuid,
                'warehouse' => $line->receipt?->branchStore?->name,
                'qc_status' => $line->receipt?->qc_status,
                'quantity' => $line->delivered_quantity,
                'outstanding' => $line->rejected_quantity, 'accepted' => $line->inventory_posted_quantity, 'rejected' => $line->rejected_quantity,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function goodsReceivedNotInvoiced(int $companyId, int $periodId): Collection
    {
        $cutoff = FinancialPeriod::query()->where('company_id', $companyId)->findOrFail($periodId)->to_date->toDateString();
        $billed = PurchaseInvoiceLine::query()->where('company_id', $companyId)->whereNotNull('receipt_line_id')
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereIn('status', ['approved', 'closed'])->whereDate('invoice_date', '<=', $cutoff))
            ->get(['receipt_line_id', 'grni_cleared_quantity', 'grni_cleared_value'])->groupBy('receipt_line_id');
        $returned = PurchaseReturnLine::query()->whereHas('purchaseReturn', fn ($query) => $query->where('company_id', $companyId)
            ->whereNull('purchase_invoice_id')->where('status', 'posted')->whereDate('return_date', '<=', $cutoff))
            ->where('from_quarantine', false)->get(['receipt_line_id', 'quantity', 'grni_reversed_value'])->groupBy('receipt_line_id');

        return UnpricedInventoryReceiptLine::query()
            ->with(['receipt.supplier', 'receipt.branch', 'receipt.branchStore', 'product', 'purchaseOrderLine.purchaseOrder.currency'])
            ->where('company_id', $companyId)
            ->where('accepted_quantity', '>', 0)
            ->whereHas('receipt', fn ($query) => $query->where('approved', true)->whereNotIn('status', ['cancelled', 'reversed'])->whereDate('document_date', '<=', $cutoff))
            ->whereNotNull('grni_journal_entry_id')
            ->get()
            ->map(function (UnpricedInventoryReceiptLine $line) use ($billed, $returned): array {
                $invoicedQuantity = (string) ($billed->get($line->id)?->sum('grni_cleared_quantity') ?? 0);
                $invoicedValue = (string) ($billed->get($line->id)?->sum('grni_cleared_value') ?? 0);
                $returnedQuantity = bcadd((string) ($returned->get($line->id)?->sum('quantity') ?? 0), '0', 8);
                $returnedValue = (string) ($returned->get($line->id)?->sum('grni_reversed_value') ?? 0);
                $eligibleQuantity = bcsub((string) $line->accepted_quantity, (string) $returnedQuantity, 8);
                $remainingQuantity = bcsub($eligibleQuantity, (string) $invoicedQuantity, 8);
                $remainingValue = bcsub(
                    bcsub((string) $line->provisional_total_value, (string) $returnedValue, 4),
                    (string) $invoicedValue,
                    4,
                );
                $receipt = $line->receipt;
                $order = $line->purchaseOrderLine?->purchaseOrder;

                return $this->row([
                    'date' => $receipt?->document_date?->toDateString(),
                    'document' => $receipt?->doc_num,
                    'status' => bccomp($remainingQuantity, '0', 8) > 0 ? 'open' : 'cleared',
                    'supplier_doc_num' => $receipt?->supplier?->doc_num,
                    'supplier' => $receipt?->supplier?->name,
                    'product_doc_num' => $line->product?->doc_num,
                    'product' => $line->product?->name,
                    'purchase_order' => $order?->doc_num,
                    'branch_id' => $receipt?->branch_id,
                    'branch' => $receipt?->branch?->name,
                    'warehouse_uuid' => $receipt?->branchStore?->public_uuid,
                    'warehouse' => $receipt?->branchStore?->name,
                    'quantity' => $line->accepted_quantity,
                    'amount' => $line->provisional_unit_value,
                    'outstanding' => $remainingValue,
                    'received_quantity' => $line->accepted_quantity,
                    'invoiced_quantity' => $invoicedQuantity,
                    'returned_quantity' => $returnedQuantity,
                    'remaining_quantity' => $remainingQuantity,
                    'provisional_unit_value' => $line->provisional_unit_value,
                    'remaining_grni_value' => $remainingValue,
                    'currency' => $order?->currency?->doc_num,
                    'age_days' => $receipt?->document_date?->diffInDays(today()) ?? 0,
                    'overdue' => bccomp($remainingQuantity, '0', 8) > 0 && $receipt?->document_date?->lt(today()->subDays(30)),
                ]);
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function invoicePurchases(int $companyId, int $periodId, bool $allPeriods = false): Collection
    {
        $cutoff = FinancialPeriod::query()->where('company_id', $companyId)->findOrFail($periodId)->to_date->toDateString();
        $lines = PurchaseInvoiceLine::query()
            ->where('company_id', $companyId)->when(! $allPeriods, fn ($query) => $query->where('financial_period_id', $periodId))
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereDate('invoice_date', '<=', $cutoff))
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereIn('status', ['approved', 'closed']))
            ->with(['purchaseInvoice.supplier', 'purchaseInvoice.branch', 'purchaseInvoice.currency', 'purchaseInvoice.purchaseOrder.branchStore', 'purchaseInvoice.lines', 'product.category', 'unit'])->get();

        $returns = PurchaseReturnLine::query()->whereIn('purchase_invoice_line_id', $lines->pluck('id'))
            ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted)->whereDate('return_date', '<=', $cutoff))
            ->selectRaw('purchase_invoice_line_id, sum(quantity) as quantity')->groupBy('purchase_invoice_line_id')->pluck('quantity', 'purchase_invoice_line_id');

        return $lines->map(function ($line) use ($returns): array {
            $invoice = $line->purchaseInvoice;
            $base = (float) $invoice->lines->sum('total_before_tax');
            $discount = $base > 0 ? (float) $invoice->header_discount_amount * (float) $line->total_before_tax / $base : 0;

            return $this->row([
                'date' => $invoice->invoice_date?->toDateString(), 'month' => $invoice->invoice_date?->format('Y-m'),
                'document' => $invoice->doc_num, 'status' => $invoice->status,
                'document_url' => route('admin.purchases.purchase-invoices.show', $invoice->doc_num), 'document_permission' => 'purchase_invoices.view',
                'supplier_doc_num' => $invoice->supplier?->doc_num, 'supplier' => $invoice->supplier?->name,
                'product_doc_num' => $line->product?->doc_num, 'product' => $line->product?->name,
                'category_key' => $line->product?->item_category_id ?? $line->product?->item_classification,
                'category' => $line->product?->category?->name ?? __('products.classifications.'.$line->product?->item_classification), 'unit' => $line->unit?->name,
                'branch_id' => $invoice->branch_id, 'branch' => $invoice->branch?->name,
                'warehouse_uuid' => $invoice->purchaseOrder?->branchStore?->public_uuid, 'warehouse' => $invoice->purchaseOrder?->branchStore?->name,
                'purchase_order' => $invoice->purchaseOrder?->doc_num, 'quantity' => $line->quantity,
                'amount' => (float) $line->total_before_tax - $discount, 'unit_price' => $line->unit_price,
                'discount' => (float) $line->discount_amount + $discount, 'tax' => $line->tax_amount,
                'net_price' => (float) $line->quantity > 0 ? ((float) $line->total_before_tax - $discount) / (float) $line->quantity : 0,
                'returned' => (float) ($returns[$line->id] ?? 0), 'net_quantity' => max(0, (float) $line->quantity - (float) ($returns[$line->id] ?? 0)),
                'exchange_rate' => $invoice->exchange_rate, 'currency' => $invoice->currency?->doc_num,
            ]);
        });
    }

    private function supplierPayables(int $companyId, int $periodId, array $filters = [], bool $carryForward = false): Collection
    {
        return $this->purchaseLedger($companyId, $periodId, $filters, $carryForward);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function supplierInstallments(int $companyId, int $periodId, bool $upcomingOnly): Collection
    {
        return PurchaseInvoicePaymentSchedule::query()->with(['purchaseInvoice.supplier', 'purchaseInvoice.branch', 'purchaseInvoice.purchaseOrder'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->whereHas('purchaseInvoice', fn ($query) => $query->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed]))
            ->get()
            ->map(function (PurchaseInvoicePaymentSchedule $schedule): array {
                $invoice = $schedule->purchaseInvoice;
                $outstanding = $schedule->outstanding_amount;

                return $this->row([
                    'date' => $schedule->due_date?->toDateString(),
                    'document' => $invoice?->doc_num.' / '.$schedule->line_number,
                    'status' => $schedule->status,
                    'supplier_doc_num' => $invoice?->supplier?->doc_num,
                    'supplier' => $invoice?->supplier?->name,
                    'purchase_order' => $invoice?->purchaseOrder?->doc_num,
                    'branch_id' => $invoice?->branch_id,
                    'branch' => $invoice?->branch?->name,
                    'document_url' => route('admin.purchases.purchase-invoices.show', $invoice->doc_num), 'document_permission' => 'purchase_invoices.view',
                    'amount' => $schedule->amount,
                    'outstanding' => $outstanding,
                    'overdue' => (float) $outstanding > 0 && $schedule->due_date?->isPast(),
                ]);
            })
            ->when($upcomingOnly, fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => (float) $row['outstanding'] > 0 && filled($row['date']) && $row['date'] >= today()->toDateString()))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function returns(int $companyId, int $periodId): Collection
    {
        return PurchaseReturnLine::query()->with(['purchaseReturn.supplier', 'purchaseReturn.purchaseOrder', 'purchaseReturn.receipt.branchStore', 'product'])
            ->whereHas('purchaseReturn', fn ($query) => $query->where('company_id', $companyId)->where('financial_period_id', $periodId))
            ->get()->map(fn (PurchaseReturnLine $line): array => $this->row([
                'date' => $line->purchaseReturn?->return_date?->toDateString(),
                'document' => $line->purchaseReturn?->doc_num,
                'status' => $line->purchaseReturn?->status,
                'supplier_doc_num' => $line->purchaseReturn?->supplier?->doc_num,
                'supplier' => $line->purchaseReturn?->supplier?->name,
                'product_doc_num' => $line->product?->doc_num,
                'product' => $line->product?->name,
                'purchase_order' => $line->purchaseReturn?->purchaseOrder?->doc_num,
                'warehouse_uuid' => $line->purchaseReturn?->receipt?->branchStore?->public_uuid,
                'warehouse' => $line->purchaseReturn?->receipt?->branchStore?->name,
                'qc_status' => $line->from_quarantine ? 'quarantine_return' : 'usable_stock_return',
                'quantity' => $line->quantity,
                'amount' => $line->line_total,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function productionAnalysis(int $companyId, int $periodId): Collection
    {
        return PurchaseRequisitionLine::query()->with(['requisition.branch', 'product', 'purchaseOrderLines.purchaseOrder'])
            ->where('company_id', $companyId)->where('financial_period_id', $periodId)
            ->whereIn('source_type', ['production_order', 'work_order'])->get()
            ->map(fn (PurchaseRequisitionLine $line): array => $this->row([
                'date' => $line->requisition?->request_date?->toDateString(),
                'document' => $line->requisition?->doc_num,
                'status' => $line->requisition?->status,
                'product_doc_num' => $line->product?->doc_num,
                'product' => $line->product?->name,
                'requisition' => $line->requisition?->doc_num,
                'purchase_order' => $line->purchaseOrderLines->first()?->purchaseOrder?->doc_num,
                'branch_id' => $line->requisition?->branch_id,
                'branch' => $line->requisition?->branch?->name,
                'production_order' => $line->source_type === 'production_order' ? $line->source_doc_num : null,
                'work_order' => $line->source_type === 'work_order' ? $line->source_doc_num : null,
                'quantity' => $line->approved_quantity,
                'outstanding' => max(0, (float) $line->approved_quantity - (float) $line->purchaseOrderLines->sum('ordered_quantity')),
            ]));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $exact = [
            'supplier_doc_num' => 'supplier_doc_num', 'product_doc_num' => 'product_doc_num',
            'purchase_requisition_doc_num' => 'requisition', 'purchase_order_doc_num' => 'purchase_order',
            'status' => 'status', 'branch_id' => 'branch_id', 'warehouse_uuid' => 'warehouse_uuid',
            'currency_doc_num' => 'currency_doc_num', 'payment_status' => 'payment_status', 'category_key' => 'category_key',
            'qc_status' => 'qc_status', 'production_order_doc_num' => 'production_order',
            'work_order_reference' => 'work_order',
        ];
        foreach ($exact as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $rows = $rows->where($column, $filters[$filter]);
            }
        }
        if (filled($filters['date_from'] ?? null)) {
            $rows = $rows->filter(fn (array $row): bool => filled($row['date']) && $row['date'] >= $filters['date_from']);
        }
        if (filled($filters['date_to'] ?? null)) {
            $rows = $rows->filter(fn (array $row): bool => filled($row['date']) && $row['date'] <= $filters['date_to']);
        }
        if (($filters['overdue'] ?? null) === '1') {
            $rows = $rows->where('overdue', true);
        }
        if (($filters['outstanding'] ?? null) === '1') {
            $rows = $rows->filter(fn (array $row): bool => (float) $row['outstanding'] > 0);
        }

        return $rows->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, string>|null
     */
    private function supplierGeographyDocNums(int $companyId, array $filters): ?Collection
    {
        $locationFilters = collect([
            'country' => $filters['country_doc_num'] ?? null,
            'governorate' => $filters['governorate_doc_num'] ?? null,
            'cityLookup' => $filters['city_doc_num'] ?? null,
            'area' => $filters['area_doc_num'] ?? null,
        ])->filter(fn (mixed $value): bool => filled($value));

        if ($locationFilters->isEmpty()) {
            return null;
        }

        return Supplier::query()->forCompany($companyId)
            ->when($locationFilters->has('country'), fn ($query) => $query->whereHas('country', fn ($location) => $location->where('doc_num', $locationFilters['country'])))
            ->when($locationFilters->has('governorate'), fn ($query) => $query->whereHas('governorate', fn ($location) => $location->where('doc_num', $locationFilters['governorate'])))
            ->when($locationFilters->has('cityLookup'), fn ($query) => $query->whereHas('cityLookup', fn ($location) => $location->where('doc_num', $locationFilters['cityLookup'])))
            ->when($locationFilters->has('area'), fn ($query) => $query->whereHas('area', fn ($location) => $location->where('doc_num', $locationFilters['area'])))
            ->pluck('doc_num');
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function row(array $values): array
    {
        return [
            'date' => null, 'document' => null, 'status' => null, 'supplier_doc_num' => null, 'supplier' => null,
            'product_doc_num' => null, 'product' => null, 'requisition' => null, 'purchase_order' => null,
            'branch_id' => null, 'branch' => null, 'warehouse_uuid' => null, 'warehouse' => null,
            'qc_status' => null, 'production_order' => null, 'work_order' => null, 'quantity' => 0,
            'amount' => 0, 'outstanding' => 0, 'overdue' => false,
            'received_quantity' => 0, 'invoiced_quantity' => 0, 'returned_quantity' => 0,
            'remaining_quantity' => 0, 'provisional_unit_value' => 0,
            'remaining_grni_value' => 0, 'currency' => null, 'age_days' => 0,
            'invited_suppliers' => 0, 'submitted_responses' => 0, 'selection_status' => null,
            'action_stage' => null, 'action_pending' => false,
            ...$values,
        ];
    }
}
