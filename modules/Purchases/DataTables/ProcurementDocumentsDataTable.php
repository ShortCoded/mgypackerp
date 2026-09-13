<?php

namespace Modules\Purchases\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\Branch;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplyOrder;
use Yajra\DataTables\Facades\DataTables;

class ProcurementDocumentsDataTable
{
    public function __construct(private readonly OperatingContextService $context, private readonly DataTableSearchService $search, private readonly DateFormatService $dates) {}

    public static function definition(string $screen): array
    {
        return match ($screen) {
            'purchase_requisitions' => ['model' => PurchaseRequisition::class, 'route' => 'purchase-requisitions', 'permission' => 'purchase_requisitions', 'date' => 'request_date', 'title' => 'Purchase Requisitions', 'type' => 'purchase-requisition'],
            'request_for_quotations' => ['model' => RequestForQuotation::class, 'route' => 'request-for-quotations', 'permission' => 'request_for_quotations', 'date' => 'issue_date', 'title' => 'Requests for Quotation', 'type' => 'request-for-quotation'],
            'supplier_quotations' => ['model' => SupplierQuotation::class, 'route' => 'supplier-quotation-entry', 'permission' => 'supplier_quotation_entry', 'date' => 'quotation_date', 'title' => 'Supplier Quotations', 'type' => 'supplier-quotation'],
            'supply_orders' => ['model' => SupplyOrder::class, 'route' => 'supply-orders', 'permission' => 'supply_orders', 'date' => 'issue_date', 'title' => 'Supply Orders', 'type' => 'supply-order'],
            'goods_receipt_inspections' => ['model' => GoodsReceiptInspection::class, 'route' => 'goods-receipt-inspection', 'permission' => 'goods_receipt_inspection', 'date' => 'inspection_at', 'title' => 'Purchase Inspections', 'type' => 'goods-receipt-inspection'],
            'goods_receipts' => ['model' => UnpricedInventoryReceipt::class, 'route' => 'goods-receipt-notes', 'permission' => 'goods_receipt_notes', 'date' => 'document_date', 'title' => 'Goods Receipt Notes', 'type' => 'goods-receipt'],
            'purchase_returns' => ['model' => PurchaseReturn::class, 'route' => 'purchase-returns', 'permission' => 'purchase_returns', 'date' => 'return_date', 'title' => 'Purchase Returns', 'type' => 'purchase-return'],
            default => abort(404),
        };
    }

    public function query(Request $request, string $screen): Builder
    {
        $definition = self::definition($screen);
        $context = $this->context->snapshot($request);
        $isAdministrativeBranch = Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
        $query = $definition['model']::query()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->when(! $isAdministrativeBranch && $screen === 'supply_orders', fn (Builder $query) => $query->whereHas('branchStore', fn (Builder $stores) => $stores->where('branch_id', $context['branch_id'])))
            ->when(! $isAdministrativeBranch && $screen !== 'supply_orders', fn (Builder $query) => $query->where('branch_id', $context['branch_id']));
        $trash = $request->string('trash_filter')->toString();
        if ($request->user()?->can('purchases.'.$definition['permission'].'.view_trashed')) {
            if ($trash === 'trashed') {
                $query->onlyTrashed();
            } elseif ($trash === 'all') {
                $query->withTrashed();
            }
        }
        if ($screen === 'goods_receipts') {
            $query->whereNotNull('purchase_order_id');
        }
        if ($screen === 'supply_orders' && ! $isAdministrativeBranch) {
            $query->where('status', '<>', SupplyOrder::StatusDraft);
        }
        if ($request->filled('status')) {
            $statusColumn = match ($screen) {
                'goods_receipts' => 'posting_status',
                'goods_receipt_inspections' => 'result',
                default => 'status',
            };
            $query->where($statusColumn, $request->string('status')->toString());
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
            if ($request->filled($filter) && $this->dates->isValidDate($request->input($filter))) {
                $query->whereDate($definition['date'], $operator, $this->dates->normalizeForStorage($request->input($filter)));
            }
        }
        if ($request->filled('supplier_doc_num') && in_array($screen, ['supplier_quotations', 'supply_orders', 'goods_receipt_inspections', 'goods_receipts', 'purchase_returns'], true)) {
            if ($screen === 'goods_receipt_inspections') {
                $supplierDocNum = $request->string('supplier_doc_num')->toString();
                $query->where(function (Builder $inspections) use ($supplierDocNum): void {
                    $inspections
                        ->whereHas('purchaseOrder.supplier', fn (Builder $suppliers) => $suppliers->where('doc_num', $supplierDocNum))
                        ->orWhereHas('supplyOrder.supplier', fn (Builder $suppliers) => $suppliers->where('doc_num', $supplierDocNum))
                        ->orWhereHas('receipt.supplier', fn (Builder $suppliers) => $suppliers->where('doc_num', $supplierDocNum));
                });
            } else {
                $query->whereHas('supplier', fn ($supplier) => $supplier->where('doc_num', $request->input('supplier_doc_num')));
            }
        }
        if ($screen === 'goods_receipt_inspections' && $request->filled('branch_doc_num')) {
            $query->whereHas('branch', fn (Builder $branches) => $branches->where('doc_num', $request->string('branch_doc_num')->toString()));
        }
        if ($screen === 'goods_receipt_inspections' && $request->filled('branch_store_uuid')) {
            $storeUuid = $request->string('branch_store_uuid')->toString();
            $query->where(function (Builder $inspections) use ($storeUuid): void {
                $inspections
                    ->whereHas('purchaseOrder.branchStore', fn (Builder $stores) => $stores->where('public_uuid', $storeUuid))
                    ->orWhereHas('supplyOrder.branchStore', fn (Builder $stores) => $stores->where('public_uuid', $storeUuid));
            });
        }

        return $query;
    }

