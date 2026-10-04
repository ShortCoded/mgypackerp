<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;

/** Repair an exact historical allocation exchange without changing posted amounts. */
final class LegacyReceiptAllocationRepairService
{
    public function supports(InventoryDocument $document): bool
    {
        return $document->document_type === InventoryDocument::TypeReceipt && $document->status === InventoryDocument::StatusReversed
            && $document->source_document_type === null && $document->source_document_id === null
            && $document->production_order_id === null && $document->production_run_id === null && $document->production_run_batch_id === null
            && ! $document->lines()->where(fn ($q) => $q->whereNotNull('unit_cost')->orWhereNotNull('total_cost'))->exists();
    }

    /** @return array<string, mixed> */
    public function plan(InventoryDocument $document, ?int $currentProposalId = null): array
    {
        $this->check($this->supports($document) && $document->journal_entry_id === null && $document->reversal_journal_entry_id === null);
        if ($currentProposalId !== null) {
            InventoryMovementCorrection::query()->where('company_id', $document->company_id)->where('inventory_document_id', $document->id)
                ->where('operation', 'repair_lineage')->whereIn('status', ['prepared', 'applying'])->findOrFail($currentProposalId);
        }
        $priorRepairs = InventoryMovementCorrection::query()->where('company_id', $document->company_id)
            ->where(fn ($q) => $q->where('inventory_document_id', $document->id)->orWhere('source_snapshot->document->id', $document->id))
            ->whereNotNull('source_snapshot->legacy_repair')
            ->where(fn ($q) => $q->whereNotNull('execution_fingerprint')->orWhereNotNull('approved_at'))
            ->when($currentProposalId !== null, fn ($q) => $q->where('id', '<>', $currentProposalId))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($priorRepairs as $priorRepair) {
            app(InventoryMovementCorrectionService::class)->assertApproved($priorRepair);
        }
        $receipts = $document->transactions()->where('is_reversal', false)->where('quantity_in', '>', 0)->orderBy('id')->lockForUpdate()->get();
        $this->check($receipts->isNotEmpty() && $receipts->count() === $document->lines()->count() && $receipts->pluck('product_id')->unique()->count() === $receipts->count());
        Product::query()->whereIn('id', $receipts->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
        $plans = $issues = $foreignIds = [];
        $misplaced = $exchanged = $valueChange = '0.00000000';
        foreach ($receipts as $receipt) {
            $reverse = InventoryTransaction::query()->where('reversal_of_id', $receipt->id)->where('is_reversal', true)->lockForUpdate()->sole();
            $own = InventoryReceiptLayer::query()->where('receipt_transaction_id', $receipt->id)->lockForUpdate()->sole();
            $this->check($receipt->unit_cost === null && $receipt->total_cost === null && $reverse->unit_cost === null && $reverse->total_cost === null
                && $own->unit_cost === null && $own->inventory_serial_identity_id === null
                && $this->samePosition($own, $receipt) && $this->samePosition($own, $reverse)
                && bccomp($own->original_quantity, '0', 8) > 0 && bccomp($own->remaining_quantity, '0', 8) >= 0
                && bccomp($receipt->quantity_in, $own->original_quantity, 8) === 0 && bccomp($receipt->quantity_in, $reverse->quantity_out, 8) === 0 && bccomp($reverse->quantity_in, '0', 8) === 0);
            $this->plainLayer($own, true);
            $allocations = InventoryLayerAllocation::query()->where('issue_transaction_id', $reverse->id)->orderBy('id')->lockForUpdate()->get();
            $this->check(bccomp($this->sum($allocations), $receipt->quantity_in, 8) === 0);
            $foreign = $allocations->where('inventory_receipt_layer_id', '<>', $own->id);
            $others = $own->allocations()->where('issue_transaction_id', '<>', $reverse->id)->orderBy('id')->lockForUpdate()->get();
            $this->check($foreign->isNotEmpty() || $others->isEmpty());
            $this->check(bccomp(bcadd($own->remaining_quantity, $this->sum($own->allocations()->lockForUpdate()->get()), 8), $own->original_quantity, 8) === 0);
            $foreignQuantity = $this->sum($foreign);
            $otherQuantity = $this->sum($others);
            $this->check(bccomp(bcadd($own->remaining_quantity, $otherQuantity, 8), $foreignQuantity, 8) === 0);
            $layers = InventoryReceiptLayer::query()->whereIn('id', $foreign->pluck('inventory_receipt_layer_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($layers as $layer) {
                $this->plainLayer($layer);
                $this->check($this->samePosition($own, $layer) && $layer->inventory_serial_identity_id === null
                    && bccomp($layer->remaining_quantity, '0', 8) >= 0
                    && bccomp(bcadd($layer->remaining_quantity, $this->sum($layer->allocations()->lockForUpdate()->get()), 8), $layer->original_quantity, 8) === 0);
                $foreignIds[] = $layer->id;
            }
            foreach ($foreign as $allocation) {
                $cost = $layers->get($allocation->inventory_receipt_layer_id)->unit_cost;
                if ($cost !== null) {
                    $valueChange = bcadd($valueChange, bcmul($allocation->quantity, $cost, 8), 8);
                }
            }
            $moves = [];
            if ($others->isNotEmpty()) {
                $this->check($layers->count() === 1);
                $foreignLayer = $layers->sole();
                $foreignReceipt = InventoryTransaction::query()->lockForUpdate()->findOrFail($foreignLayer->receipt_transaction_id);
                $this->check($foreignLayer->unit_cost !== null && bccomp($foreignLayer->unit_cost, '0', 8) >= 0
                    && ! $foreignReceipt->is_reversal && $foreignReceipt->unit_cost !== null
                    && $this->samePosition($foreignLayer, $foreignReceipt)
                    && bccomp($foreignReceipt->unit_cost, $foreignLayer->unit_cost, 8) === 0
                    && bccomp($foreignReceipt->quantity_in, $foreignLayer->original_quantity, 8) === 0);
                foreach ($others as $allocation) {
                    $issue = InventoryTransaction::query()->lockForUpdate()->findOrFail($allocation->issue_transaction_id);
                    $this->check(! $issue->is_reversal && $issue->source_type === InventoryDocument::class && $this->samePosition($own, $issue)
                        && $issue->unit_cost !== null && $issue->total_cost !== null && bccomp($issue->unit_cost, $foreignLayer->unit_cost, 8) === 0
                        && bccomp(bcmul($issue->quantity_out, $issue->unit_cost, 8), $issue->total_cost, 8) === 0
                        && ! InventoryTransaction::query()->where('reversal_of_id', $issue->id)->exists());
                    $issueDocument = InventoryDocument::query()->where('company_id', $document->company_id)->lockForUpdate()->findOrFail($issue->source_id);
                    $this->check($issueDocument->status === InventoryDocument::StatusPosted
                        && in_array($issueDocument->document_type, [InventoryDocument::TypeIssue, InventoryDocument::TypeSalesDelivery], true));
                    $this->authorizeSource($issueDocument);
                    $slices = InventoryLayerAllocation::query()->with('layer')->where('issue_transaction_id', $issue->id)->orderBy('id')->lockForUpdate()->get();
                    $this->check(bccomp($this->sum($slices), $issue->quantity_out, 8) === 0);
                    foreach ($slices as $slice) {
                        $this->plainAllocation($slice);
                        $this->check((int) $slice->inventory_receipt_layer_id === (int) $own->id
                            || ($slice->layer->unit_cost !== null && bccomp($slice->layer->unit_cost, $issue->unit_cost, 8) === 0));
                        $this->check(($slice->cost_unit_snapshot === null || bccomp($slice->cost_unit_snapshot, $issue->unit_cost, 8) === 0)
                            && ($slice->cost_total_snapshot === null || bccomp($slice->cost_total_snapshot, bcmul($slice->quantity, $issue->unit_cost, 8), 8) === 0));
                    }
                    $existing = $slices->firstWhere('inventory_receipt_layer_id', $foreignLayer->id);
                    $this->check($existing === null || (($existing->cost_unit_snapshot === null) === ($allocation->cost_unit_snapshot === null)
                        && ($existing->cost_total_snapshot === null) === ($allocation->cost_total_snapshot === null)));
                    $issues[$issueDocument->id] = $issueDocument;
                    $moves[] = ['allocation_id' => (int) $allocation->id, 'issue_id' => (int) $issue->id,
                        'merge_allocation_id' => $existing === null ? null : (int) $existing->id,
                        'from_layer_id' => (int) $own->id, 'to_layer_id' => (int) $foreignLayer->id, 'quantity' => $allocation->quantity];
                    $valueChange = bcsub($valueChange, bcmul($allocation->quantity, $foreignLayer->unit_cost, 8), 8);
                }
            }
            foreach ($allocations as $allocation) {
                $this->plainAllocation($allocation);
            }
            $misplaced = bcadd($misplaced, $foreignQuantity, 8);
            $exchanged = bcadd($exchanged, $otherQuantity, 8);
            $plans[] = ['product_id' => (int) $receipt->product_id, 'receipt_id' => (int) $receipt->id, 'reversal_id' => (int) $reverse->id, 'own_layer_id' => (int) $own->id,
                'quantity' => $receipt->quantity_in, 'foreign_allocations' => $foreign->map->getAttributes()->values()->all(), 'moves' => $moves];
        }
        $allocationIds = collect($plans)->flatMap(fn (array $line) => [...array_column($line['foreign_allocations'], 'id'), ...array_column($line['moves'], 'allocation_id'), ...array_column($line['moves'], 'merge_allocation_id')])->filter()->unique()->values()->all();
        $this->assertUnsealed($document, $allocationIds, $currentProposalId);

        return ['lines' => $plans, 'misplaced_quantity' => $misplaced, 'exchanged_quantity' => $exchanged, 'known_value_change' => $valueChange,
            'foreign_layer_ids' => array_values(array_unique($foreignIds)),
            'affected_documents' => collect($issues)->map(fn ($issue) => ['id' => $issue->id, 'number' => $issue->doc_num])->values()->all(),
            'financial_proof' => $this->financialProof([(int) $document->id, ...array_keys($issues)])];
    }

    /** @return array<string, mixed> */
    public function apply(InventoryDocument $document, InventoryMovementCorrection $proposal): array
    {
        Gate::authorize('inventory.documents.correct_approve');
        $this->check(DB::transactionLevel() > 0 && $proposal->operation === 'repair_lineage' && $proposal->status === 'applying'
            && (int) $proposal->inventory_document_id === (int) $document->id && (int) $proposal->company_id === (int) $document->company_id
            && (int) $proposal->approved_by === (int) auth()->id() && (int) $proposal->prepared_by !== (int) auth()->id());
        $plan = $this->plan($document, (int) $proposal->id);
        $this->check($plan === ($proposal->source_snapshot['legacy_repair'] ?? null) && bccomp($plan['misplaced_quantity'], '0', 8) > 0);
        $changed = $deleted = $ownIds = [];
        foreach ($plan['lines'] as $line) {
            $own = InventoryReceiptLayer::query()->lockForUpdate()->findOrFail($line['own_layer_id']);
            $ownIds[] = $own->id;
            foreach ($line['foreign_allocations'] as $row) {
                $allocation = InventoryLayerAllocation::query()->lockForUpdate()->findOrFail($row['id']);
                $layer = InventoryReceiptLayer::query()->lockForUpdate()->findOrFail($allocation->inventory_receipt_layer_id);
                $layer->forceFill(['remaining_quantity' => bcadd($layer->remaining_quantity, $allocation->quantity, 8)])->save();
                $deleted[] = $allocation->id;
                $allocation->delete();
            }
            foreach ($line['moves'] as $move) {
                $allocation = InventoryLayerAllocation::query()->lockForUpdate()->findOrFail($move['allocation_id']);
                $layer = InventoryReceiptLayer::query()->lockForUpdate()->findOrFail($move['to_layer_id']);
                $existing = InventoryLayerAllocation::query()->where('inventory_receipt_layer_id', $layer->id)->where('issue_transaction_id', $allocation->issue_transaction_id)->lockForUpdate()->first();
                $this->check(($existing === null ? null : (int) $existing->id) === $move['merge_allocation_id']);
                $this->check(bccomp($layer->remaining_quantity, $allocation->quantity, 8) >= 0);
                $layer->forceFill(['remaining_quantity' => bcsub($layer->remaining_quantity, $allocation->quantity, 8)])->save();
                if ($existing !== null) {
                    $existing->forceFill(['quantity' => bcadd($existing->quantity, $allocation->quantity, 8),
                        'cost_total_snapshot' => $existing->cost_total_snapshot === null ? null : bcadd($existing->cost_total_snapshot, $allocation->cost_total_snapshot, 8)])->save();
                    $changed[] = $existing->id;
                    $deleted[] = $allocation->id;
                    $allocation->delete();
                } else {
                    $allocation->forceFill(['inventory_receipt_layer_id' => $layer->id])->save();
                    $changed[] = $allocation->id;
                }
            }
            $allocation = InventoryLayerAllocation::query()->where('issue_transaction_id', $line['reversal_id'])->where('inventory_receipt_layer_id', $own->id)->lockForUpdate()->first();
            if ($allocation === null) {
                $allocation = InventoryLayerAllocation::query()->create(['issue_transaction_id' => $line['reversal_id'], 'inventory_receipt_layer_id' => $own->id, 'quantity' => $line['quantity']]);
            } else {
                $allocation->forceFill(['quantity' => $line['quantity']])->save();
            }
            $changed[] = $allocation->id;
            $own->forceFill(['remaining_quantity' => '0.00000000'])->save();
        }
        $this->check($plan['financial_proof'] === $this->financialProof(array_column($plan['financial_proof']['documents'], 'id')));

        return ['financial_proof' => $plan['financial_proof'], 'changed_allocations' => $this->allocationRows(array_unique($changed)),
            'deleted_allocations' => array_values(array_unique($deleted)), 'own_layers' => $this->layerRows($ownIds),
            'foreign_layers' => $this->layerRows($plan['foreign_layer_ids'], true), 'misplaced_quantity' => $plan['misplaced_quantity'],
            'exchanged_quantity' => $plan['exchanged_quantity'], 'known_value_change' => $plan['known_value_change']];
    }

    public function assertApproved(InventoryMovementCorrection $proposal): void
    {
        $effect = $proposal->execution_snapshot;
        $this->check(is_array($effect) && $effect['financial_proof'] === $this->financialProof(array_column($effect['financial_proof']['documents'], 'id'))
            && $effect['changed_allocations'] === $this->allocationRows(array_column($effect['changed_allocations'], 'id'))
            && ! InventoryLayerAllocation::query()->whereIn('id', $effect['deleted_allocations'])->exists()
            && $effect['own_layers'] === $this->layerRows(array_column($effect['own_layers'], 'id'))
            && $effect['foreign_layers'] === $this->layerRows(array_column($effect['foreign_layers'], 'id'), true));
    }

    private function plainLayer(InventoryReceiptLayer $layer, bool $originalReversedReceipt = false): void
    {
        $this->check(! $layer->costCompletionBases()->exists() && ! $layer->transitionBases()->exists() && $layer->source_allocation_id === null
            && ($originalReversedReceipt || ! InventoryTransaction::query()->where('reversal_of_id', $layer->receipt_transaction_id)->exists()));
    }

    private function plainAllocation(InventoryLayerAllocation $allocation): void
    {
        $this->check(bccomp($allocation->quantity, '0', 8) > 0 && $allocation->inventory_cost_policy_transition_basis_id === null && ! $allocation->costCompletions()->exists()
            && ! InventoryReceiptLayer::query()->where('source_allocation_id', $allocation->id)->exists());
    }

    private function authorizeSource(InventoryDocument|CustomerInvoice $document): void
    {
        $company = Company::query()->findOrFail($document->company_id);
        $scope = app(OperatingScopeAccessService::class);
        abort_unless($scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $document->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $document->financial_period_id)->exists(), 404);
    }

    /** @param list<int> $ids */
    private function assertUnsealed(InventoryDocument $document, array $ids, ?int $currentProposalId): void
    {
        if ($ids === []) {
            return;
        }
        foreach ([[InventoryMovementCorrection::class, 'source_snapshot', 'allocations'], [InventoryMovementCorrection::class, 'execution_snapshot', 'changed_allocations'], [InventoryMovementCorrection::class, 'execution_snapshot', 'allocations'], [CustomerInvoiceCorrection::class, 'source_snapshot', 'layer_allocations']] as [$model, $column, $key]) {
            $query = $model::query()->where('company_id', $document->company_id)->whereIn('status', ['prepared', 'applying', 'approved']);
            if ($model === InventoryMovementCorrection::class && $currentProposalId !== null) {
                $current = InventoryMovementCorrection::query()->where('company_id', $document->company_id)->where('inventory_document_id', $document->id)
                    ->where('operation', 'repair_lineage')->whereIn('status', ['prepared', 'applying'])->findOrFail($currentProposalId);
                $query->where('id', '<>', $current->id);
            }
            $parameters = implode(',', array_fill(0, count($ids), '?'));
            if (DB::getDriverName() === 'pgsql') {
                $query->whereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements({$column}::jsonb->'{$key}') AS allocation WHERE allocation->>'id' IN ({$parameters}))", array_map(strval(...), $ids));
            } else {
                $query->whereRaw("EXISTS (SELECT 1 FROM json_each({$column}, '$.{$key}') AS allocation WHERE json_extract(allocation.value, '$.id') IN ({$parameters}))", $ids);
            }
            $this->check(! $query->exists());
        }
    }

