<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCorrectionContextService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\SalesReturnCorrectionService;

class InventoryDocumentPostingService
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly InventoryValuationService $valuation,
        private readonly InventoryAccountingPostingService $accounting,
        private readonly InventoryLayerService $layers,
        private readonly InventoryCostPolicyService $costPolicies,
    ) {}

    public function post(InventoryDocument $document): InventoryDocument
    {
        return DB::transaction(function () use ($document): InventoryDocument {
            Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryDocument::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($document->getKey());

            if ($locked->status === InventoryDocument::StatusPosted) {
                return $locked;
            }

            if (! $locked->isUntouchedDraft()) {
                throw new DomainException(__('Only a draft inventory document can be posted.'));
            }

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('An inventory document must contain at least one line.'));
            }

            $this->costPolicies->assertPostingDateAllowed(
                (int) $locked->company_id,
                (int) $locked->branch_store_id,
                $locked->document_date->toDateString(),
            );
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($locked->financial_period_id);

            if ($period->is_closed || (int) $period->company_id !== (int) $locked->company_id) {
                throw new DomainException(__('Inventory movements cannot be posted to a closed or unrelated financial period.'));
            }
            app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $locked->company_id, $locked->document_date, (int) $locked->financial_period_id, lockForUpdate: true);

            $profile = $this->movementProfile($locked);
            $this->assertQualityHoldAuthority($locked, $profile['source_status'], $profile['destination_status']);
            $sourceStore = BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($locked->branch_store_id);
            $destinationStore = $profile['destination_store_id'] !== null
                ? BranchStore::query()->with('branch')->lockForUpdate()->findOrFail($profile['destination_store_id'])
                : null;

            if ((int) $sourceStore->branch?->company_id !== (int) $locked->company_id
                || ($destinationStore && (int) $destinationStore->branch?->company_id !== (int) $locked->company_id)) {
                throw new DomainException(__('Inventory transfer stores must belong to the document company.'));
            }
            if ($locked->document_type === InventoryDocument::TypeTransfer) {
                app(InventoryMovementService::class)->assertTransferBranchAccess((int) $locked->company_id,
                    [(int) $sourceStore->branch_id, (int) ($destinationStore?->branch_id ?? $sourceStore->branch_id)]);
            }
            $costPolicy = $this->costPolicies->resolve(
                (int) $locked->company_id,
                (int) $locked->branch_store_id,
                $locked->document_date->toDateString(),
            );

            foreach ($locked->lines as $line) {
                Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $this->assertChronologicalPosting($locked, $line);
                if ($line->selected_receipt_layer_id !== null && ! $profile['outbound']) {
                    throw new DomainException(__('inventory_cost_policy.errors.layer_selection'));
                }
                $quantity = (string) $line->quantity;

                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }

                $this->hydrateSalesReturnDispositionDimensions($locked, $line, $profile['source_status']);
                $sourceLocationId = $line->warehouse_location_id ?? $locked->warehouse_location_id;
                $sourceProductionRunId = $profile['source_status'] === InventoryTransaction::StatusProductionStaging
                    ? ($line->production_run_id ?? $locked->production_run_id)
                    : null;
                $exactSourceDimensions = $sourceLocationId !== null
                    || filled($line->batch_lot)
                    || $sourceProductionRunId !== null;
                $sourceIssue = $this->validatedSourceIssue($locked, $line, $quantity);
                if ($sourceIssue?->inventory_serial_identity_id !== null && $line->inventory_serial_identity_id === null) {
                    $line->forceFill(['inventory_serial_identity_id' => $sourceIssue->inventory_serial_identity_id])->save();
                }

                $unitCost = match (true) {
                    $profile['outbound'] && InventoryCostPolicy::usesReceiptLayers($costPolicy['method']) => null,
                    $sourceIssue !== null && $sourceIssue->completedTotalCost() !== null => bcdiv($sourceIssue->completedTotalCost(), (string) $sourceIssue->quantity_out, 8),
                    $line->unit_cost !== null => (string) $line->unit_cost,
                    $profile['outbound'] => $this->valuation->bookUnitCostForPosition(
                        (int) $locked->company_id,
                        (int) $locked->branch_store_id,
                        (int) $line->product_id,
                        $profile['source_status'],
                        $sourceLocationId,
                        $line->batch_lot,
                        $sourceProductionRunId,
                        $locked->document_date,
                        $exactSourceDimensions,
                    ),
                    $sourceIssue !== null && $sourceIssue->unit_cost !== null && $sourceIssue->total_cost !== null => (string) $sourceIssue->unit_cost,
                    default => null,
                };
                $totalCost = null;

                if ($profile['outbound']) {
                    $this->assertPositionCanIssue($locked, $line, $quantity, $profile['source_status']);
                    $sourceIssue = $this->createTransaction(
                        $locked,
                        $line,
                        'out',
                        (int) $locked->branch_store_id,
                        (int) $sourceStore->branch_id,
                        $line->warehouse_location_id ?? $locked->warehouse_location_id,
                        $profile['source_status'],
                        '0',
                        $quantity,
                        $unitCost,
                        $costPolicy['method'],
                        $costPolicy['policy_id'],
                        null,
                        InventoryCostPolicy::usesReceiptLayers($costPolicy['method'])
                            ? ($costPolicy['method'] === InventoryCostPolicy::SpecificIdentification ? 'selected_receipt_layer' : 'fifo_allocations')
                            : ($line->unit_cost !== null ? 'document_input' : ($costPolicy['method'] === InventoryCostPolicy::PeriodicWeightedAverage ? 'periodic_provisional_moving_average' : 'moving_average')),
                    );
                    if (InventoryCostPolicy::usesReceiptLayers($costPolicy['method'])) {
                        $fifoCost = $this->layers->allocatedIssueCost($sourceIssue);
                        $unitCost = $fifoCost['unit_cost'];
                        $totalCost = $fifoCost['total_cost'];
                        $sourceIssue->forceFill(['unit_cost' => $unitCost, 'total_cost' => $totalCost])->save();
                    }
                }

                if (in_array($locked->document_type, [
                    InventoryDocument::TypeMaintenanceMaterialIssue,
                    InventoryDocument::TypeMaintenanceMaterialReturn,
                ], true) && $unitCost === null) {
                    throw new DomainException(__('Maintenance material movements require an authoritative inventory cost.'));
                }

                if ($profile['inbound']) {
                    $restorationAllocations = $this->resolveRestorationAllocations($locked, $line, $sourceIssue);
                    $restorationPlan = $sourceIssue !== null
                        ? $this->layers->planRestoration(
                            $sourceIssue,
                            $quantity,
                            $restorationAllocations,
                            in_array($locked->document_type, [InventoryDocument::TypeSalesReturnReceipt, InventoryDocument::TypeMaintenanceMaterialReturn], true),
                        )
                        : null;
                    if ($sourceIssue !== null && $restorationPlan?->isNotEmpty()) {
                        $restorationCost = $this->layers->restorationCost($restorationPlan, $quantity);
                        $unitCost = $restorationCost['unit_cost'];
                        $totalCost = $restorationCost['total_cost'];
                    }
                    $destinationPolicy = $destinationStore !== null
                        ? $this->costPolicies->resolve((int) $locked->company_id, (int) $destinationStore->getKey(), $locked->document_date->toDateString())
                        : $costPolicy;
                    $receiptTransaction = $this->createTransaction(
                        $locked,
                        $line,
                        'in',
                        $profile['destination_store_id'] ?? (int) $locked->branch_store_id,
                        (int) ($destinationStore?->branch_id ?? $sourceStore->branch_id),
                        $line->destination_warehouse_location_id
                            ?? $locked->destination_warehouse_location_id
                            ?? $line->warehouse_location_id
                            ?? $locked->warehouse_location_id,
                        $profile['destination_status'],
                        $quantity,
                        '0',
                        $unitCost,
                        $destinationPolicy['method'],
                        $destinationPolicy['policy_id'],
                        $totalCost,
                        $sourceIssue !== null ? ($profile['outbound'] ? 'source_transfer' : 'source_issue') : 'document_input',
                    );
                    $this->layers->recordInbound($receiptTransaction, $sourceIssue, $restorationAllocations, $restorationPlan);
                }

                $line->update([
                    'inventory_serial_identity_id' => ($profile['inbound'] ? $receiptTransaction : $sourceIssue)?->inventory_serial_identity_id,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost ?? ($unitCost === null ? null : bcmul($quantity, $unitCost, 8)),
                ]);
            }

            $this->accounting->post($locked->refresh()->load('lines.product'));

            $locked->update([
                'status' => InventoryDocument::StatusPosted,
                'is_closed' => true,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'closed_by' => auth()->id(),
                'closed_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['lines', 'transactions']);
        });
    }

    /**
     * @return array{document: string, can_reverse: bool, blockers: list<string>, lines: list<array<string, mixed>>, preview_token: string}
     */
    public function reversalPlan(InventoryDocument $document, ?string $postingDate = null): array
    {
        $document = InventoryDocument::query()->withTrashed()->findOrFail($document->getKey());
        if ($document->status !== InventoryDocument::StatusPosted) {
            throw new DomainException(__('Only a posted inventory document can be reversed.'));
        }

        $postingDate ??= $document->document_date->toDateString();
        $blockers = [];
        if ($document->trashed()) {
            $blockers[] = __('open_documents.decisions.deleted');
        }
        if (! in_array($document->document_type, InventoryDocument::manualMovementTypes(), true)
            || $document->source_document_type !== null
            || $document->source_document_id !== null
            || $document->production_order_id !== null
            || $document->production_run_id !== null
            || $document->production_run_batch_id !== null) {
            $blockers[] = __('inventory.movements.reversal.source_workflow_required');
        }
        $period = FinancialPeriod::query()->findOrFail($document->financial_period_id);
        if ($period->is_closed || (int) $period->company_id !== (int) $document->company_id) {
            $blockers[] = __('Inventory movements cannot be reversed in a closed or unrelated financial period.');
        }
        try {
            $this->costPolicies->assertPostingDateAllowed(
                (int) $document->company_id,
                (int) $document->branch_store_id,
                $postingDate,
            );
            app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $document->company_id, $postingDate,
                expectedPeriodId: (int) $document->financial_period_id);
            if ($postingDate < $document->document_date->toDateString()) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_date_before_movements'));
            }
        } catch (DomainException $exception) {
            $blockers[] = $exception->getMessage();
        }

        $transactions = InventoryTransaction::query()
            ->with(['product', 'branchStore'])
            ->where('source_type', InventoryDocument::class)
            ->where('source_id', $document->getKey())
            ->where('is_reversal', false)
            ->orderBy('id')
            ->get();
        if ($transactions->isEmpty()) {
            $blockers[] = __('inventory.movements.reversal.no_original_transactions');
        }
        if (! $this->originalTransactionsComplete($document, $transactions)) {
            $blockers[] = __('inventory.movements.reversal.lineage_incomplete');
        }

        $lines = [];
        $projectedPositions = [];
        foreach ($transactions as $transaction) {
            $quantityIn = bcadd((string) $transaction->quantity_in, '0', 8);
            $quantityOut = bcadd((string) $transaction->quantity_out, '0', 8);
            $positionKey = json_encode([
                $transaction->company_id,
                $transaction->branch_store_id,
                $transaction->product_id,
                $transaction->warehouse_location_id,
                $transaction->stock_status,
                $transaction->batch_lot,
            ], JSON_THROW_ON_ERROR);
            if (! isset($projectedPositions[$positionKey])) {
                $position = $this->availability->forProduct(
                    (int) $transaction->company_id,
                    (int) $transaction->branch_store_id,
                    (int) $transaction->product_id,
                    null,
                    $transaction->warehouse_location_id,
                    (string) $transaction->stock_status,
                    $transaction->batch_lot,
                    true,
                );
                $projectedPositions[$positionKey] = [
                    'on_hand' => bcadd((string) $position['on_hand'], '0', 8),
                    'available' => bcadd((string) $position['available'], '0', 8),
                ];
            }
            $position = $projectedPositions[$positionKey];
            $originalLayerAvailable = null;
            $dependentDocuments = [];
            if (bccomp($quantityIn, '0', 8) > 0) {
                $lineageTransactionIds = $this->layers->receiptLineageTransactionIds((int) $transaction->getKey());
                $originalLayerAvailable = bcadd((string) InventoryReceiptLayer::query()
                    ->whereIn('receipt_transaction_id', $lineageTransactionIds)
                    ->where('company_id', $transaction->company_id)
                    ->where('branch_store_id', $transaction->branch_store_id)
                    ->where('product_id', $transaction->product_id)
                    ->where('stock_status', $transaction->stock_status)
                    ->where('warehouse_location_id', $transaction->warehouse_location_id)
                    ->where('batch_lot', $transaction->batch_lot)
                    ->sum('remaining_quantity'), '0', 8);
                $dependentDocuments = DB::table('inventory_layer_allocations as allocation')
                    ->join('inventory_receipt_layers as layer', 'layer.id', '=', 'allocation.inventory_receipt_layer_id')
                    ->join('inventory_transactions as issue', 'issue.id', '=', 'allocation.issue_transaction_id')
                    ->join('inventory_documents as dependent', 'dependent.id', '=', 'issue.source_id')
                    ->whereIn('layer.receipt_transaction_id', $lineageTransactionIds)
                    ->where('issue.source_type', InventoryDocument::class)
                    ->where('issue.is_reversal', false)
                    ->where('dependent.company_id', $document->company_id)
                    ->where('dependent.status', InventoryDocument::StatusPosted)
                    ->where('dependent.id', '<>', $document->getKey())
                    ->distinct()->orderBy('dependent.doc_num')->pluck('dependent.doc_num')->all();
                if (bccomp($quantityIn, $position['available'], 8) > 0
                    || bccomp($quantityIn, $originalLayerAvailable, 8) > 0) {
                    $blockers[] = __('inventory.movements.reversal.receipt_consumed', [
                        'product' => $transaction->product?->doc_num ?? (string) $transaction->product_id,
                        'documents' => implode(', ', $dependentDocuments) ?: '—',
                    ]);
                }
            }

            $lines[] = [
                'product' => $transaction->product?->doc_num.' — '.$transaction->product?->name,
                'store' => $transaction->branchStore?->name,
                'stock_status' => $transaction->stock_status,
                'quantity_in' => $quantityIn,
                'quantity_out' => $quantityOut,
                'before_quantity' => $position['on_hand'],
                'after_quantity' => bcadd(bcsub($position['on_hand'], $quantityIn, 8), $quantityOut, 8),
                'original_layer_available' => $originalLayerAvailable,
                'dependent_documents' => $dependentDocuments,
                'value_delta' => $transaction->completedTotalCost() === null
                    ? null
                    : (bccomp($quantityIn, '0', 8) > 0
                        ? bcsub('0', (string) $transaction->completedTotalCost(), 8)
                        : bcadd((string) $transaction->completedTotalCost(), '0', 8)),
            ];
            $projectedPositions[$positionKey] = [
                'on_hand' => bcadd(bcsub($position['on_hand'], $quantityIn, 8), $quantityOut, 8),
                'available' => bcadd(bcsub($position['available'], $quantityIn, 8), $quantityOut, 8),
            ];
        }

        $plan = [
            'document' => $document->doc_num,
            'posting_date' => $postingDate,
            'can_reverse' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'lines' => $lines,
        ];
        $plan['preview_token'] = hash_hmac('sha256', json_encode([
            'document_id' => $document->getKey(),
            'updated_at' => $document->updated_at?->toISOString(),
            'period_closed' => (bool) $period->is_closed,
            'plan' => $plan,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));

        return $plan;
    }

    public function reverse(InventoryDocument $document, ?string $reason = null, ?string $postingDate = null): InventoryDocument
    {
        return $this->reverseDocument($document, $reason, postingDate: $postingDate);
    }

    public function reverseSalesReturnReceipt(InventoryDocument $document, SalesReturn $return, string $reason, ?int $correctionId = null): InventoryDocument
    {
        $proposal = $correctionId === null ? null : app(SalesReturnCorrectionService::class)
            ->execution($correctionId, 'return', (int) $return->id);

        return $this->reverseDocument($document, $reason, $return, postingDate: $proposal?->posting_date->toDateString(),
            correctionPeriodId: $proposal?->posting_financial_period_id, correctionId: $correctionId);
    }

    public function reverseSalesReturnDisposition(InventoryDocument $document, SalesReturn $return, string $reason, ?int $correctionId = null): InventoryDocument
    {
        $proposal = $correctionId === null ? null : app(SalesReturnCorrectionService::class)
            ->execution($correctionId, 'return', (int) $return->id);

        return $this->reverseDocument($document, $reason, $return, postingDate: $proposal?->posting_date->toDateString(),
            correctionPeriodId: $proposal?->posting_financial_period_id, correctionId: $correctionId);
    }

    public function reverseForProductionCorrection(InventoryDocument $document, ProductionRun $run, int $correctionId, string $reason): InventoryDocument
    {
        Gate::authorize('production.runs.correct_approve');
        $proposal = DB::table('production_run_corrections')->where('id', $correctionId)
            ->where('company_id', $run->company_id)->where('production_run_id', $run->getKey())->where('status', 'applying')->first();
        if (DB::transactionLevel() < 1 || $proposal === null || (int) $proposal->prepared_by === (int) auth()->id()
            || app(OperatingCompanyContextService::class)->currentCompanyId() !== (int) $run->company_id) {
            throw new DomainException(__('production_run_correction.invalid_state'));
        }

        $period = app(ProductionCorrectionContextService::class)->target($run, (string) $proposal->posting_date,
            $proposal->correction_mode ?? ProductionCorrectionContextService::OriginalPeriod,
            (int) ($proposal->posting_financial_period_id ?? $proposal->financial_period_id));

        return $this->reverseDocument($document, $reason, correctingRun: $run, postingDate: (string) $proposal->posting_date,
            correctionPeriodId: (int) $period->id, correctionId: $correctionId);
    }

    public function reverseForManualCorrection(InventoryDocument $document, int $proposalId): InventoryDocument
    {
        $proposal = app(InventoryMovementCorrectionService::class)->execution($proposalId, (int) $document->id);

        return $this->reverseDocument($document, $proposal->reason, postingDate: $proposal->posting_date->toDateString(),
            correctionPeriodId: (int) $proposal->posting_financial_period_id, manualCorrectionId: $proposalId);
    }

    public function reverseForInvoiceCorrection(InventoryDocument $document, int $proposalId): InventoryDocument
    {
        $proposal = app(CustomerInvoiceCorrectionService::class)->execution($proposalId, (int) $document->id);

        return $this->reverseDocument($document, $proposal->reason, postingDate: $proposal->posting_date->toDateString(),
            correctionPeriodId: (int) $proposal->posting_financial_period_id, invoiceCorrectionId: $proposalId);
    }

    private function reverseDocument(InventoryDocument $document, ?string $reason = null, ?SalesReturn $correctingReturn = null, ?ProductionRun $correctingRun = null, ?string $postingDate = null, ?int $correctionPeriodId = null, ?int $correctionId = null, ?int $manualCorrectionId = null, ?int $invoiceCorrectionId = null): InventoryDocument
    {
        return DB::transaction(function () use ($document, $reason, $correctingReturn, $correctingRun, $postingDate, $correctionPeriodId, $correctionId, $manualCorrectionId, $invoiceCorrectionId): InventoryDocument {
            Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            $invoiceCorrection = $invoiceCorrectionId === null ? null : app(CustomerInvoiceCorrectionService::class)->execution($invoiceCorrectionId, (int) $locked->id);

            if ($locked->status === InventoryDocument::StatusReversed) {
                if ($correctingReturn !== null) {
                    throw new DomainException(__('sales_return_correction.receipt_already_reversed'));
                }

                return $locked;
            }

            if ($locked->status !== InventoryDocument::StatusPosted) {
                throw new DomainException(__('Only a posted inventory document can be reversed.'));
            }
            if ($locked->source_document_type === MaintenanceMaterialRequest::class
                || in_array($locked->document_type, [
                    InventoryDocument::TypeMaintenanceMaterialIssue,
                    InventoryDocument::TypeMaintenanceMaterialReturn,
                ], true)) {
                throw new DomainException(__('Maintenance material inventory documents are controlled by the maintenance workflow.'));
            }
            if ($locked->source_document_type === ProductionQualityInspection::class) {
                throw new DomainException(__('production_execution.messages.quality_inventory_document_controlled'));
            }
            $isProductionCorrection = $correctingRun !== null
                && $correctingRun->status === ProductionRun::StatusCompleted
                && (int) $locked->company_id === (int) $correctingRun->company_id
                && (int) $locked->branch_id === (int) $correctingRun->branch_id
                && (int) $locked->production_run_id === (int) $correctingRun->getKey()
                && $locked->source_document_type === ProductionRun::class
                && (int) $locked->source_document_id === (int) $correctingRun->getKey()
                && in_array($locked->document_type, [InventoryDocument::TypeProductionReceipt, InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste], true)
                && ! $locked->lines()->where(fn ($query) => $query->whereNotNull('production_run_id')->where('production_run_id', '<>', $correctingRun->getKey()))->exists();
            if ($correctingRun !== null && ! $isProductionCorrection) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            if (! $isProductionCorrection && $invoiceCorrection === null && ($locked->production_order_id !== null
                || $locked->production_run_id !== null
                || $locked->production_run_batch_id !== null
                || in_array($locked->source_document_type, [ProductionRun::class, ProductionMaterialRequirement::class], true)
                || $locked->lines()->whereIn('source_line_type', [ProductionRun::class, ProductionMaterialRequirement::class])->exists())) {
                throw new DomainException(__('Production-linked inventory documents must be reversed through the production workflow.'));
            }
            $hasUnreversedDisposition = $correctingReturn !== null
                && InventoryDocument::query()->withTrashed()
                    ->where('source_document_type', SalesReturn::class)
                    ->where('source_document_id', $correctingReturn->getKey())
                    ->where('document_type', InventoryDocument::TypeTransfer)
                    ->where('status', '<>', InventoryDocument::StatusReversed)
                    ->exists();
            $closedReturnCreditSettled = $correctingReturn !== null
                && $correctingReturn->status === SalesReturn::StatusClosed
                && (($correctingReturn->credit_note_id === null && $correctingReturn->customer_invoice_id === null)
                    || ($correctingReturn->credit_note_id !== null
                        && $correctingReturn->creditNote?->status === CustomerInvoice::StatusCancelled
                        && $correctingReturn->creditNote?->posting_status === 'reversed'
                        && $correctingReturn->creditNote?->reversal_journal_entry_id !== null
                        && (int) $correctingReturn->creditNote?->journalEntry?->reversed_entry_id
                            === (int) $correctingReturn->creditNote?->reversal_journal_entry_id));
            $qualityCorrectionAllowed = $correctingReturn !== null
                && ($correctingReturn->status === SalesReturn::StatusInspected || $closedReturnCreditSettled);
            $returnDispositionSettled = $correctingReturn !== null
                && $qualityCorrectionAllowed
                && ! $hasUnreversedDisposition
                && ($correctingReturn->disposition_journal_entry_id === null
                    || $correctingReturn->dispositionJournalEntry?->reversed_entry_id !== null);
            $isControlledReturnReceipt = $correctingReturn !== null
                && (($correctingReturn->status === SalesReturn::StatusReceived
                    && ! $hasUnreversedDisposition
                    && $correctingReturn->disposition_journal_entry_id === null)
                    || $returnDispositionSettled)
                && (int) $correctingReturn->company_id === (int) $locked->company_id
                && (int) $correctingReturn->branch_id === (int) $locked->branch_id
                && (int) $correctingReturn->return_inventory_document_id === (int) $locked->getKey()
                && $locked->document_type === InventoryDocument::TypeSalesReturnReceipt
                && $locked->source_document_type === SalesReturn::class
                && (int) $locked->source_document_id === (int) $correctingReturn->getKey();
            $isControlledReturnDisposition = $correctingReturn !== null
                && $qualityCorrectionAllowed
                && (int) $correctingReturn->company_id === (int) $locked->company_id
                && (int) $correctingReturn->branch_id === (int) $locked->branch_id
                && (int) $correctingReturn->branch_store_id === (int) $locked->branch_store_id
                && $locked->document_type === InventoryDocument::TypeTransfer
                && $locked->source_document_type === SalesReturn::class
                && (int) $locked->source_document_id === (int) $correctingReturn->getKey()
                && $locked->source_stock_status === InventoryTransaction::StatusQuarantine
                && in_array($locked->movement_reason, ['sales_return_saleable', 'sales_return_rework', 'sales_return_scrap'], true);
            if ($correctingReturn !== null && ! $isControlledReturnReceipt && ! $isControlledReturnDisposition) {
                throw new DomainException(__('sales_return_correction.invalid_source'));
            }
            if (($locked->source_document_type !== null || $locked->source_document_id !== null)
                && $locked->document_type !== InventoryDocument::TypeSalesDelivery
                && ! $isControlledReturnReceipt
                && ! $isControlledReturnDisposition
                && ! $isProductionCorrection) {
                throw new DomainException(__('inventory.movements.reversal.source_workflow_required'));
            }

            $salesOrder = null;
            if ($locked->document_type === InventoryDocument::TypeSalesDelivery) {
                if ($invoiceCorrection === null && $locked->sales_issue_order_id !== null && $locked->customerDeliveryReceipt()->exists()) {
                    throw new DomainException(__('sales_issue.messages.signed_issue_cannot_reverse'));
                }
                if ($locked->source_document_type === SalesOrder::class) {
                    $salesOrder = SalesOrder::query()->lockForUpdate()->findOrFail($locked->source_document_id);
                }
                $salesReturn = SalesReturn::query()->where('delivery_document_id', $locked->id)->where('status', '<>', SalesReturn::StatusCancelled)->first();
                if ($salesReturn) {
                    throw new DomainException(__('Delivery is linked to return :return.', ['return' => $salesReturn->doc_num]));
                }
                $invoice = CustomerInvoice::query()->whereHas('lines', fn ($query) => $query->whereIn('delivery_line_id', $locked->lines()->select('id')))->first();
                if ($invoice && $invoiceCorrection === null) {
                    throw new DomainException(__('Delivery :delivery cannot be reversed because it is linked to invoice :invoice.', ['delivery' => $locked->doc_num, 'invoice' => $invoice->doc_num]));
                }
            }

            $salesCorrection = $correctingReturn !== null && $correctionId !== null
                ? app(SalesReturnCorrectionService::class)->execution($correctionId, 'return', (int) $correctingReturn->id) : null;
            $manualCorrection = $manualCorrectionId === null ? null
                : app(InventoryMovementCorrectionService::class)->execution($manualCorrectionId, (int) $locked->id);
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($locked->financial_period_id);

            if (($period->is_closed && ! $isProductionCorrection && $salesCorrection === null && $manualCorrection === null && $invoiceCorrection === null) || (int) $period->company_id !== (int) $locked->company_id) {
                throw new DomainException(__('Inventory movements cannot be reversed in a closed or unrelated financial period.'));
            }

            $transactions = InventoryTransaction::query()
                ->where('source_type', InventoryDocument::class)
                ->where('source_id', $locked->getKey())
                ->where('is_reversal', false)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if (! $this->originalTransactionsComplete($locked, $transactions)) {
                throw new DomainException(__('inventory.movements.reversal.lineage_incomplete'));
            }

            $completionDate = InventoryValueAdjustment::query()->where('company_id', $locked->company_id)
                ->where('status', InventoryValueAdjustment::StatusPosted)
                ->whereHas('lines', fn ($query) => $query->whereIn('source_transaction_id', $transactions->modelKeys()))->max('posting_date');
            $completionDate = $completionDate === null ? null : Carbon::parse($completionDate)->toDateString();
            $reversalDate = $completionDate !== null && $completionDate > $locked->document_date->toDateString()
                ? $completionDate : $locked->document_date->toDateString();
            if ($postingDate !== null) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $postingDate) || $postingDate < $reversalDate) {
                    throw new DomainException(__('inventory.movements.messages.receipt_completion_date_before_movements'));
                }
                $reversalDate = $postingDate;
            }
            foreach ($transactions->pluck('branch_store_id')->unique() as $storeId) {
                $this->costPolicies->assertPostingDateAllowed((int) $locked->company_id, (int) $storeId, $reversalDate);
            }
            app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $locked->company_id, $reversalDate,
                expectedPeriodId: ($isProductionCorrection || $salesCorrection !== null || $manualCorrection !== null || $invoiceCorrection !== null) ? $correctionPeriodId : (int) $locked->financial_period_id, lockForUpdate: true);
            foreach ($transactions->sortByDesc(fn (InventoryTransaction $transaction): bool => bccomp((string) $transaction->quantity_in, '0', 8) > 0) as $transaction) {
                if (bccomp((string) $transaction->quantity_in, '0', 8) > 0) {
                    $position = $this->availability->forProduct(
                        (int) $transaction->company_id,
                        (int) $transaction->branch_store_id,
                        (int) $transaction->product_id,
                        null,
                        $transaction->warehouse_location_id,
                        (string) $transaction->stock_status,
                        $transaction->batch_lot,
                        true,
                    );

                    if (bccomp((string) $transaction->quantity_in, $position['available'], 8) > 0) {
                        throw new DomainException(__('The document cannot be reversed because its received stock has already been consumed or moved.'));
                    }
                }

                $completedCost = $transaction->completedTotalCost();
                $completedQuantity = bccomp((string) $transaction->quantity_in, '0', 8) > 0 ? (string) $transaction->quantity_in : (string) $transaction->quantity_out;
                $reversalAttributes = [
                    ...$transaction->only([
                        'company_id', 'financial_period_id', 'branch_id', 'branch_store_id',
                        'branch_hall_id', 'warehouse_location_id', 'stock_status', 'batch_lot',
                        'manufacture_date', 'expiry_date',
                        'transaction_date', 'transaction_type', 'product_id', 'unit_id',
                        'source_type', 'source_id', 'source_doc_num', 'source_line_type',
                        'source_line_id', 'supplier_id', 'customer_id', 'production_order_id',
                        'production_run_id', 'inventory_reservation_id', 'unit_cost', 'total_cost',
                        'cost_policy_id', 'cost_method',
                        'inventory_serial_identity_id',
                    ]),
                    'transaction_date' => $reversalDate,
                    'financial_period_id' => ($isProductionCorrection || $salesCorrection !== null || $manualCorrection !== null || $invoiceCorrection !== null) ? $correctionPeriodId : $transaction->financial_period_id,
                    'unit_cost' => $completedCost === null ? null : bcdiv($completedCost, $completedQuantity, 8),
                    'total_cost' => $completedCost,
                    'quantity_in' => $transaction->quantity_out,
                    'quantity_out' => $transaction->quantity_in,
                    'is_reversal' => true,
                    'reversal_of_id' => $transaction->getKey(),
                    'notes' => 'Reversal of '.$transaction->posting_key,
                    'cost_basis' => 'reversal',
                    'created_by' => auth()->id(),
                ];
                $reversal = InventoryTransaction::query()->firstOrCreate(
                    ['posting_key' => $transaction->posting_key.':reversal'],
                    $reversalAttributes,
                );
                $this->assertReversalMatches($reversal, $reversalAttributes);
                if (bccomp((string) $reversal->quantity_out, '0', 8) > 0) {
                    $this->layers->allocateIssue($reversal, (int) $transaction->getKey());
                } else {
                    $this->layers->recordInbound($reversal, $transaction);
                }
            }

            if ($isProductionCorrection) {
                $this->accounting->reverseForProductionCorrection($locked, $correctingRun, $correctionId);
            } elseif ($salesCorrection !== null) {
                $this->accounting->reverseForSalesReturnCorrection($locked, $correctingReturn, $correctionId);
            } elseif ($manualCorrection !== null) {
                $this->accounting->reverseForManualCorrection($locked, (int) $manualCorrection->id);
            } elseif ($invoiceCorrection !== null) {
                $this->accounting->reverseForInvoiceCorrection($locked, (int) $invoiceCorrection->id);
            } else {
                $this->accounting->reverse($locked, $reversalDate);
            }

            if ($salesOrder) {
                foreach ($locked->lines as $deliveryLine) {
                    $salesLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($deliveryLine->source_line_id);
                    $salesLine->decrement('delivered_quantity', $deliveryLine->transaction_quantity);
                    $salesLine->decrement('delivered_base_quantity', $deliveryLine->quantity);
                }
                $from = $salesOrder->status;
                $status = $salesOrder->lines()->where('delivered_quantity', '>', 0)->exists()
                    ? SalesOrder::StatusPartiallyFulfilled : SalesOrder::StatusApproved;
                $salesOrder->update(['status' => $status, 'updated_by' => auth()->id()]);
                $salesOrder->statusHistory()->create(['from_status' => $from, 'to_status' => $status, 'reason' => 'Delivery reversal '.$locked->doc_num, 'changed_by' => auth()->id(), 'changed_at' => now()]);
            }

            $locked->update([
                'status' => InventoryDocument::StatusReversed,
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'reversal_reason' => $reason === null ? null : trim($reason),
                'updated_by' => auth()->id(),
            ]);
            if ($locked->sales_issue_order_id !== null && $invoiceCorrection === null) {
                $issueOrder = SalesIssueOrder::query()->with('invoice.order')->lockForUpdate()->findOrFail($locked->sales_issue_order_id);
                $issueInvoice = CustomerInvoice::query()->lockForUpdate()->findOrFail($issueOrder->customer_invoice_id);
                if ((int) $issueInvoice->delivery_document_id === (int) $locked->getKey()) {
                    $replacementDeliveryId = DB::table('customer_invoice_deliveries as link')
                        ->join('inventory_documents as document', 'document.id', '=', 'link.inventory_document_id')
                        ->where('link.customer_invoice_id', $issueInvoice->getKey())
                        ->where('document.id', '!=', $locked->getKey())
                        ->where('document.status', InventoryDocument::StatusPosted)
                        ->whereNull('document.deleted_at')
                        ->orderBy('document.id')
                        ->value('document.id');
                    $issueInvoice->update(['delivery_document_id' => $replacementDeliveryId]);
                }
                $issueOrder->update([
                    'status' => SalesIssueOrder::StatusPending,
                    'branch_store_id' => $issueOrder->invoice?->order?->branch_store_id,
                    'issued_by' => null,
                    'issued_at' => null,
                ]);
            }

            return $locked->refresh()->load(['lines', 'transactions']);
        });
    }

    /** @param array<string, mixed> $expected */
    private function assertReversalMatches(InventoryTransaction $reversal, array $expected): void
    {
        foreach ($expected as $field => $value) {
            if ($field === 'created_by') {
                continue;
            }
            $actual = $reversal->getAttribute($field);
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            if ($actual instanceof \DateTimeInterface) {
                $actual = $actual->format('Y-m-d');
            }
            if (in_array($field, ['quantity_in', 'quantity_out', 'unit_cost', 'total_cost'], true)) {
                $matches = $value === null && $actual === null
                    || $value !== null && $actual !== null && bccomp((string) $actual, (string) $value, 8) === 0;
            } else {
                $matches = $value === null && $actual === null
                    || $value !== null && $actual !== null && (string) $actual === (string) $value;
            }
            if (! $matches) {
                throw new DomainException(__('inventory.movements.reversal.lineage_incomplete'));
            }
        }
    }

    /**
     * @return array{outbound: bool, inbound: bool, source_status: string, destination_status: string, destination_store_id: int|null}
     */
    /** @param Collection<int, InventoryTransaction> $transactions */
    private function originalTransactionsComplete(InventoryDocument $document, Collection $transactions): bool
    {
        $profile = $this->movementProfile($document);
        $expected = [];
        foreach ($document->lines()->get() as $line) {
            $quantity = bcadd((string) $line->quantity, '0', 8);
            if (bccomp($quantity, '0', 8) <= 0) {
                continue;
            }
            foreach (['out', 'in'] as $direction) {
                if (! $profile[$direction === 'out' ? 'outbound' : 'inbound']) {
                    continue;
                }
                $key = "inventory-document:{$document->id}:line:{$line->id}:{$direction}";
                $expected[$key] = [
                    'line' => $line,
                    'direction' => $direction,
                    'quantity' => $quantity,
                    'branch_store_id' => $direction === 'out'
                        ? (int) $document->branch_store_id
                        : (int) ($profile['destination_store_id'] ?? $document->branch_store_id),
                    'branch_id' => $direction === 'out'
                        ? (int) $document->branch_id
                        : (int) ($document->destinationBranchStore?->branch_id ?? $document->branch_id),
                    'stock_status' => $direction === 'out' ? $profile['source_status'] : $profile['destination_status'],
                    'warehouse_location_id' => $direction === 'out'
                        ? ($line->warehouse_location_id ?? $document->warehouse_location_id)
                        : ($line->destination_warehouse_location_id
                            ?? $document->destination_warehouse_location_id
                            ?? $line->warehouse_location_id
                            ?? $document->warehouse_location_id),
                ];
            }
        }
        if ($expected === [] || count($expected) !== $transactions->count()) {
            return false;
        }

        foreach ($transactions as $transaction) {
            $source = $expected[$transaction->posting_key] ?? null;
            if ($source === null
                || (int) $transaction->company_id !== (int) $document->company_id
                || (int) $transaction->financial_period_id !== (int) $document->financial_period_id
                || (int) $transaction->branch_store_id !== $source['branch_store_id']
                || (int) $transaction->branch_id !== $source['branch_id']
                || (int) $transaction->product_id !== (int) $source['line']->product_id
                || (int) $transaction->unit_id !== (int) $source['line']->unit_id
                || (string) $transaction->transaction_type !== (string) $document->document_type
                || $transaction->transaction_date?->toDateString() !== $document->document_date?->toDateString()
                || $transaction->stock_status !== $source['stock_status']
                || (string) $transaction->warehouse_location_id !== (string) $source['warehouse_location_id']
                || $transaction->batch_lot !== $source['line']->batch_lot
                || $transaction->source_type !== InventoryDocument::class
                || (int) $transaction->source_id !== (int) $document->getKey()
                || $transaction->source_doc_num !== $document->doc_num
                || $transaction->source_line_type !== $source['line']->source_line_type
                || (string) $transaction->source_line_id !== (string) $source['line']->source_line_id
                || $transaction->is_reversal
                || ($transaction->unit_cost === null) !== ($source['line']->unit_cost === null)
                || ($transaction->total_cost === null) !== ($source['line']->total_cost === null)
                || ($transaction->unit_cost !== null && bccomp((string) $transaction->unit_cost, (string) $source['line']->unit_cost, 8) !== 0)
                || ($transaction->total_cost !== null && bccomp((string) $transaction->total_cost, (string) $source['line']->total_cost, 8) !== 0)
                || bccomp((string) $transaction->quantity_in, $source['direction'] === 'in' ? $source['quantity'] : '0', 8) !== 0
                || bccomp((string) $transaction->quantity_out, $source['direction'] === 'out' ? $source['quantity'] : '0', 8) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function movementProfile(InventoryDocument $document): array
    {
        $transferTypes = [
            InventoryDocument::TypeTransfer,
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeDamage,
        ];
        $outboundTypes = [
            InventoryDocument::TypeSalesDelivery,
            InventoryDocument::TypeIssue,
            InventoryDocument::TypeAdjustmentOut,
            InventoryDocument::TypeMaintenanceMaterialIssue,
            InventoryDocument::TypeMaterialConsumption,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeScrap,
        ];

        $sourceStatus = $document->source_stock_status ?: match ($document->document_type) {
            InventoryDocument::TypeMaterialConsumption,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeMaterialReturn => InventoryTransaction::StatusProductionStaging,
            default => InventoryTransaction::StatusAvailable,
        };
        $destinationStatus = $document->destination_stock_status ?: match ($document->document_type) {
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue => InventoryTransaction::StatusProductionStaging,
            InventoryDocument::TypeDamage => InventoryTransaction::StatusDamaged,
            default => InventoryTransaction::StatusAvailable,
        };
        $isTransfer = in_array($document->document_type, $transferTypes, true);

        return [
            'outbound' => $isTransfer || in_array($document->document_type, $outboundTypes, true),
            'inbound' => $isTransfer || ! in_array($document->document_type, $outboundTypes, true),
            'source_status' => $sourceStatus,
            'destination_status' => $destinationStatus,
            'destination_store_id' => $document->destination_branch_store_id
                ? (int) $document->destination_branch_store_id
                : ($isTransfer ? (int) $document->branch_store_id : null),
        ];
    }

    private function assertPositionCanIssue(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $quantity,
        string $stockStatus,
    ): void {
        $position = $this->availability->forProduct(
            (int) $document->company_id,
            (int) $document->branch_store_id,
            (int) $line->product_id,
            $line->source_line_type === SalesOrderLine::class ? (int) $line->source_line_id : null,
            $line->warehouse_location_id ?? $document->warehouse_location_id,
            $stockStatus,
            $line->batch_lot,
            $line->warehouse_location_id !== null
                || $document->warehouse_location_id !== null
                || filled($line->batch_lot),
        );

        if (bccomp($quantity, $position['available'], 8) > 0) {
            throw new DomainException(__(
                'The inventory movement exceeds unreserved stock in the selected store, location, batch, and status. Document: :document; product ID: :product_id; requested: :requested; available: :available; store ID: :store_id; status: :status.',
                [
                    'document' => $document->doc_num,
                    'product_id' => (int) $line->product_id,
                    'requested' => $quantity,
                    'available' => $position['available'],
                    'store_id' => (int) $document->branch_store_id,
                    'status' => $stockStatus,
                ],
            ));
        }
    }

    private function validatedSourceIssue(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $quantity,
    ): ?InventoryTransaction {
        $sourceIssueId = $line->product_snapshot['source_issue_transaction_id'] ?? null;
        if ($sourceIssueId === null && $document->document_type !== InventoryDocument::TypeSalesReturnReceipt) {
            return null;
        }

        $canonicalSourceIssue = $document->document_type === InventoryDocument::TypeSalesReturnReceipt
            ? $this->canonicalSalesReturnSourceIssue($document, $line)
            : null;
        $sourceIssue = $canonicalSourceIssue ?? (is_numeric($sourceIssueId)
            ? InventoryTransaction::query()->lockForUpdate()->find((int) $sourceIssueId)
            : null);

        if ($canonicalSourceIssue instanceof InventoryTransaction) {
            $line->forceFill([
                'batch_lot' => $sourceIssue->batch_lot,
                'manufacture_date' => $sourceIssue->manufacture_date,
                'expiry_date' => $sourceIssue->expiry_date,
            ])->save();
        }
        $sourceDocument = $sourceIssue?->source_type === InventoryDocument::class
            ? InventoryDocument::query()->lockForUpdate()->find($sourceIssue->source_id)
            : null;
        $expectedProductionRunId = $line->production_run_id ?? $document->production_run_id;
        $isMaintenanceAllocationBound = $document->document_type === InventoryDocument::TypeMaintenanceMaterialReturn
            && isset($line->product_snapshot['restoration_allocation_id']);

        if (! $sourceIssue instanceof InventoryTransaction
            || ! $sourceDocument instanceof InventoryDocument
            || (int) $sourceIssue->company_id !== (int) $document->company_id
            || (int) $sourceIssue->branch_id !== (int) $document->branch_id
            || ($document->document_type !== InventoryDocument::TypeSalesReturnReceipt
                && (int) $sourceIssue->branch_store_id !== (int) $document->branch_store_id)
            || (int) $sourceIssue->product_id !== (int) $line->product_id
            || $sourceIssue->is_reversal
            || bccomp((string) $sourceIssue->quantity_in, '0', 8) !== 0
            || bccomp((string) $sourceIssue->quantity_out, $quantity, 8) < 0
            || $sourceIssue->completedTotalCost() === null
            || (! $isMaintenanceAllocationBound && $sourceIssue->batch_lot !== $line->batch_lot)
            || ($sourceIssue->production_run_id === null ? null : (int) $sourceIssue->production_run_id) !== ($expectedProductionRunId === null ? null : (int) $expectedProductionRunId)
            || $sourceIssue->transaction_date?->gt($document->document_date)
            || $sourceDocument->status !== InventoryDocument::StatusPosted
            || ! $this->sourceIssueLinkMatches($document, $line, $sourceIssue, $sourceDocument)) {
            throw new DomainException(__('The linked source issue does not match this inventory return line.'));
        }

        return $sourceIssue;
    }

    private function hydrateSalesReturnDispositionDimensions(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $sourceStatus,
    ): void {
        if ($document->document_type !== InventoryDocument::TypeTransfer
            || $document->source_document_type !== SalesReturn::class
            || $line->source_line_type !== SalesReturnLine::class
            || $sourceStatus !== InventoryTransaction::StatusQuarantine) {
            return;
        }

        $salesReturn = SalesReturn::query()->lockForUpdate()->find($document->source_document_id);
        $sourceReceipt = $salesReturn?->return_inventory_document_id
            ? InventoryTransaction::query()
                ->where('source_type', InventoryDocument::class)
                ->where('source_id', $salesReturn->return_inventory_document_id)
                ->where('source_line_type', SalesReturnLine::class)
                ->where('source_line_id', $line->source_line_id)
                ->where('product_id', $line->product_id)
                ->where('stock_status', InventoryTransaction::StatusQuarantine)
                ->where('quantity_in', '>', 0)
                ->where('is_reversal', false)
                ->lockForUpdate()
                ->latest('id')
                ->first()
            : null;

        if (! $sourceReceipt instanceof InventoryTransaction) {
            return;
        }

        $line->forceFill([
            'warehouse_location_id' => $sourceReceipt->warehouse_location_id,
            'batch_lot' => $sourceReceipt->batch_lot,
            'manufacture_date' => $sourceReceipt->manufacture_date,
            'expiry_date' => $sourceReceipt->expiry_date,
        ])->save();
    }

    private function sourceIssueLinkMatches(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        InventoryTransaction $sourceIssue,
        InventoryDocument $sourceDocument,
    ): bool {
        if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialReturn) {
            return $sourceDocument->document_type === InventoryDocument::TypeMaintenanceMaterialIssue
                && $sourceDocument->source_document_type === $document->source_document_type
                && (int) $sourceDocument->source_document_id === (int) $document->source_document_id
                && $sourceIssue->source_line_type === $line->source_line_type
                && (int) $sourceIssue->source_line_id === (int) $line->source_line_id;
        }

        if ($document->document_type !== InventoryDocument::TypeSalesReturnReceipt
            || $document->source_document_type !== SalesReturn::class
            || $line->source_line_type !== SalesReturnLine::class) {
            return false;
        }

        $salesReturn = SalesReturn::query()
            ->where('company_id', $document->company_id)
            ->lockForUpdate()
            ->find($document->source_document_id);
        $returnLine = $salesReturn instanceof SalesReturn
            ? SalesReturnLine::query()
                ->where('sales_return_id', $salesReturn->getKey())
                ->where('product_id', $line->product_id)
                ->lockForUpdate()
                ->find($line->source_line_id)
            : null;
        $deliveryLine = $returnLine instanceof SalesReturnLine
            ? InventoryDocumentLine::query()
                ->lockForUpdate()
                ->find($returnLine->delivery_line_id)
            : null;

        return $salesReturn instanceof SalesReturn
            && $returnLine instanceof SalesReturnLine
            && $deliveryLine instanceof InventoryDocumentLine
            && (int) $deliveryLine->inventory_document_id === (int) $sourceDocument->getKey()
            && $sourceDocument->document_type === InventoryDocument::TypeSalesDelivery
            && $sourceIssue->posting_key === "inventory-document:{$deliveryLine->inventory_document_id}:line:{$deliveryLine->id}:out"
            && $sourceIssue->source_line_type === $deliveryLine->source_line_type
            && (int) $sourceIssue->source_line_id === (int) $deliveryLine->source_line_id;
    }

    private function canonicalSalesReturnSourceIssue(
        InventoryDocument $document,
        InventoryDocumentLine $line,
    ): ?InventoryTransaction {
        if ($document->source_document_type !== SalesReturn::class
            || $line->source_line_type !== SalesReturnLine::class) {
            return null;
        }

        $returnLine = SalesReturnLine::query()
            ->where('sales_return_id', $document->source_document_id)
            ->where('product_id', $line->product_id)
            ->lockForUpdate()
            ->find($line->source_line_id);
        $deliveryLine = $returnLine instanceof SalesReturnLine
            ? InventoryDocumentLine::query()->lockForUpdate()->find($returnLine->delivery_line_id)
            : null;

        if (! $deliveryLine instanceof InventoryDocumentLine) {
            return null;
        }

        return InventoryTransaction::query()
            ->where('posting_key', "inventory-document:{$deliveryLine->inventory_document_id}:line:{$deliveryLine->id}:out")
            ->where('source_type', InventoryDocument::class)
            ->where('source_id', $deliveryLine->inventory_document_id)
            ->where('source_line_type', $deliveryLine->source_line_type)
            ->where('source_line_id', $deliveryLine->source_line_id)
            ->where('product_id', $line->product_id)
            ->where('warehouse_location_id', $deliveryLine->warehouse_location_id)
            ->when(
                $deliveryLine->batch_lot !== null,
                fn ($query) => $query->where('batch_lot', $deliveryLine->batch_lot),
                fn ($query) => $query->whereNull('batch_lot'),
            )
            ->where('quantity_in', 0)
            ->where('quantity_out', '>', 0)
            ->where('is_reversal', false)
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    private function assertQualityHoldAuthority(InventoryDocument $document, string $sourceStatus, string $destinationStatus): void
    {
        if (($sourceStatus === InventoryTransaction::StatusQcHold || $destinationStatus === InventoryTransaction::StatusQcHold)
            && $document->source_document_type !== ProductionQualityInspection::class) {
            throw new DomainException(__('production_execution.messages.quality_hold_movement_controlled'));
        }
    }

    private function createTransaction(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        string $direction,
        int $branchStoreId,
        int $branchId,
        mixed $warehouseLocationId,
        string $stockStatus,
        string $quantityIn,
        string $quantityOut,
        ?string $unitCost,
        ?string $costMethod,
        ?int $costPolicyId,
        ?string $totalCost = null,
        ?string $costBasis = null,
    ): InventoryTransaction {
        $postingKey = "inventory-document:{$document->id}:line:{$line->id}:{$direction}";

        $transaction = InventoryTransaction::query()->firstOrCreate(
            ['posting_key' => $postingKey],
            [
                'company_id' => $document->company_id,
                'financial_period_id' => $document->financial_period_id,
                'branch_id' => $branchId,
                'branch_store_id' => $branchStoreId,
                'branch_hall_id' => $branchId === (int) $document->branch_id ? $document->branch_hall_id : null,
                'warehouse_location_id' => $warehouseLocationId,
                'stock_status' => $stockStatus,
                'batch_lot' => $line->batch_lot,
                'manufacture_date' => $line->manufacture_date,
                'expiry_date' => $line->expiry_date,
                'transaction_date' => $document->document_date,
                'transaction_type' => $document->document_type,
                'product_id' => $line->product_id,
                'inventory_serial_identity_id' => $line->inventory_serial_identity_id,
                'unit_id' => $line->unit_id,
                'quantity_in' => $quantityIn,
                'quantity_out' => $quantityOut,
                'source_type' => InventoryDocument::class,
                'source_id' => $document->getKey(),
                'source_doc_num' => $document->doc_num,
                'source_line_type' => $line->source_line_type,
                'source_line_id' => $line->source_line_id,
                'customer_id' => $document->customer_id,
                'production_order_id' => $document->production_order_id,
                'production_run_id' => $line->production_run_id ?? $document->production_run_id,
                'inventory_reservation_id' => $line->inventory_reservation_id ?? null,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost ?? ($unitCost === null
                    ? null
                    : bcmul(bcadd($quantityIn, $quantityOut, 8), $unitCost, 8)),
                'cost_method' => $costMethod,
                'cost_policy_id' => $costPolicyId,
                'cost_basis' => $costBasis ?? ($direction === 'out' && InventoryCostPolicy::usesReceiptLayers($costMethod) ? ($costMethod === InventoryCostPolicy::SpecificIdentification ? 'selected_receipt_layer' : 'fifo_allocations') : ($costMethod === InventoryCostPolicy::PeriodicWeightedAverage ? 'periodic_provisional_moving_average' : 'moving_average')),
                'created_by' => auth()->id(),
            ],
        );

        if (bccomp($quantityOut, '0', 8) > 0) {
            $this->layers->allocateIssue($transaction, selectedLayerId: $line->selected_receipt_layer_id === null ? null : (int) $line->selected_receipt_layer_id);
        }

        return $transaction;
    }

    private function assertChronologicalPosting(
        InventoryDocument $document,
        InventoryDocumentLine $line,
    ): void {
        $hasLaterMovement = InventoryTransaction::query()
            ->where('company_id', $document->company_id)
            ->where('branch_store_id', $document->branch_store_id)
            ->where('product_id', $line->product_id)
            ->whereDate('transaction_date', '>', $document->document_date)
            ->exists();

        if ($hasLaterMovement) {
            throw new DomainException(__('Backdated inventory posting is blocked because later valued movements already exist for this product and store.'));
        }
    }

    /**
     * Resolve and validate a specific restoration allocation for a maintenance return line.
     * Returns null when the line does not carry explicit allocation lineage, letting the
     * layer service fall back to the general source-issue allocation lookup.
     *
     * Validation:
     *  - Only MaintenanceMaterialReturn documents may use restoration_allocation_id.
     *  - allocation.issue_transaction_id must equal the linked source issue ID.
     *  - allocation layer must exist and match company/store/product/status of the return context.
     *  - return line location, batch, manufacture/expiry must agree with the allocation layer.
     *  - return slice quantity must not exceed allocation quantity.
     *
     * @return Collection<int, InventoryLayerAllocation>|null
     */
    private function resolveRestorationAllocations(
        InventoryDocument $document,
        InventoryDocumentLine $line,
        ?InventoryTransaction $sourceIssue,
    ): ?Collection {
        $allocationId = $line->product_snapshot['restoration_allocation_id'] ?? null;
        if ($allocationId === null || ! is_numeric($allocationId)) {
            return null;
        }

        if ($document->document_type !== InventoryDocument::TypeMaintenanceMaterialReturn) {
            throw new DomainException(__('Only maintenance material returns may use restoration allocation lineage.'));
        }

        $allocation = InventoryLayerAllocation::query()
            ->with(['layer', 'layer.receiptTransaction'])
            ->where('id', (int) $allocationId)
            ->lockForUpdate()
            ->first();

        if ($allocation === null) {
            throw new DomainException(__('The maintenance return references a restoration allocation that does not exist.'));
        }

        if (! $sourceIssue instanceof InventoryTransaction) {
            throw new DomainException(__('Restoration allocation requires a validated source issue transaction.'));
        }

        if ((int) $allocation->issue_transaction_id !== (int) $sourceIssue->getKey()) {
            throw new DomainException(__('The restoration allocation does not belong to the linked source issue.'));
        }

        $layer = $allocation->layer;
        if ($layer === null) {
            throw new DomainException(__('The restoration allocation is missing its receipt layer lineage.'));
        }

        if ((int) $layer->company_id !== (int) $document->company_id
            || (int) $layer->financial_period_id !== (int) $document->financial_period_id
            || (int) $layer->branch_id !== (int) $document->branch_id
            || (int) $layer->branch_store_id !== (int) $document->branch_store_id
            || (int) $layer->product_id !== (int) $line->product_id
            || $layer->stock_status !== $document->destination_stock_status) {
            throw new DomainException(__('The restoration allocation layer does not match the return document context.'));
        }

        $receiptTransaction = $layer->receiptTransaction;
        if ($receiptTransaction !== null
            && $receiptTransaction->production_run_id !== null
            && ($document->production_run_id === null
                || (int) $receiptTransaction->production_run_id !== (int) $document->production_run_id)) {
            throw new DomainException(__('The restoration allocation receipt production run does not match the return document.'));
        }

        $lineLocationId = $line->warehouse_location_id;
        if (($layer->warehouse_location_id ?? null) !== ($lineLocationId ?? null)) {
            throw new DomainException(__('The return line location does not match the restoration allocation layer location.'));
        }

        $lineBatchLot = $line->batch_lot;
        if (($layer->batch_lot ?? null) !== ($lineBatchLot ?? null)) {
            throw new DomainException(__('The return line batch does not match the restoration allocation layer batch.'));
        }

        $lineManufactureDate = $line->manufacture_date?->toDateString();
        $layerManufactureDate = $layer->manufacture_date?->toDateString();
        if (($layerManufactureDate ?? null) !== ($lineManufactureDate ?? null)) {
            throw new DomainException(__('The return line manufacture date does not match the restoration allocation layer.'));
        }

        $lineExpiryDate = $line->expiry_date?->toDateString();
        $layerExpiryDate = $layer->expiry_date?->toDateString();
        if (($layerExpiryDate ?? null) !== ($lineExpiryDate ?? null)) {
            throw new DomainException(__('The return line expiry date does not match the restoration allocation layer.'));
        }

        if (bccomp((string) $line->quantity, (string) $allocation->quantity, 8) > 0) {
            throw new DomainException(__('The return slice quantity exceeds its restoration allocation quantity.'));
        }

        return collect([$allocation]);
    }
}
