<?php

namespace App\Console\Commands;

use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Throwable;

class RepairReversedInventoryReceiptLayersCommand extends Command
{
    protected $signature = 'inventory:repair-reversed-receipt-layers
        {document : Exact inventory document number}
        {--apply : Apply the previewed allocation correction}
        {--expect-quantity= : Required exact misplaced quantity when applying}';

    protected $description = 'Preview or repair historical receipt reversals that consumed unrelated receipt layers';

    public function handle(): int
    {
        $documentNumber = trim((string) $this->argument('document'));
        $apply = (bool) $this->option('apply');
        $expectedQuantity = trim((string) $this->option('expect-quantity'));

        if ($documentNumber === '') {
            $this->error('An exact inventory document number is required.');

            return self::FAILURE;
        }
        if ($apply && (! preg_match('/^\d+(?:\.\d{1,8})?$/', $expectedQuantity)
            || bccomp($expectedQuantity, '0', 8) <= 0)) {
            $this->error('Applying a correction requires a positive --expect-quantity with up to eight decimal places.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($documentNumber, $apply, $expectedQuantity): array {
                $document = InventoryDocument::query()->where('doc_num', $documentNumber)->lockForUpdate()->sole();
                if ($document->document_type !== InventoryDocument::TypeReceipt
                    || $document->status !== InventoryDocument::StatusReversed
                    || $document->source_document_type !== null
                    || $document->production_order_id !== null
                    || $document->production_run_id !== null) {
                    throw new DomainException('Only a reversed general receipt without production lineage can be repaired.');
                }

                $receipts = InventoryTransaction::query()
                    ->where('source_type', InventoryDocument::class)
                    ->where('source_id', $document->getKey())
                    ->where('is_reversal', false)
                    ->where('quantity_in', '>', 0)
                    ->orderBy('product_id')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($receipts->isEmpty() || $receipts->count() !== $document->lines()->count()) {
                    throw new DomainException('Original receipt transactions do not match the document lines.');
                }
                if ($receipts->pluck('product_id')->unique()->count() !== $receipts->count()) {
                    throw new DomainException('The repair requires one receipt line per product to avoid overlapping layer corrections.');
                }

                $productIds = $receipts->pluck('product_id')->unique()->sort()->values();
                if (Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get(['id'])->count() !== $productIds->count()) {
                    throw new DomainException('A receipt product could not be locked for correction.');
                }

                $plans = [];
                $misplacedQuantity = '0.00000000';
                foreach ($receipts as $receipt) {
                    $reversal = InventoryTransaction::query()
                        ->where('reversal_of_id', $receipt->getKey())
                        ->where('is_reversal', true)
                        ->lockForUpdate()
                        ->sole();
                    $quantity = (string) $receipt->quantity_in;
                    if (bccomp((string) $reversal->quantity_out, $quantity, 8) !== 0
                        || bccomp((string) $reversal->quantity_in, '0', 8) !== 0) {
                        throw new DomainException('Receipt and reversal quantities do not match.');
                    }
                    if ($receipt->unit_cost !== null || $receipt->total_cost !== null
                        || $reversal->unit_cost !== null || $reversal->total_cost !== null) {
                        throw new DomainException('Priced receipts require cost and journal reconciliation; this command only repairs unpriced receipts.');
                    }

                    $ownLayer = InventoryReceiptLayer::query()
                        ->where('receipt_transaction_id', $receipt->getKey())
                        ->lockForUpdate()
                        ->sole();
                    if (bccomp((string) $ownLayer->original_quantity, $quantity, 8) !== 0
                        || InventoryLayerAllocation::query()
                            ->where('inventory_receipt_layer_id', $ownLayer->getKey())
                            ->where('issue_transaction_id', '<>', $reversal->getKey())
                            ->exists()) {
                        throw new DomainException('The original receipt layer has other consumption or a mismatched quantity.');
                    }

                    $allocations = InventoryLayerAllocation::query()
                        ->where('issue_transaction_id', $reversal->getKey())
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $layers = InventoryReceiptLayer::query()
                        ->whereIn('id', $allocations->pluck('inventory_receipt_layer_id')->push($ownLayer->getKey())->unique())
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    $allocatedQuantity = '0.00000000';
                    $foreignQuantity = '0.00000000';
                    $foreignAllocations = [];
                    $ownAllocation = null;
                    foreach ($allocations as $allocation) {
                        $layer = $layers->get($allocation->inventory_receipt_layer_id);
                        if (! $layer || ! $this->samePosition($ownLayer, $layer)) {
                            throw new DomainException('A reversal allocation has a missing or unrelated stock layer.');
                        }
                        $allocatedQuantity = bcadd($allocatedQuantity, (string) $allocation->quantity, 8);
                        if ((int) $layer->getKey() === (int) $ownLayer->getKey()) {
                            $ownAllocation = $allocation;
                        } else {
                            $foreignQuantity = bcadd($foreignQuantity, (string) $allocation->quantity, 8);
                            $foreignAllocations[] = $allocation;
                        }
                    }

                    if (bccomp($allocatedQuantity, $quantity, 8) !== 0
                        || bccomp((string) $ownLayer->remaining_quantity, $foreignQuantity, 8) !== 0) {
                        throw new DomainException('Reversal allocations or original layer balance do not reconcile.');
                    }
                    foreach (collect($foreignAllocations)->groupBy('inventory_receipt_layer_id') as $layerId => $group) {
                        $layer = $layers->get($layerId);
                        $restoreQuantity = $group->reduce(
                            fn (string $carry, InventoryLayerAllocation $allocation): string => bcadd($carry, (string) $allocation->quantity, 8),
                            '0.00000000',
                        );
                        if (bccomp(bcadd((string) $layer->remaining_quantity, $restoreQuantity, 8), (string) $layer->original_quantity, 8) > 0) {
                            throw new DomainException('Restoring an unrelated layer would exceed its original quantity.');
                        }
                    }

                    $misplacedQuantity = bcadd($misplacedQuantity, $foreignQuantity, 8);
                    $plans[] = compact('receipt', 'reversal', 'ownLayer', 'ownAllocation', 'foreignAllocations', 'layers', 'quantity', 'foreignQuantity');
                }

                if ($apply) {
                    if (bccomp($misplacedQuantity, '0', 8) === 0 && $this->hasMatchingRepairAudit($document, $expectedQuantity)) {
                        return [
                            'document' => $document->doc_num,
                            'lines' => count($plans),
                            'misplaced_quantity' => $misplacedQuantity,
                            'misplaced_allocations' => 0,
                            'applied' => false,
                            'already_repaired' => true,
                        ];
                    }

                    if (bccomp($misplacedQuantity, $expectedQuantity, 8) !== 0) {
                        throw new DomainException('The misplaced quantity changed since the preview; no correction was applied.');
                    }

                    $auditAllocations = [];
                    foreach ($plans as $plan) {
                        if (bccomp($plan['foreignQuantity'], '0', 8) <= 0) {
                            continue;
                        }
                        foreach ($plan['foreignAllocations'] as $allocation) {
                            $layer = $plan['layers']->get($allocation->inventory_receipt_layer_id);
                            $layer->forceFill([
                                'remaining_quantity' => bcadd((string) $layer->remaining_quantity, (string) $allocation->quantity, 8),
                            ])->save();
                            $auditAllocations[] = [
                                'allocation_id' => $allocation->getKey(),
                                'issue_transaction_id' => $allocation->issue_transaction_id,
                                'restored_layer_id' => $layer->getKey(),
                                'correct_layer_id' => $plan['ownLayer']->getKey(),
                                'quantity' => (string) $allocation->quantity,
                            ];
                            $allocation->delete();
                        }

                        if ($plan['ownAllocation']) {
                            $plan['ownAllocation']->forceFill(['quantity' => $plan['quantity']])->save();
                        } else {
                            InventoryLayerAllocation::query()->create([
                                'inventory_receipt_layer_id' => $plan['ownLayer']->getKey(),
                                'issue_transaction_id' => $plan['reversal']->getKey(),
                                'quantity' => $plan['quantity'],
                            ]);
                        }
                        $plan['ownLayer']->forceFill(['remaining_quantity' => '0.00000000'])->save();

                        $corrected = InventoryLayerAllocation::query()
                            ->where('issue_transaction_id', $plan['reversal']->getKey())
                            ->sole();
                        if ((int) $corrected->inventory_receipt_layer_id !== (int) $plan['ownLayer']->getKey()
                            || bccomp((string) $corrected->quantity, $plan['quantity'], 8) !== 0) {
                            throw new DomainException('Corrected receipt-layer allocation did not reconcile.');
                        }
                    }

                    activity('inventory')
                        ->performedOn($document)
                        ->event('reversed_receipt_layer_repair')
                        ->withProperties([
                            'document' => $document->doc_num,
                            'quantity' => $misplacedQuantity,
                            'allocation_changes' => $auditAllocations,
                        ])
                        ->log('Corrected historical receipt reversal layer allocations.');
                }

                return [
                    'document' => $document->doc_num,
                    'lines' => count($plans),
                    'misplaced_quantity' => $misplacedQuantity,
                    'misplaced_allocations' => collect($plans)->sum(fn (array $plan): int => count($plan['foreignAllocations'])),
                    'applied' => $apply,
                    'already_repaired' => false,
                ];
            }, 3);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Document', 'Lines', 'Misplaced allocations', 'Misplaced quantity', 'Applied'], [[
            $result['document'], $result['lines'], $result['misplaced_allocations'], $result['misplaced_quantity'], $result['applied'] ? 'yes' : 'no',
        ]]);
        if ($result['already_repaired']) {
            $this->info('The same expected repair was already applied; no changes were made.');
        }

        return self::SUCCESS;
    }

    private function hasMatchingRepairAudit(InventoryDocument $document, string $expectedQuantity): bool
    {
        $auditRows = DB::table('activity_log')
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'reversed_receipt_layer_repair')
            ->orderByDesc('id')
            ->get(['properties']);

        foreach ($auditRows as $auditRow) {
            $properties = json_decode((string) $auditRow->properties, true, 512, JSON_THROW_ON_ERROR);
            if (($properties['document'] ?? null) === $document->doc_num
                && isset($properties['quantity'])
                && bccomp((string) $properties['quantity'], $expectedQuantity, 8) === 0) {
                return true;
            }
        }

        return false;
    }

    private function samePosition(InventoryReceiptLayer $left, InventoryReceiptLayer $right): bool
    {
        foreach (['company_id', 'branch_store_id', 'product_id', 'stock_status', 'warehouse_location_id', 'batch_lot'] as $attribute) {
            if ($left->getAttribute($attribute) !== $right->getAttribute($attribute)) {
                return false;
            }
        }

        return true;
    }
}
