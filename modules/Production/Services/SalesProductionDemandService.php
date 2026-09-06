<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Production\Models\ProductionOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\SalesAmountService;

class SalesProductionDemandService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly FinancialPeriodService $periods,
        private readonly InventoryAvailabilityService $availability,
    ) {}

    /** @param list<array{sales_order_line_id: int, quantity: string|int|float}> $lines */
    public function create(SalesOrder $salesOrder, array $lines): ProductionOrder
    {
        return DB::transaction(function () use ($salesOrder, $lines): ProductionOrder {
            if ($lines === [] || count(array_unique(array_column($lines, 'sales_order_line_id'))) !== count($lines)) {
                throw new DomainException(__('Select each production demand line once and enter its total quantity.'));
            }
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($salesOrder->getKey());
            if (! $order->isApprovedForFulfillment()) {
                throw new DomainException(__('Production demand requires an approved sales order.'));
            }
            $period = $this->periods->resolveOpenForPostingDate((int) $order->company_id, now()->toDateString(), lockForUpdate: true);
            BranchStore::query()->lockForUpdate()->findOrFail($order->branch_store_id);
            $numbers = $this->documents->nextForCompany('production_orders', ProductionOrder::class, (int) $order->company_id, fn ($query) => $query->where('financial_period_id', $period->getKey()));
            $production = ProductionOrder::query()->create([
                ...$numbers, 'company_id' => $order->company_id, 'financial_period_id' => $period->getKey(),
                'branch_id' => $order->branch_id, 'sales_order_id' => $order->getKey(), 'customer_id' => $order->customer_id,
                'source_type' => 'sales_order', 'source_id' => $order->getKey(),
                'production_order_date' => now()->toDateString(), 'expected_delivery_date' => $order->expected_delivery_date,
                'status' => ProductionOrder::StatusDraft,
                'technical_notes' => $order->technical_notes_snapshot ? json_encode($order->technical_notes_snapshot, JSON_THROW_ON_ERROR) : null,
                'production_notes' => 'Generated from '.$order->doc_num, 'created_by' => auth()->id(),
            ]);

            foreach ($lines as $index => $input) {
                $line = SalesOrderLine::query()->lockForUpdate()->where('sales_order_id', $order->getKey())->findOrFail($input['sales_order_line_id']);
                if ($line->isService() || $line->product_classification_snapshot !== Product::ClassificationFinishedProduct) {
                    throw new DomainException(__('Only finished-product lines can generate production demand.'));
                }
                $quantity = (string) $input['quantity'];
                $baseQuantity = bcmul($quantity, (string) $line->conversion_factor, 8);
                $this->amounts->assertPositive($quantity, __('Production quantity must be greater than zero.'));
                Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $availableBase = $this->availability->forProduct((int) $order->company_id, (int) $order->branch_store_id, (int) $line->product_id, (int) $line->getKey())['available'];
                $available = bcdiv($availableBase, (string) $line->conversion_factor, 8);
                $plannedRemaining = bcsub((string) $line->production_requested_quantity, (string) $line->produced_quantity, 8);
                $remaining = bcsub(bcsub($line->remainingDeliveryQuantity(), $available, 8), $plannedRemaining, 8);
                $this->amounts->assertNotGreaterThan($quantity, $remaining, __('Production demand exceeds the unplanned stock shortage.'));
                $production->lines()->create([
                    'sales_order_line_id' => $line->getKey(), 'line_number' => $index + 1,
                    'product_id' => $line->product_id, 'unit_id' => $line->unit_id, 'description' => $line->description,
                    'quantity' => $quantity, 'conversion_factor' => $line->conversion_factor,
                    'base_quantity' => $baseQuantity, 'specifications' => $line->specifications,
                    'production_notes' => $line->production_notes, 'mandatory_specs_resolved' => true,
                ]);
                $line->increment('production_requested_quantity', $quantity);
                $line->increment('production_requested_base_quantity', $baseQuantity);
            }

            return $production->load('lines');
        });
    }
}
