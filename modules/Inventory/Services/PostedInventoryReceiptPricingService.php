<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;

class PostedInventoryReceiptPricingService
{
    public function __construct(
        private readonly InventoryAccountingPostingService $accounting,
        private readonly FinancialPeriodService $periods,
        private readonly OperatingContextService $context,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<int, string>  $unitCosts  Inventory document line ID => unit cost.
     */
    public function price(
        InventoryDocument $document,
        array $unitCosts,
        string $sourceReference,
        bool $provisional,
        Request $request,
        ?InventoryReceiptCostProposal $proposal = null,
        ?string $approvalReference = null,
    ): InventoryDocument {
        return DB::transaction(function () use ($document, $unitCosts, $sourceReference, $provisional, $request, $proposal, $approvalReference): InventoryDocument {
            Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            $scope = $this->context->snapshot($request);
            if ((int) $locked->company_id !== (int) $scope['company_id']
                || (int) $locked->financial_period_id !== (int) $scope['financial_period_id']
                || (int) $locked->branch_id !== (int) $scope['branch_id']) {
                throw new DomainException(__('inventory.movements.messages.context_mismatch'));
            }
            if ($provisional && ! app()->environment('local')) {
                throw new DomainException(__('inventory.movements.messages.provisional_local_only'));
            }
            if ($locked->status !== InventoryDocument::StatusPosted
                || $locked->document_type !== InventoryDocument::TypeReceipt
                || $locked->source_document_type !== null
                || $locked->source_document_id !== null
                || $locked->source_doc_num !== null
                || $locked->production_order_id !== null
                || $locked->production_run_id !== null
                || $locked->production_run_batch_id !== null
                || $locked->journal_entry_id !== null
                || $locked->reversal_journal_entry_id !== null) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_unavailable'));
            }

            $period = FinancialPeriod::query()->whereKey($locked->financial_period_id)->lockForUpdate()->firstOrFail();
            if ($period->is_closed || (int) $period->company_id !== (int) $locked->company_id) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_period_closed'));
            }
            $this->periods->resolveOpenForPostingDate(
                (int) $locked->company_id,
                $locked->document_date,
                (int) $locked->financial_period_id,
                lockForUpdate: true,
            );

            $store = BranchStore::query()->with('branch')->whereKey($locked->branch_store_id)->lockForUpdate()->firstOrFail();
            if ((int) $store->branch_id !== (int) $locked->branch_id
                || (int) $store->branch?->company_id !== (int) $locked->company_id) {
                throw new DomainException(__('inventory.movements.messages.context_mismatch'));
            }

            $lines = $locked->lines()->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()
                || $lines->contains(fn (InventoryDocumentLine $line): bool => $line->unit_cost !== null || $line->total_cost !== null)
                || count($unitCosts) !== $lines->count()
                || array_diff($lines->modelKeys(), array_keys($unitCosts)) !== []) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
            }

