<?php

namespace Modules\Sales\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrderLine;

class SalesCycleReadService
{
    /** @param array<string, mixed> $filters */
    public function backorders(int $companyId, int $branchId, array $filters = []): Collection
    {
        $rows = SalesOrderLine::query()->with(['order.customer', 'order.branchStore', 'product.color', 'product.unit', 'unit', 'productionLines.order'])
            ->whereHas('order', fn (Builder $query) => $query->where('company_id', $companyId)->where('branch_id', $branchId)
                ->whereIn('status', ['approved', 'partially_fulfilled'])
                ->when($filters['customer_id'] ?? null, fn ($query, $id) => $query->where('customer_id', $id))
                ->when($filters['currency_id'] ?? null, fn ($query, $id) => $query->where('currency_id', $id))
                ->when($filters['branch_store_id'] ?? null, fn ($query, $id) => $query->where('branch_store_id', $id))
                ->when($filters['sales_person_id'] ?? null, fn ($query, $id) => $query->where('business_employee_id', $id))
                ->when($filters['order_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
                ->when(($filters['overdue_state'] ?? null) === 'overdue', fn ($query) => $query->whereDate('expected_delivery_date', '<', today()))
                ->when(($filters['overdue_state'] ?? null) === 'not_overdue', fn ($query) => $query->whereDate('expected_delivery_date', '>=', today())))
            ->whereHas('product', fn (Builder $query) => $query->nonService()->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('item_category_id', $id)))
            ->whereColumn('delivered_quantity', '<', 'quantity')
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->orderBy('sales_order_id')->orderBy('line_number')->get();
        $products = $rows->pluck('product_id')->unique();
        $stock = DB::table('inventory_transactions')->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereIn('product_id', $products)->where('stock_status', 'available')->groupBy('branch_store_id', 'product_id')
            ->selectRaw('branch_store_id, product_id, sum(quantity_in - quantity_out) as quantity')->get()->keyBy(fn ($row): string => $row->branch_store_id.':'.$row->product_id);
        $reserved = InventoryReservation::query()->where('company_id', $companyId)->where('branch_id', $branchId)->whereIn('product_id', $products)->where('status', 'active')->get(['sales_order_line_id', 'branch_store_id', 'product_id', 'quantity', 'consumed_quantity', 'released_quantity']);
        $reservedByPosition = $reserved->groupBy(fn ($row): string => $row->branch_store_id.':'.$row->product_id)->map(fn ($group): string => $group->reduce(fn (string $sum, $row): string => bcadd($sum, $row->remaining_quantity, 8), '0'));
        $reservedByLine = $reserved->groupBy('sales_order_line_id')->map(fn ($group): string => $group->reduce(fn (string $sum, $row): string => bcadd($sum, $row->remaining_quantity, 8), '0'));
        $freeByPosition = [];

        return $rows->map(function (SalesOrderLine $line) use ($stock, $reservedByPosition, $reservedByLine, &$freeByPosition): array {
            $key = $line->order->branch_store_id.':'.$line->product_id;
            $onHand = (string) ($stock->get($key)?->quantity ?? 0);
            $freeByPosition[$key] ??= $this->nonnegative(bcsub($onHand, $reservedByPosition->get($key, '0'), 8));
            $reserved = (string) $reservedByLine->get($line->id, '0');
            $remainingBase = bcmul($line->remainingDeliveryQuantity(), (string) $line->conversion_factor, 8);
            $need = $this->nonnegative(bcsub($remainingBase, $reserved, 8));
            $freeShare = bccomp($freeByPosition[$key], $need, 8) > 0 ? $need : $freeByPosition[$key];
            $freeByPosition[$key] = bcsub($freeByPosition[$key], $freeShare, 8);
            $shortage = $this->nonnegative(bcsub($need, $freeShare, 8));
            $planned = $this->nonnegative(bcsub((string) $line->production_requested_base_quantity, (string) $line->produced_base_quantity, 8));

            return ['line' => $line, 'on_hand' => bcdiv($onHand, (string) $line->conversion_factor, 8),
                'reserved' => bcdiv($reserved, (string) $line->conversion_factor, 8),
                'available' => bcdiv(bcadd($reserved, $freeShare, 8), (string) $line->conversion_factor, 8),
                'shortage' => bcdiv($shortage, (string) $line->conversion_factor, 8),
                'remaining_production' => bcdiv($planned, (string) $line->conversion_factor, 8),
                'unplanned_base' => $this->nonnegative(bcsub($shortage, $planned, 8)),
                'days_late' => $line->order->expected_delivery_date ? max(0, (int) $line->order->expected_delivery_date->diffInDays(now(), false)) : 0];
        });
    }

    private function nonnegative(string $quantity): string
    {
        return bccomp($quantity, '0', 8) < 0 ? '0.00000000' : $quantity;
    }

    /** @param array<string, mixed> $filters */
    public function ledger(int $companyId, int $branchId, array $filters = []): Builder
    {
        return CustomerInvoice::query()->with(['customer', 'order', 'currency', 'deliveries', 'lines.product.color', 'lines.product.category', 'lines.returnLines.salesReturn'])
            ->with(['lines' => fn ($query) => $query->withSum(['returnLines as returned_quantity' => fn ($returns) => $returns->whereHas('salesReturn', fn ($return) => $return->whereNotIn('status', ['draft', 'cancelled', 'rejected']))], 'quantity')])
            ->where('company_id', $companyId)->where('branch_id', $branchId)->where('posting_status', 'posted')->where('document_type', CustomerInvoice::TypeInvoice)
            ->when($filters['customer_id'] ?? null, fn ($query, $id) => $query->where('customer_id', $id))
            ->when(array_key_exists('customer_ids', $filters) && $filters['customer_ids'] !== null, fn ($query) => $query->whereIn('customer_id', $filters['customer_ids']))
            ->when($filters['currency_id'] ?? null, fn ($query, $id) => $query->where('currency_id', $id))
            ->when($filters['invoice_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($filters['order_id'] ?? null, fn ($query, $id) => $query->where('sales_order_id', $id))
            ->when($filters['sales_person_id'] ?? null, fn ($query, $id) => $query->whereHas('order', fn ($order) => $order->where('business_employee_id', $id)))
            ->when($filters['branch_store_id'] ?? null, fn ($query, $id) => $query->whereHas('deliveries', fn ($delivery) => $delivery
                ->where('branch_store_id', $id)
                ->where('status', 'posted')))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->whereHas('lines.product', fn ($product) => $product->where('item_category_id', $id)))
            ->when(($filters['payment_state'] ?? null) === 'outstanding', fn ($query) => $query->where('remaining_amount', '>', 0))
            ->when(($filters['payment_state'] ?? null) === 'settled', fn ($query) => $query->where('remaining_amount', '<=', 0))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->whereHas('lines', fn ($lines) => $lines->where('product_id', $id)))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '<=', $date))
            ->orderByDesc('invoice_date')->orderByDesc('id');
    }
}
