<?php

namespace Modules\Sales\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Yajra\DataTables\Facades\DataTables;

class SalesCycleDataTable
{
    public static function routePrefix(string $kind): string
    {
        return 'admin.sales.'.match ($kind) {
            'sales_requests' => 'customer-requests', 'sales_orders' => 'sales-orders',
            'customer_invoices' => 'sales-invoices', 'customer_receipts' => 'customer-receipts',
            'sales_returns' => 'sales-returns', 'sales_deliveries' => 'delivery-notes',
        };
    }

    public function json(Request $request, Builder $query, string $kind, string $dateColumn): JsonResponse
    {
        $prefix = self::routePrefix($kind);
        $table = $query->getModel()->getTable();
        $showPrices = $kind === 'customer_receipts' || $request->user()?->can(match ($kind) {
            'sales_orders', 'sales_requests' => 'sales_orders.view_prices',
            default => 'customer_invoices.view_prices',
        });

        return DataTables::eloquent($query)
            ->filter(function (Builder $query) use ($request): void {
                $search = trim((string) $request->input('search.value', ''));
                if ($search !== '') {
                    $query->where(fn ($query) => $query->where('doc_num', 'like', '%'.$search.'%')
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%')->orWhere('doc_num', 'like', '%'.$search.'%')));
                }
            })
            ->editColumn('doc_num', fn ($record): string => '<a href="'.e(route($prefix.'.show', $record)).'">'.e($record->doc_num).'</a>')
            ->addColumn('date', fn ($record): string => app(DateFormatService::class)->formatDate($record->{$dateColumn}, ''))
            ->addColumn('customer', fn ($record): string => $record->customer?->name ?? __('Internal request'))
            ->editColumn('status', fn ($record): string => view('modules.sales.quotations.partials.status', ['status' => $record->status])->render())
            ->addColumn('amount', fn ($record): string => $showPrices && $kind !== 'sales_deliveries' && ($record->total_amount ?? $record->amount) !== null ? app(NumericFormatService::class)->format($record->total_amount ?? $record->amount ?? null) : '—')
            ->addColumn('actions', fn ($record): string => view('modules.sales.cycle.partials.index-actions', ['record' => $record, 'prefix' => $prefix, 'kind' => $kind])->render())
            ->orderColumn('doc_num', $table.'.doc_number $1')
            ->orderColumn('date', $table.'.'.$dateColumn.' $1')
            ->only(['doc_num', 'date', 'customer', 'status', 'amount', 'actions'])
            ->rawColumns(['doc_num', 'status', 'actions'])->toJson();
    }
}
