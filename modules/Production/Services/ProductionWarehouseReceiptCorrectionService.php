<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryAccountingPostingService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Models\SalesOrderLine;

final class ProductionWarehouseReceiptCorrectionService
{
    public const Operation = 'production_unreceive';

    public function __construct(private readonly ProductionHandoverService $handovers) {}

    /** @return array<string, mixed> */
    public function preview(InventoryDocument $document): array
    {
        abort_unless(Gate::any(['inventory.production_receipts.correct_prepare', 'inventory.production_receipts.correct_approve']), 403);
        $receipt = $this->handovers->warehouseReceipt($document);
        $snapshot = $this->snapshot($receipt);
        $steps = $receipt->status === InventoryDocument::StatusPosted
            ? app(InventoryMovementCorrectionService::class)->dependencySteps($receipt) : [];
        $blockers = [];
        if ($receipt->status !== InventoryDocument::StatusPosted || $receipt->trashed()) {
            $blockers[] = __('production_handover.invalid_state');
        }
        if ($steps !== []) {
            $blockers[] = __('production_run_correction.dependencies_required');
        }
        foreach ($snapshot['runs'] as $run) {
            if (! in_array($run['status'], [ProductionRun::StatusRunning, ProductionRun::StatusCompleted], true)
                || (int) $run['company_id'] !== (int) $receipt->company_id || (int) $run['branch_id'] !== (int) $receipt->branch_id) {
                $blockers[] = __('production_run_correction.lineage_invalid');
            }
        }
        if ($snapshot['reservations'] !== []) {
            $blockers[] = __('production_handover.reservations_first');
        }
        if ($snapshot['pending_run_corrections'] !== []) {
            $blockers[] = __('production_run_correction.reject_existing');
        }
        try {
            $this->handovers->context($receipt, now()->toDateString());
            if ($receipt->status === InventoryDocument::StatusPosted) {
                app(InventoryAccountingPostingService::class)->assertManualCorrectionAccounting($receipt);
            }
        } catch (DomainException $exception) {
            $blockers[] = $exception->getMessage();
        }

        return ['record' => $receipt, 'snapshot' => $snapshot, 'fingerprint' => $this->digest($snapshot),
            'steps' => $steps, 'blockers' => array_values(array_unique($blockers)),
            'corrections' => InventoryMovementCorrection::query()->where('company_id', $receipt->company_id)
                ->where('inventory_document_id', $receipt->id)->where('operation', self::Operation)->orderByDesc('id')->get()];
    }

    public function prepare(InventoryDocument $document, string $reason, string $fingerprint, string $date): InventoryMovementCorrection
    {
        Gate::authorize('inventory.production_receipts.correct_prepare');

        return DB::transaction(function () use ($document, $reason, $fingerprint, $date): InventoryMovementCorrection {
            Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
            $receipt = $this->handovers->warehouseReceipt($document, lock: true);
            $preview = $this->preview($receipt);
            $this->assertPreview($preview, $fingerprint);
            $target = $this->handovers->context($receipt, $date);
            if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000 || $date < $receipt->document_date->toDateString() || $date > now()->toDateString()) {
                throw new DomainException(__('production_handover.date_invalid'));
            }
            $proposal = new InventoryMovementCorrection(['company_id' => $receipt->company_id, 'branch_id' => $receipt->branch_id,
                'inventory_document_id' => $receipt->id, 'source_financial_period_id' => $receipt->financial_period_id,
                'posting_financial_period_id' => $target['financial_period_id'], 'posting_date' => $date, 'operation' => self::Operation,
                'status' => 'prepared', 'reason' => trim($reason), 'replacement_payload' => [], 'source_snapshot' => $preview['snapshot'],
                'source_fingerprint' => $fingerprint, 'prepared_by' => auth()->id()]);
            $proposal->proposal_fingerprint = $this->digest($this->proposalData($proposal));
            $existing = InventoryMovementCorrection::query()->where('company_id', $receipt->company_id)
                ->where('inventory_document_id', $receipt->id)->where('status', 'prepared')->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->proposal_fingerprint, $proposal->proposal_fingerprint)) {
                    throw new DomainException(__('production_run_correction.reject_existing'));
                }

