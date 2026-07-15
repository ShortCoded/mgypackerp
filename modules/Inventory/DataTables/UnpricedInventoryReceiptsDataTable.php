<?php

namespace Modules\Inventory\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Yajra\DataTables\Facades\DataTables;

class UnpricedInventoryReceiptsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $context = $this->operatingContext->snapshot($request);
        $allowedBranchIds = $this->operatingContext
            ->allowedBranchQueryForCurrentCompany($request)
            ->pluck('branches.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $query = match ($this->trashFilter($request)) {
            'trashed' => UnpricedInventoryReceipt::onlyTrashed(),
            'all' => UnpricedInventoryReceipt::withTrashed(),
            default => UnpricedInventoryReceipt::query(),
        };

        $query
            ->when(
                $context['company_id'] && $context['financial_period_id'] && $allowedBranchIds !== [],
                fn ($query) => $query
                    ->where('unpriced_inventory_receipts.company_id', $context['company_id'])
                    ->where('unpriced_inventory_receipts.financial_period_id', $context['financial_period_id'])
                    ->whereIn('unpriced_inventory_receipts.branch_id', $allowedBranchIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->leftJoin('financial_periods', 'financial_periods.id', '=', 'unpriced_inventory_receipts.financial_period_id')
            ->leftJoin('branches', 'branches.id', '=', 'unpriced_inventory_receipts.branch_id')
            ->leftJoin('branch_halls', 'branch_halls.id', '=', 'unpriced_inventory_receipts.branch_hall_id')
            ->leftJoin('branch_stores', 'branch_stores.id', '=', 'unpriced_inventory_receipts.branch_store_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'unpriced_inventory_receipts.supplier_id')
            ->leftJoin('users as approved_users', 'approved_users.id', '=', 'unpriced_inventory_receipts.approved_by')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'unpriced_inventory_receipts.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'unpriced_inventory_receipts.updated_by')
            ->select([
                'unpriced_inventory_receipts.*',
                'financial_periods.name as financial_period_name',
                'branches.name as branch_name',
                'branch_halls.name as hall_name',
                'branch_stores.name as store_name',
                'suppliers.doc_num as supplier_doc_num',
                'suppliers.name as supplier_name',
                'approved_users.name as approved_by_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ])
            ->selectSub(
                DB::table('unpriced_inventory_receipt_lines')
                    ->selectRaw('COUNT(*)')
                    ->whereNull('unpriced_inventory_receipt_lines.deleted_at')
                    ->whereColumn('unpriced_inventory_receipt_lines.receipt_id', 'unpriced_inventory_receipts.id'),
                'lines_count'
            )
            ->selectSub(
                DB::table('unpriced_inventory_receipt_lines')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereNull('unpriced_inventory_receipt_lines.deleted_at')
                    ->whereColumn('unpriced_inventory_receipt_lines.receipt_id', 'unpriced_inventory_receipts.id'),
                'total_quantity'
            );

        $this->applyFilters($query, $request);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'unpriced_inventory_receipts.doc_num',
                            'unpriced_inventory_receipts.reference_number',
                            'unpriced_inventory_receipts.notes',
                            'financial_periods.name',
                            'branches.name',
                            'branch_halls.name',
                            'branch_stores.name',
                            'suppliers.doc_num',
                            'suppliers.name',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (UnpricedInventoryReceipt $record): string => view('modules.inventory.unpriced-inventory-receipts.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (UnpricedInventoryReceipt $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.inventory.unpriced-inventory-receipts.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('document_date', fn (UnpricedInventoryReceipt $record): string => $this->plainText($record->document_date?->format($dateFormat) ?? ''))
            ->addColumn('financial_period', fn (UnpricedInventoryReceipt $record): string => $this->ellipsisText($record->financial_period_name))
            ->addColumn('branch', fn (UnpricedInventoryReceipt $record): string => $this->ellipsisText($record->branch_name))
            ->addColumn('hall', fn (UnpricedInventoryReceipt $record): string => $this->ellipsisText($record->hall_name ?: __('common.empty_value')))
            ->addColumn('store', fn (UnpricedInventoryReceipt $record): string => $this->ellipsisText($record->store_name ?: __('common.empty_value')))
            ->addColumn('supplier_reference', fn (UnpricedInventoryReceipt $record): string => $this->ellipsisText($this->supplierReference($record)))
            ->addColumn('lines_count', fn (UnpricedInventoryReceipt $record): string => $this->plainText((string) ((int) $record->lines_count)))
            ->addColumn('total_quantity', fn (UnpricedInventoryReceipt $record): string => $this->plainText($this->formatQuantity($record->total_quantity)))
            ->addColumn('status', fn (UnpricedInventoryReceipt $record): string => view('modules.inventory.unpriced-inventory-receipts.partials.state', ['record' => $record, 'type' => 'status'])->render())
            ->addColumn('pricing_status', fn (UnpricedInventoryReceipt $record): string => view('modules.inventory.unpriced-inventory-receipts.partials.state', ['record' => $record, 'type' => 'pricing'])->render())
            ->editColumn('approved_by', fn (UnpricedInventoryReceipt $record): string => $this->ellipsisText($record->approved_by_name ?: __('common.empty_value')))
            ->editColumn('approved_at', fn (UnpricedInventoryReceipt $record): string => $this->plainText($record->approved_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('created_by', fn (UnpricedInventoryReceipt $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (UnpricedInventoryReceipt $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('updated_by', fn (UnpricedInventoryReceipt $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (UnpricedInventoryReceipt $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (UnpricedInventoryReceipt $record): string => view('modules.inventory.unpriced-inventory-receipts.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (UnpricedInventoryReceipt $record): string => route('admin.inventory.unpriced-inventory-receipts.edit', $record->doc_num))
            ->addColumn('can_edit', fn (UnpricedInventoryReceipt $record): bool => ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can('inventory.unpriced_inventory_receipts.edit'))
            ->addColumn('edit_blocked_message', fn (UnpricedInventoryReceipt $record): string => $this->editBlockedMessage($record, (bool) $request->user()?->can('inventory.unpriced_inventory_receipts.edit')))
            ->orderColumn('doc_num', 'unpriced_inventory_receipts.doc_number $1')
            ->orderColumn('document_date', 'unpriced_inventory_receipts.document_date $1')
            ->orderColumn('financial_period', 'financial_periods.name $1')
            ->orderColumn('branch', 'branches.name $1')
            ->orderColumn('hall', 'branch_halls.name $1')
            ->orderColumn('store', 'branch_stores.name $1')
            ->orderColumn('supplier_reference', 'suppliers.name $1')
            ->orderColumn('lines_count', 'lines_count $1')
            ->orderColumn('total_quantity', 'total_quantity $1')
            ->orderColumn('status', 'unpriced_inventory_receipts.status $1')
            ->orderColumn('pricing_status', 'unpriced_inventory_receipts.pricing_status $1')
            ->orderColumn('approved_by', 'approved_users.name $1')
            ->orderColumn('approved_at', 'unpriced_inventory_receipts.approved_at $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'unpriced_inventory_receipts.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'unpriced_inventory_receipts.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('financial_period_id')
            ->removeColumn('branch_id')
            ->removeColumn('branch_hall_id')
            ->removeColumn('branch_store_id')
            ->removeColumn('supplier_id')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'financial_period', 'branch', 'hall', 'store', 'supplier_reference', 'status', 'pricing_status', 'approved_by', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $dates = app(DateFormatService::class);
        $dateFrom = $request->string('date_from')->trim()->toString();
        $dateTo = $request->string('date_to')->trim()->toString();
        $financialPeriodDocNum = $request->string('financial_period_doc_num')->trim()->toString();
        $branchDocNum = $request->string('branch_doc_num')->trim()->toString();
        $supplierDocNum = $request->string('supplier_doc_num')->trim()->toString();
        $createdByDocNum = $request->string('created_by_doc_num')->trim()->toString();
        $status = $request->string('status')->trim()->toString();

        if ($dateFrom !== '' && $dates->isValidDate($dateFrom)) {
            $query->whereDate('unpriced_inventory_receipts.document_date', '>=', $dates->normalizeForStorage($dateFrom));
        }

        if ($dateTo !== '' && $dates->isValidDate($dateTo)) {
            $query->whereDate('unpriced_inventory_receipts.document_date', '<=', $dates->normalizeForStorage($dateTo));
        }

        if ($financialPeriodDocNum !== '') {
            $query->where('financial_periods.doc_num', $financialPeriodDocNum);
        }

        if ($branchDocNum !== '') {
            $query->where('branches.doc_num', $branchDocNum);
        }

        if ($supplierDocNum !== '') {
            $query->where('suppliers.doc_num', $supplierDocNum);
        }

        if ($createdByDocNum !== '') {
            $query->where('created_users.doc_num', $createdByDocNum);
        }

        if (in_array($status, [
            UnpricedInventoryReceipt::StatusDraft,
            UnpricedInventoryReceipt::StatusApproved,
            UnpricedInventoryReceipt::StatusClosed,
            UnpricedInventoryReceipt::StatusCancelled,
        ], true)) {
            $query->where('unpriced_inventory_receipts.status', $status);
        }
    }

    private function supplierReference(UnpricedInventoryReceipt $record): string
    {
        return trim(implode(' / ', array_filter([
            $record->supplier_doc_num,
            $record->supplier_name,
            $record->reference_number,
        ]))) ?: __('common.empty_value');
    }

    private function editBlockedMessage(UnpricedInventoryReceipt $record, bool $hasPermission): string
    {
        if (! $hasPermission) {
            return __('inventory.unpriced_inventory_receipts.messages.edit_permission_denied');
        }

        if ($record->isCancelled()) {
            return __('inventory.unpriced_inventory_receipts.messages.cancelled_edit_forbidden');
        }

        if ($record->isApproved()) {
            return __('inventory.unpriced_inventory_receipts.messages.approved_edit_forbidden');
        }

        if ($record->isClosed()) {
            return __('inventory.unpriced_inventory_receipts.messages.closed_edit_forbidden');
        }

        return __('inventory.unpriced_inventory_receipts.messages.locked_not_editable');
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('inventory.unpriced_inventory_receipts.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function formatQuantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.') ?: '0';
    }
}
