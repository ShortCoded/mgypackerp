<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\SalesAmountService;

class SalesProductionDemandService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly InventoryDocumentPostingService $posting,
    ) {}

    /** @param list<array{sales_order_line_id: int, quantity: string|int|float}> $lines */
    public function create(SalesOrder $salesOrder, array $lines): ProductionOrder
    {
        return DB::transaction(function () use ($salesOrder, $lines): ProductionOrder {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($salesOrder->getKey());
            if (! $order->isApprovedForFulfillment()) {
                throw new DomainException('Production demand requires an approved sales order.');
            }
            $numbers = $this->documents->nextForCompany('production_orders', ProductionOrder::class, (int) $order->company_id, fn ($query) => $query->where('financial_period_id', $order->financial_period_id));
            $production = ProductionOrder::query()->create([
                ...$numbers, 'company_id' => $order->company_id, 'financial_period_id' => $order->financial_period_id,
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
                    throw new DomainException('Only finished-product lines can generate production demand.');
                }
                $quantity = (string) $input['quantity'];
                $baseQuantity = bcmul($quantity, (string) $line->conversion_factor, 8);
                $this->amounts->assertPositive($quantity, 'Production quantity must be greater than zero.');
                $remaining = $this->amounts->subtract($line->quantity, $line->production_requested_quantity, 8);
                $this->amounts->assertNotGreaterThan($quantity, $remaining, 'Production demand exceeds the unplanned order quantity.');
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

    /** @param list<array{production_order_line_id: int, quantity: string|int|float}> $lines */
    public function receiveCompletion(ProductionOrder $productionOrder, int $branchStoreId, array $lines): InventoryDocument
    {
        return DB::transaction(function () use ($productionOrder, $branchStoreId, $lines): InventoryDocument {
            $production = ProductionOrder::query()->lockForUpdate()->findOrFail($productionOrder->getKey());
            if (in_array($production->status, [ProductionOrder::StatusCancelled, ProductionOrder::StatusCompleted], true)) {
                throw new DomainException('This production order cannot receive another completion.');
            }
            $numbers = $this->documents->nextForCompany('inventory_documents', InventoryDocument::class, (int) $production->company_id, fn ($query) => $query->where('financial_period_id', $production->financial_period_id));
            $receipt = InventoryDocument::query()->create([
                ...$numbers, 'company_id' => $production->company_id, 'financial_period_id' => $production->financial_period_id,
                'branch_id' => $production->branch_id, 'branch_store_id' => $branchStoreId,
                'document_type' => InventoryDocument::TypeProductionReceipt, 'document_date' => now()->toDateString(),
                'purpose' => 'Finished production receipt', 'source_document_type' => ProductionOrder::class,
                'source_document_id' => $production->getKey(), 'source_doc_num' => $production->doc_num,
                'production_order_id' => $production->getKey(), 'status' => InventoryDocument::StatusDraft,
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $index => $input) {
                $line = ProductionOrderLine::query()->lockForUpdate()->where('production_order_id', $production->getKey())->findOrFail($input['production_order_line_id']);
                $quantity = (string) $input['quantity'];
                $baseQuantity = bcmul($quantity, (string) $line->conversion_factor, 8);
                $this->amounts->assertPositive($quantity, 'Completed production quantity must be greater than zero.');
                $received = (string) DB::table('inventory_document_lines')->where('production_order_id', $production->getKey())->where('source_line_type', ProductionOrderLine::class)->where('source_line_id', $line->getKey())->whereNull('deleted_at')->sum('transaction_quantity');
                $this->amounts->assertNotGreaterThan($quantity, $this->amounts->subtract($line->quantity, $received, 8), 'Completion exceeds the production line quantity.');
                $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $receipt->lines()->create([
                    'company_id' => $production->company_id, 'financial_period_id' => $production->financial_period_id,
                    'line_number' => $index + 1, 'product_id' => $line->product_id, 'unit_id' => $product->item_unit_id,
                    'transaction_unit_id' => $line->unit_id, 'conversion_factor' => $line->conversion_factor,
                    'transaction_quantity' => $quantity, 'base_quantity' => $baseQuantity,
                    'source_line_type' => ProductionOrderLine::class, 'source_line_id' => $line->getKey(),
                    'source_line_public_id' => $line->public_id, 'reference_quantity' => $line->base_quantity,
                    'previous_quantity' => bcmul($received, (string) $line->conversion_factor, 8), 'quantity' => $baseQuantity, 'production_order_id' => $production->getKey(),
                    'product_snapshot' => ['description' => $line->description, 'specifications' => $line->specifications],
                    'created_by' => auth()->id(),
                ]);
            }

            $posted = $this->posting->post($receipt);
            foreach ($posted->lines as $receiptLine) {
                $productionLine = ProductionOrderLine::query()->findOrFail($receiptLine->source_line_id);
                if ($productionLine->sales_order_line_id) {
                    SalesOrderLine::query()->whereKey($productionLine->sales_order_line_id)->increment('produced_quantity', $receiptLine->transaction_quantity);
                    SalesOrderLine::query()->whereKey($productionLine->sales_order_line_id)->increment('produced_base_quantity', $receiptLine->quantity);
                }
            }
            $allComplete = $production->lines()->get()->every(function (ProductionOrderLine $line): bool {
                $received = DB::table('inventory_document_lines')->where('production_order_id', $line->production_order_id)->where('source_line_type', ProductionOrderLine::class)->where('source_line_id', $line->getKey())->whereNull('deleted_at')->sum('transaction_quantity');

                return $this->amounts->compare($received, $line->quantity, 8) >= 0;
            });
            $production->update(['status' => $allComplete ? ProductionOrder::StatusCompleted : ProductionOrder::StatusPartiallyCompleted, 'updated_by' => auth()->id()]);

            return $posted->refresh()->load('lines');
        });
    }
}
