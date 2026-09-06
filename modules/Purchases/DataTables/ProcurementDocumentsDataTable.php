<?php

namespace Modules\Purchases\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\SupplierQuotation;
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
            'goods_receipts' => ['model' => UnpricedInventoryReceipt::class, 'route' => 'goods-receipt-notes', 'permission' => 'goods_receipt_notes', 'date' => 'document_date', 'title' => 'Goods Receipt Notes', 'type' => 'goods-receipt'],
            'purchase_returns' => ['model' => PurchaseReturn::class, 'route' => 'purchase-returns', 'permission' => 'purchase_returns', 'date' => 'return_date', 'title' => 'Purchase Returns', 'type' => 'purchase-return'],
            default => abort(404),
        };
    }

    public function query(Request $request, string $screen): Builder
    {
        $definition = self::definition($screen);
        $context = $this->context->snapshot($request);
        $query = $definition['model']::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('financial_period_id', $context['financial_period_id']);
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
        if ($request->filled('status')) {
            $query->where($screen === 'goods_receipts' ? 'posting_status' : 'status', $request->string('status')->toString());
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $filter => $operator) {
            if ($request->filled($filter) && $this->dates->isValidDate($request->input($filter))) {
                $query->whereDate($definition['date'], $operator, $this->dates->normalizeForStorage($request->input($filter)));
            }
        }
        if ($request->filled('supplier_doc_num') && in_array($screen, ['supplier_quotations', 'goods_receipts', 'purchase_returns'], true)) {
            $query->whereHas('supplier', fn ($supplier) => $supplier->where('doc_num', $request->input('supplier_doc_num')));
        }

        return $query;
    }

    public function json(Request $request, string $screen): JsonResponse
    {
        $definition = self::definition($screen);
        abort_unless($request->user()?->can('purchases.'.$definition['permission'].'.view'), 403);
        $query = $this->query($request, $screen)->withCount('lines');
        $relations = match ($screen) {
            'purchase_requisitions' => ['requesterEmployee', 'branch', 'branchStore'],
            'request_for_quotations' => ['requisition'],
            'supplier_quotations' => ['supplier', 'requestForQuotation'],
            'goods_receipts' => ['supplier', 'purchaseOrder'],
            default => ['supplier', 'receipt'],
        };
        $query->with($relations);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $this->search->applyMultiTermSearch($query, $this->search->terms($request->input('search.value')), ['text' => ['doc_num']]);
            })
            ->addColumn('checkbox', fn ($record): string => view('modules.purchases.procurement.partials.index-checkbox', ['record' => $record, 'screen' => $screen])->render())
            ->editColumn('doc_num', fn ($record): string => '<a class="dt-code-value fw-semibold" href="'.e(route('admin.purchases.'.$definition['route'].'.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->addColumn('date', fn ($record): string => $this->dates->formatDate($record->{$definition['date']}))
            ->addColumn('party', fn ($record): string => $screen === 'purchase_requisitions' ? ($record->requesterEmployee?->full_name ?: $record->requesterEmployee?->name ?: __('common.empty_value')) : ($record->supplier?->name ?? __('common.empty_value')))
            ->addColumn('source', fn ($record): string => match ($screen) {
                'purchase_requisitions' => $record->branch?->name ?? '',
                'request_for_quotations' => $record->requisition?->doc_num ?? '',
                'supplier_quotations' => $record->requestForQuotation?->doc_num ?? '',
                'goods_receipts' => $record->purchaseOrder?->doc_num ?? '',
                default => $record->receipt?->doc_num ?? '',
            })
            ->editColumn('status', fn ($record): string => view('modules.purchases.procurement.partials.index-status', ['record' => $record, 'screen' => $screen])->render())
            ->editColumn('created_at', fn ($record): string => $this->dates->formatDateTime($record->created_at))
            ->editColumn('updated_at', fn ($record): string => $this->dates->formatDateTime($record->updated_at))
            ->addColumn('actions', fn ($record): string => view('modules.purchases.procurement.partials.index-actions', ['record' => $record, 'screen' => $screen, 'definition' => $definition])->render())
            ->orderColumn('date', $definition['date'].' $1')
            ->orderColumn('doc_num', 'doc_number $1')
            ->only(['checkbox', 'doc_num', 'date', 'party', 'source', 'lines_count', 'status', 'created_at', 'updated_at', 'actions'])
            ->rawColumns(['checkbox', 'doc_num', 'status', 'actions'])->toJson();
    }
}
