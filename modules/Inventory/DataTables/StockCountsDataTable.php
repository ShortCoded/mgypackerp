<?php

namespace Modules\Inventory\DataTables;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Inventory\Models\StockCount;
use Yajra\DataTables\Facades\DataTables;

class StockCountsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingContextService $context,
        private readonly NumericFormatService $numbers,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $context = $this->context->snapshot($request);
        $query = match ($this->trashFilter($request)) {
            'trashed' => StockCount::onlyTrashed(),
            'all' => StockCount::withTrashed(),
            default => StockCount::query(),
        };

        $query
            ->when(
                $context['company_id'] && $context['financial_period_id'] && $context['branch_id'],
                fn ($query) => $query->forContext((int) $context['company_id'], (int) $context['financial_period_id'], (int) $context['branch_id']),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->leftJoin('branch_stores', 'branch_stores.id', '=', 'inventory_stock_counts.branch_store_id')
            ->leftJoin('warehouse_locations', 'warehouse_locations.id', '=', 'inventory_stock_counts.warehouse_location_id')
            ->leftJoin('users as approved_users', 'approved_users.id', '=', 'inventory_stock_counts.approved_by')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'inventory_stock_counts.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'inventory_stock_counts.updated_by')
            ->select([
                'inventory_stock_counts.*',
                'branch_stores.name as store_name',
                'warehouse_locations.code as location_code',
                'warehouse_locations.name as location_name',
                'approved_users.name as approved_by_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ])
            ->selectSub($this->lineAggregate('COUNT(*)'), 'lines_count')
            ->selectSub($this->lineAggregate('COALESCE(SUM(system_quantity), 0)'), 'system_total')
            ->selectSub($this->lineAggregate('COALESCE(SUM(physical_quantity), 0)'), 'physical_total')
            ->selectSub($this->lineAggregate('COALESCE(SUM(variance_quantity), 0)'), 'variance_total');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'inventory_stock_counts.doc_num',
                            'inventory_stock_counts.notes',
                            'branch_stores.name',
                            'warehouse_locations.code',
                            'warehouse_locations.name',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (StockCount $record): string => view('modules.inventory.stock-counts.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (StockCount $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.inventory.stock-counts.show', $record)).'">'.e($record->doc_num).'</a>')
            ->editColumn('count_date', fn (StockCount $record): string => $this->plainText($record->count_date?->format($dateFormat) ?? ''))
            ->addColumn('store', fn (StockCount $record): string => $this->ellipsisText($record->store_name))
            ->addColumn('location', fn (StockCount $record): string => $this->ellipsisText(trim(implode(' — ', array_filter([$record->location_code, $record->location_name]))) ?: __('common.empty_value')))
            ->addColumn('lines_count', fn (StockCount $record): string => $this->plainText($this->numbers->format($record->lines_count)))
            ->addColumn('system_total', fn (StockCount $record): string => $this->plainText($this->numbers->format($record->system_total)))
            ->addColumn('physical_total', fn (StockCount $record): string => $this->plainText($this->numbers->format($record->physical_total)))
            ->addColumn('variance_total', fn (StockCount $record): string => $this->plainText($this->numbers->format($record->variance_total)))
            ->addColumn('status_label', fn (StockCount $record): string => view('modules.inventory.stock-counts.partials.state', ['record' => $record])->render())
            ->editColumn('approved_by', fn (StockCount $record): string => $this->ellipsisText($record->approved_by_name ?: __('common.empty_value')))
            ->editColumn('approved_at', fn (StockCount $record): string => $this->plainText($record->approved_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('created_by', fn (StockCount $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (StockCount $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('updated_by', fn (StockCount $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (StockCount $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (StockCount $record): string => view('modules.inventory.stock-counts.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (StockCount $record): string => route('admin.inventory.stock-counts.edit', $record))
            ->addColumn('can_edit', fn (StockCount $record): bool => $record->isEditable() && (bool) $request->user()?->can('inventory.stock_counts.edit'))
            ->addColumn('edit_blocked_message', fn (): string => __('inventory.stock_counts.messages.approved_edit_forbidden'))
            ->orderColumn('doc_num', 'inventory_stock_counts.doc_number $1')
            ->orderColumn('count_date', 'inventory_stock_counts.count_date $1')
            ->orderColumn('store', 'branch_stores.name $1')
            ->orderColumn('location', 'warehouse_locations.code $1')
            ->orderColumn('lines_count', 'lines_count $1')
            ->orderColumn('system_total', 'system_total $1')
            ->orderColumn('physical_total', 'physical_total $1')
            ->orderColumn('variance_total', 'variance_total $1')
            ->orderColumn('status_label', 'inventory_stock_counts.status $1')
            ->orderColumn('approved_by', 'approved_users.name $1')
            ->orderColumn('approved_at', 'inventory_stock_counts.approved_at $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'inventory_stock_counts.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'inventory_stock_counts.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('financial_period_id')
            ->removeColumn('branch_id')
            ->removeColumn('branch_store_id')
            ->removeColumn('warehouse_location_id')
            ->removeColumn('adjustment_document_id')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'store', 'location', 'status_label', 'approved_by', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function lineAggregate(string $expression): Builder
    {
        return DB::table('inventory_stock_count_lines')
            ->selectRaw($expression)
            ->whereNull('inventory_stock_count_lines.deleted_at')
            ->whereColumn('inventory_stock_count_lines.inventory_stock_count_id', 'inventory_stock_counts.id');
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('inventory.stock_counts.view_trashed')) {
            return 'active';
        }

        $value = $request->string('trash_filter')->toString();

        return in_array($value, ['active', 'trashed', 'all'], true) ? $value : 'active';
    }
}