                return $existing;
            }
            $proposal->save();
            $this->audit($receipt, $proposal, 'prepared');

            return $proposal;
        }, 3);
    }

    public function approve(InventoryDocument $document, int $id): InventoryMovementCorrection
    {
        Gate::authorize('inventory.production_receipts.correct_approve');

        return DB::transaction(function () use ($document, $id): InventoryMovementCorrection {
            Company::query()->whereKey($document->company_id)->lockForUpdate()->firstOrFail();
            $receipt = $this->handovers->warehouseReceipt($document, lock: true);
            $proposal = $this->proposal($receipt, $id);
            if ((int) $proposal->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($proposal->status === 'approved') {
                if ($receipt->status !== InventoryDocument::StatusReversed || $proposal->approved_by === null
                    || $proposal->execution_snapshot === null || ! hash_equals((string) $proposal->execution_fingerprint, $this->digest($proposal->execution_snapshot))) {
                    throw new DomainException(__('production_run_correction.lineage_invalid'));
                }

                return $proposal;
            }
            if ($proposal->status !== 'prepared' || ! hash_equals($proposal->proposal_fingerprint, $this->digest($this->proposalData($proposal)))
                || ! hash_equals($proposal->source_fingerprint, $this->digest($proposal->source_snapshot))) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $this->assertPreview($this->preview($receipt), $proposal->source_fingerprint);
            $context = $this->handovers->context($receipt, $proposal->posting_date->toDateString());
            if ($context['financial_period_id'] !== (int) $proposal->posting_financial_period_id) {
                throw new DomainException(__('production_run_correction.target_changed'));
            }
            $runs = ProductionRun::query()->where('company_id', $receipt->company_id)->whereIn('id', array_column($proposal->source_snapshot['runs'], 'id'))->orderBy('id')->lockForUpdate()->get();
            $proposal->update(['status' => 'applying', 'approved_by' => auth()->id(), 'approved_at' => now(), 'approval_fingerprint' => $proposal->proposal_fingerprint]);
            app(InventoryDocumentPostingService::class)->reverseForProductionWarehouseCorrection($receipt, $proposal->id);
            foreach ($runs as $run) {
                $quantity = bcadd((string) $receipt->lines()->where('production_run_id', $run->id)->sum('quantity'), '0', 8);
                $line = $run->orderLine()->lockForUpdate()->firstOrFail();
                if (bccomp((string) $run->received_base_quantity, $quantity, 8) < 0 || bccomp((string) $line->received_base_quantity, $quantity, 8) < 0) {
                    throw new DomainException(__('production_run_correction.lineage_invalid'));
                }
                $line->decrement('received_base_quantity', $quantity);
                $salesLine = $line->sales_order_line_id === null ? null : SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
                if ($salesLine !== null) {
                    $salesQuantity = bcdiv($quantity, (string) $salesLine->conversion_factor, 8);
                    if (bccomp((string) $salesLine->produced_base_quantity, $quantity, 8) < 0 || bccomp((string) $salesLine->produced_quantity, $salesQuantity, 8) < 0) {
                        throw new DomainException(__('production_run_correction.lineage_invalid'));
                    }
                    $salesLine->decrement('produced_base_quantity', $quantity);
                    $salesLine->decrement('produced_quantity', $salesQuantity);
                }
                if ($run->status === ProductionRun::StatusCompleted) {
                    app(ProductionReceiptCancellationService::class)->registerWarehouseRecovery($run, $receipt, $proposal);
                }
                $run->decrement('received_base_quantity', $quantity);
                $order = $run->order()->lockForUpdate()->firstOrFail();
                $order->update(['status' => $order->lines()->where('received_base_quantity', '>', 0)->exists()
                    ? ProductionOrder::StatusPartiallyCompleted : ProductionOrder::StatusInProgress, 'updated_by' => auth()->id()]);
            }
            $execution = ['receipt' => $receipt->fresh()->getRawOriginal(), 'run_receipts' => $runs->map(fn (ProductionRun $run): array => ['run_id' => $run->id, 'received_base_quantity' => $run->fresh()->received_base_quantity])->all()];
            $proposal->update(['status' => 'approved', 'execution_snapshot' => $execution, 'execution_fingerprint' => $this->digest($execution)]);
            $this->audit($receipt, $proposal, 'approved');

            return $proposal;
        }, 3);
    }

    public function hasVerifiedReversal(InventoryDocument $receipt): bool
    {
        if ($receipt->status !== InventoryDocument::StatusReversed || $receipt->trashed()) {
            return false;
        }
        $proposal = InventoryMovementCorrection::query()->where('company_id', $receipt->company_id)
            ->where('inventory_document_id', $receipt->id)->where('operation', self::Operation)->where('status', 'approved')->latest('id')->first();
        if ($proposal === null || $proposal->execution_snapshot === null || $proposal->approved_at === null
            || (int) $proposal->prepared_by === (int) $proposal->approved_by
            || ! hash_equals((string) $proposal->execution_fingerprint, $this->digest($proposal->execution_snapshot))
            || ! hash_equals($proposal->source_fingerprint, $this->digest($proposal->source_snapshot))
            || ($proposal->execution_snapshot['receipt'] ?? null) !== $receipt->getRawOriginal()) {
            return false;
        }
        if (! hash_equals((string) $proposal->approval_fingerprint, $proposal->proposal_fingerprint)
            || ! hash_equals($proposal->proposal_fingerprint, $this->digest($this->proposalData($proposal)))) {
            return false;
        }
        $originals = $receipt->transactions()->where('is_reversal', false)->orderBy('id')->get();
        if ($originals->isEmpty() || ($proposal->source_snapshot['transactions'] ?? []) !== $originals->map->getRawOriginal()->all()) {
            return false;
        }
        foreach ($originals as $original) {
            $inverses = $receipt->transactions()->where('is_reversal', true)->where('reversal_of_id', $original->id)->get();
            if ($inverses->count() !== 1) {
                return false;
            }
            $inverse = $inverses->sole();
            foreach (['company_id', 'branch_id', 'branch_store_id', 'branch_hall_id', 'warehouse_location_id', 'stock_status',
                'batch_lot', 'manufacture_date', 'expiry_date', 'product_id', 'unit_id', 'source_type', 'source_id', 'source_doc_num',
                'source_line_type', 'source_line_id', 'supplier_id', 'customer_id', 'production_order_id', 'production_run_id',
                'inventory_reservation_id', 'cost_policy_id', 'cost_method', 'inventory_serial_identity_id'] as $field) {
                if ($original->getRawOriginal($field) !== $inverse->getRawOriginal($field)) {
                    return false;
                }
            }
            $quantity = bccomp((string) $original->quantity_in, '0', 8) > 0 ? (string) $original->quantity_in : (string) $original->quantity_out;
            $cost = $original->completedTotalCost();
            if (bccomp((string) $original->quantity_in, (string) $inverse->quantity_out, 8) !== 0
                || bccomp((string) $original->quantity_out, (string) $inverse->quantity_in, 8) !== 0
                || (int) $inverse->financial_period_id !== (int) $proposal->posting_financial_period_id
                || $inverse->transaction_date->toDateString() !== $proposal->posting_date->toDateString()
                || ($cost === null) !== ($inverse->total_cost === null)
                || ($cost !== null && (bccomp($cost, (string) $inverse->total_cost, 8) !== 0
                    || bccomp(bcdiv($cost, $quantity, 8), (string) $inverse->unit_cost, 8) !== 0))) {
                return false;
            }
        }
        try {
            app(InventoryAccountingPostingService::class)->assertCorrectionCompletionReversal($receipt);
            if ($receipt->journal_entry_id === null || $receipt->reversalJournalEntry === null || $receipt->journalEntry === null) {
                return false;
            }
            app(JournalEntryService::class)->assertPostedReversal($receipt->journalEntry, $receipt->reversalJournalEntry);
            $journal = $receipt->journalEntry->getRawOriginal();
            $original = $proposal->source_snapshot['journal'] ?? [];
            unset($journal['updated_at'], $journal['reversed_entry_id'], $original['updated_at'], $original['reversed_entry_id']);

            return $journal === $original && ($proposal->source_snapshot['journal_lines'] ?? []) === $receipt->journalEntry->lines->sortBy('id')->map->getRawOriginal()->values()->all();
        } catch (DomainException) {
            return false;
        }
    }

    public function execution(int $id, int $documentId): InventoryMovementCorrection
    {
        Gate::authorize('inventory.production_receipts.correct_approve');
        $proposal = InventoryMovementCorrection::query()->where('id', $id)->where('inventory_document_id', $documentId)
            ->where('operation', self::Operation)->where('status', 'applying')->first();
        if (DB::transactionLevel() < 1 || $proposal === null || (int) $proposal->prepared_by === (int) auth()->id()
            || (int) $proposal->approved_by !== (int) auth()->id() || $proposal->approved_at === null
            || ! hash_equals((string) $proposal->approval_fingerprint, $proposal->proposal_fingerprint)
            || ! hash_equals($proposal->proposal_fingerprint, $this->digest($this->proposalData($proposal)))
            || ! hash_equals($proposal->source_fingerprint, $this->digest($proposal->source_snapshot))) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $receipt = $this->handovers->warehouseReceipt(InventoryDocument::query()->findOrFail($documentId));
        if ((int) $proposal->company_id !== (int) $receipt->company_id || (int) $proposal->branch_id !== (int) $receipt->branch_id
            || (int) $proposal->source_financial_period_id !== (int) $receipt->financial_period_id
            || $proposal->replacement_payload !== [] || $receipt->lines->isEmpty()
            || $receipt->lines->contains(fn ($line) => $line->production_run_id === null)) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $context = $this->handovers->context($receipt, $proposal->posting_date->toDateString());
        if ($context['financial_period_id'] !== (int) $proposal->posting_financial_period_id) {
            throw new DomainException(__('production_run_correction.target_changed'));
        }

        return $proposal;
    }

    /** @return array<string, mixed> */
    private function snapshot(InventoryDocument $receipt): array
    {
        $runIds = $receipt->lines->pluck('production_run_id')->unique()->all();
        $query = fn (string $table) => DB::table($table)->where('company_id', $receipt->company_id)->whereIn('production_run_id', $runIds)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $transactions = $receipt->transactions()->where('is_reversal', false)->orderBy('id')->get();
        $layers = DB::table('inventory_receipt_layers')->whereIn('receipt_transaction_id', $transactions->modelKeys())->orderBy('id')->get();
        $handover = $this->handovers->handover(InventoryDocument::query()->findOrFail($receipt->source_document_id));
        $runs = ProductionRun::query()->where('company_id', $receipt->company_id)->where('branch_id', $receipt->branch_id)
            ->whereIn('id', $runIds)->orderBy('id')->get();
        if ($runs->count() !== count($runIds)) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $requirements = DB::table('production_material_requirements as requirement')
            ->join('production_runs as run', 'run.id', '=', 'requirement.production_run_id')
            ->where('run.company_id', $receipt->company_id)->where('run.branch_id', $receipt->branch_id)
            ->whereIn('run.id', $runIds)->orderBy('requirement.id')->get(['requirement.*'])
            ->map(fn ($row): array => (array) $row)->all();

        return ['receipt' => $receipt->getRawOriginal(), 'lines' => $receipt->lines->sortBy('id')->map->getRawOriginal()->values()->all(),
            'handover' => $handover->getRawOriginal(), 'handover_lines' => $handover->lines->sortBy('id')->map->getRawOriginal()->values()->all(),
            'transactions' => $transactions->map->getRawOriginal()->all(), 'layers' => $layers->map(fn ($row): array => (array) $row)->all(),
            'allocations' => DB::table('inventory_layer_allocations')->whereIn('inventory_receipt_layer_id', $layers->pluck('id'))->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'journal' => $receipt->journalEntry?->getRawOriginal(), 'journal_lines' => $receipt->journalEntry?->lines->sortBy('id')->map->getRawOriginal()->values()->all(),
            'runs' => $runs->map->getRawOriginal()->all(), 'requirements' => $requirements,
            'progress' => DB::table('production_progress_entries')->whereIn('production_run_id', $runIds)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'quality' => $query('quality_inspections'), 'shifts' => $query('production_shift_entries'),
            'reservations' => InventoryReservation::query()->where('company_id', $receipt->company_id)->where('branch_id', $receipt->branch_id)
                ->where('status', InventoryReservation::StatusActive)->whereIn('product_id', $receipt->lines->pluck('product_id'))
                ->where('branch_store_id', $receipt->branch_store_id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'pending_run_corrections' => DB::table('production_run_corrections')->where('company_id', $receipt->company_id)
                ->whereIn('production_run_id', $runIds)->whereIn('status', ['prepared', 'applying'])->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all()];
    }

    private function proposal(InventoryDocument $receipt, int $id): InventoryMovementCorrection
    {
        return InventoryMovementCorrection::query()->where('company_id', $receipt->company_id)->where('inventory_document_id', $receipt->id)
            ->where('operation', self::Operation)->lockForUpdate()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function proposalData(InventoryMovementCorrection $proposal): array
    {
        return $proposal->only(['company_id', 'branch_id', 'inventory_document_id', 'source_financial_period_id',
            'posting_financial_period_id', 'operation', 'reason', 'replacement_payload', 'source_fingerprint', 'prepared_by'])
            + ['posting_date' => $proposal->posting_date->toDateString()];
    }

    /** @param array<string, mixed> $preview */
    private function assertPreview(array $preview, string $fingerprint): void
    {
        if ($preview['blockers'] !== []) {
            throw new DomainException(implode(' ', $preview['blockers']));
        }
        if (! hash_equals($preview['fingerprint'], $fingerprint)) {
            throw new DomainException(__('production_run_correction.stale'));
        }
    }

    /** @param array<string, mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function audit(InventoryDocument $receipt, InventoryMovementCorrection $proposal, string $action): void
    {
        app(ActivityLogger::class)->log(request(), 'production', 'production.warehouse_receipt_correction.'.$action, 'success', [
            'subject' => $receipt, 'company_id' => $receipt->company_id, 'properties_only' => true,
            'properties' => ['correction_id' => $proposal->id, 'source_fingerprint' => $proposal->source_fingerprint,
                'proposal_fingerprint' => $proposal->proposal_fingerprint],
        ]);
    }
}
