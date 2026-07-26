<?php

namespace Modules\Purchases\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Purchases\Models\PurchaseOrder;
use Yajra\DataTables\Facades\DataTables;

class PurchaseOrdersDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingContextService $operatingContext,
        private readonly NumericFormatService $numbers,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $context = $this->operatingContext->snapshot($request);
        $query = match ($this->trashFilter($request)) {
            'trashed' => PurchaseOrder::onlyTrashed(),
            'all' => PurchaseOrder::withTrashed(),
            default => PurchaseOrder::query(),
        };

        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $context['branch_id']) {
            $query->whereRaw('1 = 0');
        } else {
            $query
                ->where('purchase_orders.company_id', (int) $context['company_id'])
                ->where('purchase_orders.financial_period_id', (int) $context['financial_period_id'])
                ->where('purchase_orders.branch_id', (int) $context['branch_id']);
        }

        $query
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->leftJoin('branch_stores', 'branch_stores.id', '=', 'purchase_orders.branch_store_id')
            ->leftJoin('financial_periods', 'financial_periods.id', '=', 'purchase_orders.financial_period_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'purchase_orders.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'purchase_orders.created_by')
            ->leftJoin('users as approved_users', 'approved_users.id', '=', 'purchase_orders.approved_by')
            ->select([
                'purchase_orders.id',
                'purchase_orders.doc_number',
                'purchase_orders.doc_num',
                'purchase_orders.company_id',
                'purchase_orders.document_date',
                'purchase_orders.expected_delivery_date',
                'purchase_orders.supplier_reference',
                'purchase_orders.status',
                'purchase_orders.total_ordered_quantity',
                'purchase_orders.total_amount',
                'purchase_orders.created_at',
                'purchase_orders.updated_at',
                'purchase_orders.approved_at',
                'purchase_orders.deleted_at',
                'suppliers.name as supplier_name',
                'suppliers.doc_num as supplier_doc_num',
                'branch_stores.name as store_name',
                'financial_periods.name as financial_period_name',
                'financial_periods.doc_num as financial_period_doc_num',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'created_users.name as created_by_name',
                'approved_users.name as approved_by_name',
            ]);

        $this->applyFilters($query, $request);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'purchase_orders.doc_num',
                            'purchase_orders.supplier_reference',
                            'suppliers.name',
                            'suppliers.doc_num',
                            'branch_stores.name',
                            'financial_periods.name',
                            'financial_periods.doc_num',
                            'currencies.code',
                            'currencies.name',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (PurchaseOrder $record): string => view('modules.purchases.purchase-orders.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (PurchaseOrder $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.purchases.purchase-orders.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('document_date', fn (PurchaseOrder $record): string => $this->plainText($record->document_date?->format($dateFormat) ?? ''))
            ->addColumn('supplier', fn (PurchaseOrder $record): string => $this->ellipsisText(trim(implode(' / ', array_filter([$record->supplier_doc_num, $record->supplier_name])))))
            ->addColumn('branch_store', fn (PurchaseOrder $record): string => $this->ellipsisText($record->store_name ?: __('common.empty_value')))
            ->addColumn('currency', fn (PurchaseOrder $record): string => $this->ellipsisText(trim(implode(' / ', array_filter([$record->currency_code, $record->currency_name])))))
            ->editColumn('total_ordered_quantity', fn (PurchaseOrder $record): string => $this->plainText($this->numbers->format($record->total_ordered_quantity)))
            ->editColumn('total_amount', fn (PurchaseOrder $record): string => $this->plainText($this->numbers->format($record->total_amount)))
            ->editColumn('expected_delivery_date', fn (PurchaseOrder $record): string => $this->plainText($record->expected_delivery_date?->format($dateFormat) ?? ''))
            ->editColumn('status', fn (PurchaseOrder $record): string => view('modules.purchases.purchase-orders.partials.status', ['record' => $record])->render())
            ->addColumn('created_by', fn (PurchaseOrder $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (PurchaseOrder $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('approved_by', fn (PurchaseOrder $record): string => $this->ellipsisText($record->approved_by_name ?: __('common.empty_value')))
            ->editColumn('approved_at', fn (PurchaseOrder $record): string => $this->plainText($record->approved_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (PurchaseOrder $record): string => view('modules.purchases.purchase-orders.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (PurchaseOrder $record): string => route('admin.purchases.purchase-orders.edit', $record->doc_num))
            ->addColumn('can_edit', fn (PurchaseOrder $record): bool => ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can('purchase_orders.edit'))
            ->orderColumn('doc_num', 'purchase_orders.doc_number $1')
            ->orderColumn('document_date', 'purchase_orders.document_date $1')
            ->orderColumn('supplier', 'suppliers.name $1')
            ->orderColumn('branch_store', 'branch_stores.name $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('total_ordered_quantity', 'purchase_orders.total_ordered_quantity $1')
            ->orderColumn('total_amount', 'purchase_orders.total_amount $1')
            ->orderColumn('expected_delivery_date', 'purchase_orders.expected_delivery_date $1')
            ->orderColumn('status', 'purchase_orders.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'purchase_orders.created_at $1')
            ->orderColumn('approved_by', 'approved_users.name $1')
            ->orderColumn('approved_at', 'purchase_orders.approved_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->rawColumns(['checkbox', 'doc_num', 'supplier', 'branch_store', 'currency', 'status', 'created_by', 'approved_by', 'actions'])
            ->toJson();
    }

    private function applyFilters(mixed $query, Request $request): void
    {
        if ($request->filled('supplier_doc_num')) {
            $query->where('suppliers.doc_num', $request->string('supplier_doc_num')->toString());
        }

        if ($request->filled('status')) {
            $query->where('purchase_orders.status', $request->string('status')->toString());
        }

        if ($request->filled('currency_doc_num')) {
            $query->where('currencies.doc_num', $request->string('currency_doc_num')->toString());
        }

        $dates = app(DateFormatService::class);

        if ($request->filled('date_from') && $dates->isValidDate($request->string('date_from')->toString())) {
            $query->whereDate('purchase_orders.document_date', '>=', $dates->normalizeForStorage($request->string('date_from')->toString()));
        }

        if ($request->filled('date_to') && $dates->isValidDate($request->string('date_to')->toString())) {
            $query->whereDate('purchase_orders.document_date', '<=', $dates->normalizeForStorage($request->string('date_to')->toString()));
        }
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('purchase_orders.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }
}
