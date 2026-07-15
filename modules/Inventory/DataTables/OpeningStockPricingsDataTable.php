<?php

namespace Modules\Inventory\DataTables;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Inventory\Models\OpeningStockPricing;
use Yajra\DataTables\Facades\DataTables;

class OpeningStockPricingsDataTable
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
        $query = match ($this->trashFilter($request)) {
            'trashed' => OpeningStockPricing::onlyTrashed(),
            'all' => OpeningStockPricing::withTrashed(),
            default => OpeningStockPricing::query(),
        };

        $query
            ->when(
                $context['company_id'] && $context['financial_period_id'],
                fn ($query) => $query
                    ->where('inventory_opening_stock_pricings.company_id', $context['company_id'])
                    ->where('inventory_opening_stock_pricings.financial_period_id', $context['financial_period_id']),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->leftJoin('branches', 'branches.id', '=', 'inventory_opening_stock_pricings.branch_id')
            ->leftJoin('branch_halls', 'branch_halls.id', '=', 'inventory_opening_stock_pricings.branch_hall_id')
            ->leftJoin('inventory_opening_stocks', 'inventory_opening_stocks.id', '=', 'inventory_opening_stock_pricings.opening_stock_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'inventory_opening_stock_pricings.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'inventory_opening_stock_pricings.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'inventory_opening_stock_pricings.updated_by')
            ->select([
                'inventory_opening_stock_pricings.*',
                'branches.name as branch_name',
                'branch_halls.name as hall_name',
                'inventory_opening_stocks.doc_num as opening_stock_doc_num',
                'inventory_opening_stocks.document_date as opening_stock_document_date',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ])
            ->selectSub(
                DB::table('inventory_opening_stock_pricing_lines')
                    ->selectRaw('COUNT(*)')
                    ->whereNull('inventory_opening_stock_pricing_lines.deleted_at')
                    ->whereColumn('inventory_opening_stock_pricing_lines.pricing_id', 'inventory_opening_stock_pricings.id'),
                'lines_count'
            );

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'inventory_opening_stock_pricings.doc_num',
                            'inventory_opening_stock_pricings.notes',
                            'inventory_opening_stocks.doc_num',
                            'branches.name',
                            'branch_halls.name',
                            'currencies.code',
                            'currencies.name',
                        ],
                        'dates' => ['inventory_opening_stocks.document_date'],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (OpeningStockPricing $record): string => view('modules.inventory.opening-stock-pricings.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (OpeningStockPricing $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.inventory.opening-stock-pricings.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('document_date', fn (OpeningStockPricing $record): string => $this->plainText($record->document_date?->format($dateFormat) ?? ''))
            ->addColumn('branch', fn (OpeningStockPricing $record): string => $this->ellipsisText($record->branch_name))
            ->addColumn('hall', fn (OpeningStockPricing $record): string => $this->ellipsisText($record->hall_name ?: __('common.empty_value')))
            ->addColumn('opening_stock_doc_num', fn (OpeningStockPricing $record): string => $this->plainText($this->openingStockDocumentLabel($record, $dateFormat)))
            ->addColumn('currency', fn (OpeningStockPricing $record): string => $this->plainText(trim(implode(' / ', array_filter([$record->currency_code, $record->currency_name])))))
            ->editColumn('exchange_rate', fn (OpeningStockPricing $record): string => $this->plainText($this->formatNumber($record->exchange_rate, 6)))
            ->addColumn('total_amount', fn (OpeningStockPricing $record): string => $this->plainText(trim($this->formatNumber($record->total_amount).' '.($record->currency_code ?: ''))))
            ->addColumn('status', fn (OpeningStockPricing $record): string => view('modules.inventory.opening-stock-pricings.partials.state', ['record' => $record])->render())
            ->addColumn('lines_count', fn (OpeningStockPricing $record): string => $this->plainText((string) ((int) $record->lines_count)))
            ->editColumn('created_by', fn (OpeningStockPricing $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (OpeningStockPricing $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('updated_by', fn (OpeningStockPricing $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (OpeningStockPricing $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (OpeningStockPricing $record): string => view('modules.inventory.opening-stock-pricings.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (OpeningStockPricing $record): string => route('admin.inventory.opening-stock-pricings.edit', $record->doc_num))
            ->addColumn('can_edit', fn (OpeningStockPricing $record): bool => ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can('inventory.opening_stock_pricings.edit'))
            ->addColumn('edit_blocked_message', fn (OpeningStockPricing $record): string => $this->editBlockedMessage($record, (bool) $request->user()?->can('inventory.opening_stock_pricings.edit')))
            ->orderColumn('doc_num', 'inventory_opening_stock_pricings.doc_number $1')
            ->orderColumn('document_date', 'inventory_opening_stock_pricings.document_date $1')
            ->orderColumn('branch', 'branches.name $1')
            ->orderColumn('hall', 'branch_halls.name $1')
            ->orderColumn('opening_stock_doc_num', 'inventory_opening_stocks.doc_number $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('exchange_rate', 'inventory_opening_stock_pricings.exchange_rate $1')
            ->orderColumn('total_amount', 'inventory_opening_stock_pricings.total_amount $1')
            ->orderColumn('status', 'inventory_opening_stock_pricings.status $1')
            ->orderColumn('lines_count', 'lines_count $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'inventory_opening_stock_pricings.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'inventory_opening_stock_pricings.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('financial_period_id')
            ->removeColumn('branch_id')
            ->removeColumn('branch_hall_id')
            ->removeColumn('opening_stock_id')
            ->removeColumn('currency_id')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'branch', 'hall', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function openingStockDocumentLabel(OpeningStockPricing $record, string $dateFormat): string
    {
        $docNum = trim((string) $record->opening_stock_doc_num);

        if ($docNum === '') {
            return __('common.empty_value');
        }

        $date = $record->opening_stock_document_date
            ? Carbon::parse($record->opening_stock_document_date)->format($dateFormat)
            : null;

        return trim(implode(' — ', array_filter([$docNum, $date])));
    }

    private function editBlockedMessage(OpeningStockPricing $record, bool $hasPermission): string
    {
        if (! $hasPermission) {
            return __('inventory.opening_stock_pricings.messages.edit_permission_denied');
        }

        if ($record->isClosed()) {
            return __('inventory.opening_stock_pricings.messages.closed_edit_forbidden');
        }

        return __('inventory.opening_stock_pricings.messages.closed_edit_forbidden');
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('inventory.opening_stock_pricings.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function formatNumber(mixed $value, int $precision = 4): string
    {
        return rtrim(rtrim(number_format((float) $value, $precision, '.', ''), '0'), '.') ?: '0';
    }
}