            $transactions = InventoryTransaction::query()
                ->where('source_type', InventoryDocument::class)
                ->where('source_id', $locked->getKey())
                ->where('is_reversal', false)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($transactions->count() !== $lines->count()
                || InventoryTransaction::query()->where('source_type', InventoryDocument::class)
                    ->where('source_id', $locked->getKey())->where('is_reversal', true)->exists()) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
            }

            $byLine = $transactions->keyBy('posting_key');
            $lastTransactionId = (int) $transactions->max('id');
            $costLog = [];
            foreach ($lines as $line) {
                $transaction = $byLine->get("inventory-document:{$locked->id}:line:{$line->id}:in");
                $unitCost = (string) $unitCosts[$line->getKey()];
                if (! $transaction instanceof InventoryTransaction
                    || (int) $transaction->product_id !== (int) $line->product_id
                    || (int) $transaction->company_id !== (int) $locked->company_id
                    || (int) $transaction->branch_id !== (int) $locked->branch_id
                    || (int) $transaction->branch_store_id !== (int) $locked->branch_store_id
                    || bccomp((string) $transaction->quantity_in, (string) $line->quantity, 8) !== 0
                    || bccomp((string) $transaction->quantity_out, '0', 8) !== 0
                    || $transaction->unit_cost !== null
                    || $transaction->total_cost !== null
                    || ! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $unitCost)
                    || bccomp($unitCost, '0', 8) <= 0) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
                }
                $hasLaterOutbound = InventoryTransaction::query()
                    ->where('company_id', $locked->company_id)
                    ->where('branch_store_id', $locked->branch_store_id)
                    ->where('product_id', $line->product_id)
                    ->where('quantity_out', '>', 0)
                    ->where(function ($query) use ($locked, $lastTransactionId): void {
                        $query->whereDate('transaction_date', '>', $locked->document_date)
                            ->orWhere(function ($sameDate) use ($locked, $lastTransactionId): void {
                                $sameDate->whereDate('transaction_date', $locked->document_date)
                                    ->where('id', '>', $lastTransactionId);
                            });
                    })
                    ->exists();
                if ($hasLaterOutbound) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_stock_moved'));
                }

                $layers = InventoryReceiptLayer::query()
                    ->where('receipt_transaction_id', $transaction->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $layerQuantity = $layers->reduce(
                    fn (string $sum, InventoryReceiptLayer $layer): string => bcadd($sum, (string) $layer->original_quantity, 8),
                    '0.00000000',
                );
                if ($layers->isEmpty()
                    || bccomp($layerQuantity, (string) $transaction->quantity_in, 8) !== 0
                    || $layers->contains(fn (InventoryReceiptLayer $layer): bool => $layer->unit_cost !== null
                        || bccomp((string) $layer->remaining_quantity, (string) $layer->original_quantity, 8) !== 0)
                    || InventoryLayerAllocation::query()->whereIn('inventory_receipt_layer_id', $layers->modelKeys())->exists()) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_stock_moved'));
                }

                $totalCost = bcmul((string) $line->quantity, $unitCost, 8);
                if (bccomp($totalCost, bcadd($totalCost, '0', 4), 8) !== 0) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_precision'));
                }
                $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
                $snapshot['cost_correction'] = [
                    'basis' => $proposal ? ($proposal->basis === InventoryReceiptCostProposal::BasisEstimate ? 'approved_estimate' : 'approved_documented') : ($provisional ? 'local_provisional' : 'documented'),
                    'source_reference' => $sourceReference,
                    'approval_reference' => $approvalReference,
                    'proposal_id' => $proposal?->getKey(),
                    'source_file_sha256' => $proposal?->source_file_sha256,
                    'applied_at' => now()->toIso8601String(),
                    'applied_by' => $request->user()?->getKey(),
                ];
                $line->forceFill([
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                    'product_snapshot' => $snapshot,
                    'updated_by' => $request->user()?->getKey(),
                ])->save();
                $transaction->forceFill(['unit_cost' => $unitCost, 'total_cost' => $totalCost])->save();
                foreach ($layers as $layer) {
                    $layer->forceFill(['unit_cost' => $unitCost])->save();
                }
                $costLog[] = [
                    'line_id' => (int) $line->getKey(),
                    'product_id' => (int) $line->product_id,
                    'quantity' => (string) $line->quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ];
            }

            $journal = $this->accounting->post($locked->refresh()->load('lines.product'));
            if ($journal === null) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_journal_failed'));
            }
            $this->activity->log($request, 'inventory', 'receipt_pricing', 'success', [
                'subject' => $locked,
                'properties_only' => true,
                'properties' => [
                    'doc_num' => $locked->doc_num,
                    'source_reference' => $sourceReference,
                    'basis' => $proposal ? $proposal->basis : ($provisional ? 'local_provisional' : 'documented'),
                    'proposal_id' => $proposal?->getKey(),
                    'approval_reference' => $approvalReference,
                    'journal_entry_id' => $journal->getKey(),
                    'lines' => $costLog,
                ],
            ]);

            return $locked->refresh()->load(['lines', 'transactions', 'journalEntry']);
        });
    }
}