    public function json(Request $request, string $screen): JsonResponse
    {
        $definition = self::definition($screen);
        abort_unless($request->user()?->can('purchases.'.$definition['permission'].'.view'), 403);
        $context = $this->context->snapshot($request);
        $isAdministrativeBranch = Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
        $query = $this->query($request, $screen)->withCount('lines');
        $relations = match ($screen) {
            'purchase_requisitions' => ['requesterEmployee', 'branch', 'branchStore'],
            'request_for_quotations' => ['requisition', 'suppliers'],
            'supplier_quotations' => ['supplier', 'requestForQuotation', 'purchaseRequisition', 'purchaseOrder'],
            'supply_orders' => ['supplier', 'purchaseOrder', 'purchaseInvoice'],
            'goods_receipt_inspections' => [
                'branch',
                'receipt.supplier',
                'lines.receiptLines.receipt',
                'purchaseOrder.supplier',
                'purchaseOrder.branchStore.branch',
                'supplyOrder.supplier',
                'supplyOrder.branchStore.branch',
            ],
            'goods_receipts' => ['supplier', 'purchaseOrder', 'inspection', 'sourceInspection'],
            default => ['supplier', 'receipt'],
        };
        $query->with($relations);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $screen, $definition): void {
                $table = $query->getModel()->getTable();
                $related = match ($screen) {
                    'purchase_requisitions' => [['hr_employees', 'requester_employee_id', ['doc_num', 'full_name', 'name']], ['branches', 'branch_id', ['name']], ['branch_stores', 'branch_store_id', ['name']]],
                    'request_for_quotations' => [['purchase_requisitions', 'purchase_requisition_id', ['doc_num']]],
                    'supplier_quotations' => [['suppliers', 'supplier_id', ['name', 'doc_num']], ['request_for_quotations', 'request_for_quotation_id', ['doc_num']], ['purchase_requisitions', 'purchase_requisition_id', ['doc_num']], ['purchase_orders', 'purchase_order_id', ['doc_num']]],
                    'supply_orders' => [['suppliers', 'supplier_id', ['name', 'doc_num']], ['purchase_orders', 'purchase_order_id', ['doc_num']], ['purchase_invoices', 'purchase_invoice_id', ['doc_num']]],
                    'goods_receipt_inspections' => [['purchase_orders', 'purchase_order_id', ['doc_num']], ['supply_orders', 'supply_order_id', ['doc_num']], ['unpriced_inventory_receipts', 'receipt_id', ['doc_num']]],
                    'goods_receipts' => [['suppliers', 'supplier_id', ['name', 'doc_num']], ['purchase_orders', 'purchase_order_id', ['doc_num']]],
                    default => [['suppliers', 'supplier_id', ['name', 'doc_num']], ['unpriced_inventory_receipts', 'receipt_id', ['doc_num']]],
                };
                $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('search.value')), [
                    'text' => [$table.'.doc_num'], 'dates' => [$table.'.'.$definition['date']],
                    'exists' => array_map(fn (array $relation): array => ['table' => $relation[0], 'first' => $relation[0].'.id', 'second' => $table.'.'.$relation[1], 'columns' => array_map(fn (string $column): string => $relation[0].'.'.$column, $relation[2])], $related),
                ]);
            })
            ->addColumn('checkbox', fn ($record): string => view('modules.purchases.procurement.partials.index-checkbox', ['record' => $record, 'screen' => $screen, 'isAdministrativeBranch' => $isAdministrativeBranch])->render())
            ->editColumn('doc_num', fn ($record): string => '<a class="dt-code-value fw-semibold" href="'.e(route('admin.purchases.'.$definition['route'].'.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->addColumn('date', fn ($record): string => $this->dates->formatDate($record->{$definition['date']}))
            ->addColumn('party', fn ($record): string => match ($screen) {
                'purchase_requisitions' => $record->requesterEmployee?->full_name ?: $record->requesterEmployee?->name ?: __('common.empty_value'),
                'request_for_quotations' => $record->suppliers->pluck('name')->join('، '),
                'goods_receipt_inspections' => $record->purchaseOrder?->supplier?->name ?? $record->supplyOrder?->supplier?->name ?? $record->receipt?->supplier?->name ?? __('common.empty_value'),
                default => $record->supplier?->name ?? __('common.empty_value'),
            })
            ->addColumn('source', fn ($record): string => match ($screen) {
                'purchase_requisitions' => collect([$record->branch?->name, $record->branchStore?->name])->filter()->join(' — '),
                'request_for_quotations' => $record->requisition?->doc_num ?? '',
                'supplier_quotations' => $record->source_doc_num ?? $record->requestForQuotation?->doc_num ?? '',
                'supply_orders' => $record->source_doc_num,
                'goods_receipt_inspections' => $record->source_doc_num ?? $record->purchaseOrder?->doc_num ?? $record->supplyOrder?->doc_num ?? $record->receipt?->doc_num ?? '',
                'goods_receipts' => $record->purchaseOrder?->doc_num ?? '',
                default => $record->receipt?->doc_num ?? '',
            })
            ->addColumn('location', fn ($record): string => match ($screen) {
                'goods_receipt_inspections' => collect([
                    $record->branch?->name ?? $record->purchaseOrder?->branchStore?->branch?->name ?? $record->supplyOrder?->branchStore?->branch?->name,
                    $record->purchaseOrder?->branchStore?->name ?? $record->supplyOrder?->branchStore?->name,
                ])->filter()->join(' — '),
                default => '',
            })
            ->editColumn('status', fn ($record): string => view('modules.purchases.procurement.partials.index-status', ['record' => $record, 'screen' => $screen])->render())
            ->editColumn('created_at', fn ($record): string => $this->dates->formatDateTime($record->created_at))
            ->editColumn('updated_at', fn ($record): string => $this->dates->formatDateTime($record->updated_at))
            ->addColumn('actions', fn ($record): string => view('modules.purchases.procurement.partials.index-actions', [
                'record' => $record,
                'screen' => $screen,
                'definition' => $definition,
                'isAdministrativeBranch' => $isAdministrativeBranch,
                'activeBranchId' => (int) $context['branch_id'],
            ])->render())
            ->addColumn('view_url', fn ($record): string => route('admin.purchases.'.$definition['route'].'.show', $record->doc_num))
            ->addColumn('edit_url', fn ($record): ?string => Route::has('admin.purchases.'.$definition['route'].'.edit')
                ? route('admin.purchases.'.$definition['route'].'.edit', $record->doc_num)
                : null)
            ->addColumn('can_edit', fn ($record): bool => $this->canEditDocument($request, $record, $screen, $definition, (int) $context['branch_id']))
            ->orderColumn('status', match ($screen) {
                'goods_receipts' => 'posting_status $1',
                'goods_receipt_inspections' => 'result $1',
                default => 'status $1',
            })
            ->orderColumn('date', $definition['date'].' $1')
            ->orderColumn('doc_num', 'doc_number $1')
            ->only(['checkbox', 'doc_num', 'date', 'party', 'source', 'location', 'lines_count', 'status', 'created_at', 'updated_at', 'actions', 'view_url', 'edit_url', 'can_edit'])
            ->rawColumns(['checkbox', 'doc_num', 'status', 'actions'])->toJson();
    }

    /** @param array<string, string> $definition */
    private function canEditDocument(Request $request, mixed $record, string $screen, array $definition, int $activeBranchId): bool
    {
        $editableDraft = $record->status === 'draft'
            && ($screen !== 'goods_receipts' || ($record->posting_status === 'unposted'
                && (in_array($record->qc_status, ['pending_inspection', 'not_required'], true) || $record->sourceInspection !== null)
                && $record->inspection === null));

        return ! $record->trashed()
            && $editableDraft
            && (int) ($record->branch_id ?? 0) === $activeBranchId
            && Route::has('admin.purchases.'.$definition['route'].'.edit')
            && (bool) $request->user()?->can('purchases.'.$definition['permission'].'.edit');
    }
}