    /** @param list<int> $ids @return array<string, mixed> */
    private function financialProof(array $ids): array
    {
        $documents = InventoryDocument::withTrashed()->whereIn('id', $ids)->orderBy('id')->get();
        $transactions = InventoryTransaction::query()->where('source_type', InventoryDocument::class)->whereIn('source_id', $ids)->orderBy('id')->get();
        $lineIds = $documents->flatMap(fn ($row) => $row->lines()->pluck('id'))->all();
        $invoices = CustomerInvoice::query()->where(fn ($q) => $q->whereIn('delivery_document_id', $ids)->orWhereHas('deliveries', fn ($r) => $r->whereIn('inventory_documents.id', $ids))
            ->orWhereHas('lines', fn ($r) => $r->whereIn('delivery_line_id', $lineIds)))->orderBy('id')->lockForUpdate()->get();
        foreach ($invoices as $invoice) {
            $this->check($documents->pluck('company_id')->unique()->all() === [$invoice->company_id]);
            $this->authorizeSource($invoice);
        }
        $journals = JournalEntry::withTrashed()->whereIn('id', $documents->pluck('journal_entry_id')->merge($invoices->pluck('journal_entry_id'))->filter())->orderBy('id')->lockForUpdate()->get();
        foreach ($journals as $journal) {
            $this->check($documents->pluck('company_id')->unique()->all() === [$journal->company_id]);
        }

        return ['documents' => $documents->map(fn ($row) => collect($row->getAttributes())->except(['status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'updated_by', 'updated_at'])->all())->all(),
            'lines' => $documents->flatMap(fn ($row) => $row->lines()->orderBy('id')->get()->map(fn ($line) => collect($line->getAttributes())->except('updated_at')->all()))->all(),
            'transactions' => $transactions->map(fn ($row) => collect($row->getAttributes())->except('updated_at')->all())->all(),
            'invoices' => $invoices->map(fn ($row) => collect($row->getAttributes())->only(['id', 'company_id', 'branch_id', 'financial_period_id', 'doc_num', 'sales_order_id', 'delivery_document_id', 'document_type', 'invoice_date', 'due_date', 'currency_id', 'exchange_rate', 'subtotal_amount', 'discount_amount', 'taxable_amount', 'tax_amount', 'total_amount', 'journal_entry_id'])->all())->all(),
            'invoice_lines' => $invoices->flatMap(fn ($row) => $row->lines()->orderBy('id')->get()->map(fn ($line) => collect($line->getAttributes())->except('updated_at')->all()))->all(),
            'journals' => $journals->map(fn ($row) => ['header' => collect($row->getAttributes())->except(['updated_at', 'reversed_entry_id'])->all(), 'lines' => $row->lines()->orderBy('line_no')->get()->map->getAttributes()->all()])->all()];
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function allocationRows(array $ids): array
    {
        return InventoryLayerAllocation::query()->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($row) => collect($row->getAttributes())->except('updated_at')->all())->all();
    }

    /** @param list<int> $ids @return list<array<string, mixed>> */
    private function layerRows(array $ids, bool $consumable = false): array
    {
        return InventoryReceiptLayer::query()->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($row) => collect($row->getAttributes())->except($consumable ? ['updated_at', 'remaining_quantity'] : ['updated_at'])->all())->all();
    }

    private function samePosition(InventoryReceiptLayer $source, InventoryReceiptLayer|InventoryTransaction $target): bool
    {
        foreach (['company_id', 'branch_id', 'branch_store_id', 'product_id', 'unit_id', 'warehouse_location_id', 'stock_status', 'batch_lot', 'manufacture_date', 'expiry_date'] as $key) {
            if ((string) $source->{$key} !== (string) $target->{$key}) {
                return false;
            }
        }

        return true;
    }

    private function sum(iterable $rows): string
    {
        $total = '0.00000000';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->quantity, 8);
        }

        return $total;
    }

    private function check(bool $condition): void
    {
        if (! $condition) {
            throw new DomainException(__('inventory_correction.legacy_inconsistent'));
        }
    }
}
