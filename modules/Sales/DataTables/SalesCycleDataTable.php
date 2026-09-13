<?php

namespace Modules\Sales\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Sales\Models\SalesRequestLine;
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
        if (in_array($kind, ['sales_requests', 'sales_orders'], true)) {
            $trash = $request->string('trash', 'active')->toString();
            if (in_array($trash, ['trashed', 'all'], true)) {
                abort_unless($request->user()?->can($kind.'.view_trashed'), 403);
                $trash === 'trashed' ? $query->onlyTrashed() : $query->withTrashed();
            }
        }
        if ($request->filled('document')) {
            $query->where('doc_num', 'like', '%'.$request->string('document')->toString().'%');
        }

        if ($kind === 'sales_requests') {
            $query->withExists(['quotations as has_quotations' => fn ($related) => $related->withTrashed(), 'orders as has_orders' => fn ($related) => $related->withTrashed()]);
            $query->addSelect([
                'request_total' => SalesRequestLine::query()
                    ->selectRaw('coalesce(sum(quantity * coalesce(unit_price, 0)), 0)')
                    ->whereColumn('sales_request_id', 'sales_requests.id'),
            ]);
        }
        if ($kind === 'sales_orders') {
            $query->withExists([
                'invoices as has_invoices' => fn ($related) => $related->withTrashed(),
                'deliveries',
                'productionOrders',
                'productionOrders as has_active_production_orders' => fn ($related) => $related->where('status', '<>', 'cancelled'),
                'receipts as has_receipts' => fn ($related) => $related->withTrashed(),
                'lines as has_amendment_quantities' => fn ($related) => $related->where(function (Builder $lines): void {
                    $lines->where('reserved_quantity', '>', 0)
                        ->orWhere('production_requested_quantity', '>', 0)
                        ->orWhere('produced_quantity', '>', 0)
                        ->orWhere('delivered_quantity', '>', 0)
                        ->orWhere('invoiced_quantity', '>', 0);
                }),
                'lines as has_fulfillment_quantities' => fn ($related) => $related->where(function (Builder $lines): void {
                    $lines->where('delivered_quantity', '>', 0)
                        ->orWhere('invoiced_quantity', '>', 0);
                }),
            ]);
            $query->addSelect(['has_reservations' => InventoryReservation::query()->selectRaw('1')->whereColumn('sales_order_id', 'sales_orders.id')->limit(1)]);
        }
        if ($kind === 'sales_returns') {
            $query->withCount(['lines as physical_lines_count' => fn ($lines) => $lines->where('is_service', false)]);
        }
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
            ->editColumn('doc_num', fn ($record): string => $record->trashed() ? e($record->doc_num) : '<a href="'.e(route($prefix.'.show', $record)).'">'.e($record->doc_num).'</a>')
            ->addColumn('date', fn ($record): string => app(DateFormatService::class)->formatDate($record->{$dateColumn}, ''))
            ->addColumn('customer', fn ($record): string => $record->customer?->name ?? __('Internal request'))
            ->editColumn('status', fn ($record): string => view('modules.sales.quotations.partials.status', ['status' => $record->trashed() ? 'deleted' : $record->status])->render())
            ->addColumn('amount', function ($record) use ($showPrices, $kind): string {
                $amount = $kind === 'sales_requests'
                    ? $record->request_total
                    : ($record->total_amount ?? $record->amount);

                return $showPrices && $kind !== 'sales_deliveries' && $amount !== null
                    ? app(NumericFormatService::class)->format($amount)
                    : '—';
            })
            ->addColumn('actions', fn ($record): string => view('modules.sales.cycle.partials.index-actions', ['record' => $record, 'prefix' => $prefix, 'kind' => $kind, 'canDeleteDraft' => $record->status === 'draft' && match ($kind) {
                'sales_requests' => ! $record->has_quotations && ! $record->has_orders, 'sales_orders' => ! $record->quotation_id && ! $record->sales_request_id && ! $record->has_invoices && ! $record->deliveries_exists && ! $record->production_orders_exists && ! $record->has_receipts && ! $record->has_reservations, 'customer_invoices' => $record->canDeleteDraft(), default => false
            }])->render())
            ->orderColumn('doc_num', $table.'.doc_number $1')
            ->orderColumn('date', $table.'.'.$dateColumn.' $1')
            ->only(['doc_num', 'date', 'customer', 'status', 'amount', 'actions'])
            ->rawColumns(['doc_num', 'status', 'actions'])->toJson();
    }
}
