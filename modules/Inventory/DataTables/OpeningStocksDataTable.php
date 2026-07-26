<?php

namespace Modules\Inventory\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\Branch;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Inventory\Models\OpeningStock;
use Yajra\DataTables\Facades\DataTables;

class OpeningStocksDataTable
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
        $contextAllowsOpeningStock = $context['branch_id']
            && Branch::query()
                ->whereKey($context['branch_id'])
                ->whereIn('type', [Branch::TypeWarehouse, Branch::TypeFactory])
                ->exists();
        $query = match ($this->trashFilter($request)) {
            'trashed' => OpeningStock::onlyTrashed(),
            'all' => OpeningStock::withTrashed(),
            default => OpeningStock::query(),
        };

        $query
            ->when(
                $context['company_id'] && $context['financial_period_id'] && $context['branch_id'] && $contextAllowsOpeningStock,
                fn ($query) => $query
                    ->where('inventory_opening_stocks.company_id', $context['company_id'])
                    ->where('inventory_opening_stocks.financial_period_id', $context['financial_period_id'])
                    ->where('inventory_opening_stocks.branch_id', $context['branch_id']),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->leftJoin('branches', 'branches.id', '=', 'inventory_opening_stocks.branch_id')
            ->leftJoin('branch_halls', 'branch_halls.id', '=', 'inventory_opening_stocks.branch_hall_id')
            ->leftJoin('branch_stores', 'branch_stores.id', '=', 'inventory_opening_stocks.branch_store_id')
            ->leftJoin('users as approved_users', 'approved_users.id', '=', 'inventory_opening_stocks.approved_by')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'inventory_opening_stocks.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'inventory_opening_stocks.updated_by')
            ->select([
                'inventory_opening_stocks.*',
                'branches.name as branch_name',
                'branch_halls.name as hall_name',
                'branch_stores.name as store_name',
                'approved_users.name as approved_by_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ])
            ->selectSub(
                DB::table('inventory_opening_stock_lines')
                    ->selectRaw('COUNT(*)')
                    ->whereNull('inventory_opening_stock_lines.deleted_at')
                    ->whereColumn('inventory_opening_stock_lines.opening_stock_id', 'inventory_opening_stocks.id'),
                'lines_count'
            )
            ->selectSub(
                DB::table('inventory_opening_stock_lines')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereNull('inventory_opening_stock_lines.deleted_at')
                    ->whereColumn('inventory_opening_stock_lines.opening_stock_id', 'inventory_opening_stocks.id'),
                'total_quantity'
            );

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'inventory_opening_stocks.doc_num',
                            'inventory_opening_stocks.notes',
                            'branches.name',
                            'branch_halls.name',
                            'branch_stores.name',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (OpeningStock $record): string => view('modules.inventory.opening-stocks.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (OpeningStock $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.inventory.opening-stocks.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('document_date', fn (OpeningStock $record): string => $this->plainText($record->document_date?->format($dateFormat) ?? ''))
            ->addColumn('branch', fn (OpeningStock $record): string => $this->ellipsisText($record->branch_name))
            ->addColumn('hall', fn (OpeningStock $record): string => $this->ellipsisText($record->hall_name ?: __('common.empty_value')))
            ->addColumn('store', fn (OpeningStock $record): string => $this->ellipsisText($record->store_name ?: __('common.empty_value')))
            ->addColumn('lines_count', fn (OpeningStock $record): string => $this->plainText($this->numbers->format($record->lines_count)))
            ->addColumn('total_quantity', fn (OpeningStock $record): string => $this->plainText($this->numbers->format($record->total_quantity)))
            ->addColumn('document_status', fn (OpeningStock $record): string => view('modules.inventory.opening-stocks.partials.state', ['record' => $record, 'type' => 'document'])->render())
            ->addColumn('approval_status', fn (OpeningStock $record): string => view('modules.inventory.opening-stocks.partials.state', ['record' => $record, 'type' => 'approval'])->render())
            ->editColumn('approved_by', fn (OpeningStock $record): string => $this->ellipsisText($record->approved_by_name ?: __('common.empty_value')))
            ->editColumn('approved_at', fn (OpeningStock $record): string => $this->plainText($record->approved_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('created_by', fn (OpeningStock $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (OpeningStock $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('updated_by', fn (OpeningStock $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (OpeningStock $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (OpeningStock $record): string => view('modules.inventory.opening-stocks.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (OpeningStock $record): string => route('admin.inventory.opening-stocks.edit', $record->doc_num))
            ->addColumn('can_edit', fn (OpeningStock $record): bool => ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can('inventory.opening_stocks.edit'))
            ->addColumn('edit_blocked_message', fn (OpeningStock $record): string => $this->editBlockedMessage($record, (bool) $request->user()?->can('inventory.opening_stocks.edit')))
            ->orderColumn('doc_num', 'inventory_opening_stocks.doc_number $1')
            ->orderColumn('document_date', 'inventory_opening_stocks.document_date $1')
            ->orderColumn('branch', 'branches.name $1')
            ->orderColumn('hall', 'branch_halls.name $1')
            ->orderColumn('store', 'branch_stores.name $1')
            ->orderColumn('lines_count', 'lines_count $1')
            ->orderColumn('total_quantity', 'total_quantity $1')
            ->orderColumn('document_status', 'inventory_opening_stocks.status $1')
            ->orderColumn('approval_status', 'inventory_opening_stocks.approved $1')
            ->orderColumn('approved_by', 'approved_users.name $1')
            ->orderColumn('approved_at', 'inventory_opening_stocks.approved_at $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'inventory_opening_stocks.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'inventory_opening_stocks.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('financial_period_id')
            ->removeColumn('branch_id')
            ->removeColumn('branch_hall_id')
            ->removeColumn('branch_store_id')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'branch', 'hall', 'store', 'document_status', 'approval_status', 'approved_by', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function editBlockedMessage(OpeningStock $record, bool $hasPermission): string
    {
        if (! $hasPermission) {
            return __('inventory.opening_stocks.messages.edit_permission_denied');
        }

        if ($record->isApproved()) {
            return __('inventory.opening_stocks.messages.approved_edit_forbidden');
        }

        if ($record->isClosed()) {
            return __('inventory.opening_stocks.messages.closed_edit_forbidden');
        }

        return __('inventory.opening_stocks.messages.approved_not_editable');
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('inventory.opening_stocks.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }
}
