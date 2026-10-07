<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Models\SalesOrderLine;

final class ProductionReceiptCancellationService
{
    public const Kind = 'receipt_cancellation';

    public function __construct(
        private readonly ProductionRunCorrectionService $corrections,
        private readonly ProductionCorrectionDependencyService $dependencies,
        private readonly ProductionCorrectionContextService $context,
        private readonly InventoryDocumentPostingService $posting,
        private readonly ActivityLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(ProductionRun $run, string $receiptNumber): array
    {
        $base = $this->corrections->preview($run);
        $run = $base['record'];
        $receipt = $this->receipt($run, $receiptNumber);
        $snapshot = $this->snapshot($run, $receipt, $base['snapshot']);
        [$blockers, $steps] = $this->blockers($run, $receipt, $snapshot);

        return [
            'record' => $run, 'receipt' => $receipt, 'snapshot' => $snapshot,
            'fingerprint' => $this->fingerprint($snapshot), 'blockers' => $blockers, 'correction_steps' => $steps,
            'default_mode' => $base['default_mode'], 'posting_period' => $base['posting_period'],
            'corrections' => DB::table('production_run_corrections')->where('company_id', $run->company_id)
                ->where('production_run_id', $run->id)->where('corrected_output->kind', self::Kind)
                ->where('corrected_output->receipt_document_id', $receipt->id)->orderByDesc('id')->get(),
        ];
    }

    public function prepare(ProductionRun $run, string $receiptNumber, string $reason, string $fingerprint, string $postingDate, string $mode): object
    {
        Gate::authorize('production.runs.correct');
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new DomainException(__('production_run_correction.reason_required'));
        }

        return DB::transaction(function () use ($run, $receiptNumber, $reason, $fingerprint, $postingDate, $mode): object {
            $this->lockCompany();
            ProductionRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($run, $receiptNumber);
            $run = $preview['record'];
            $receipt = $preview['receipt'];
            $this->assertEligible($preview);
            $this->assertFingerprint($preview['snapshot'], $fingerprint);
            $target = $this->context->target($run, $postingDate, $mode);
            if ($postingDate < $receipt->document_date->toDateString()) {
                throw new DomainException(__('inventory.movements.messages.receipt_completion_date_before_movements'));
            }
            $output = [...$run->only(['good_base_quantity', 'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity']),
                'kind' => self::Kind, 'receipt_document_id' => (int) $receipt->id];
            $pending = DB::table('production_run_corrections')->where('company_id', $run->company_id)
                ->where('production_run_id', $run->id)->where('status', 'prepared')->lockForUpdate()->first();
            if ($pending !== null) {
                if ($pending->fingerprint === $fingerprint && $pending->reason === trim($reason)
                    && $pending->posting_date === $postingDate && $pending->correction_mode === $mode
                    && (int) $pending->posting_financial_period_id === (int) $target->id
                    && json_decode($pending->corrected_output, true, 512, JSON_THROW_ON_ERROR) === $output) {
                    return $pending;
                }
                throw new DomainException(__('production_run_correction.reject_existing'));
            }
            $id = DB::table('production_run_corrections')->insertGetId([
                'company_id' => $run->company_id, 'production_run_id' => $run->id, 'financial_period_id' => $run->financial_period_id,
                'posting_financial_period_id' => $target->id, 'posting_date' => $postingDate, 'correction_mode' => $mode,
                'reason' => trim($reason), 'fingerprint' => $fingerprint,
                'source_snapshot' => json_encode($preview['snapshot'], JSON_THROW_ON_ERROR),
                'corrected_output' => json_encode($output, JSON_THROW_ON_ERROR),
                'receipt_date_basis' => null, 'receipt_date_evidence' => null,
                'prepared_by' => auth()->id(), 'status' => 'prepared', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->log($run, $receipt, $id, 'prepared');

            return DB::table('production_run_corrections')->find($id);
        }, attempts: 3);
    }

    public function approve(ProductionRun $run, int $correctionId, ?string $receiptNumber = null): object
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($run, $correctionId, $receiptNumber): object {
            $this->lockCompany();
            ProductionRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $base = $this->corrections->preview($run);
            $run = $base['record'];
            $proposal = DB::table('production_run_corrections')->where('company_id', $run->company_id)
                ->where('production_run_id', $run->id)->where('id', $correctionId)->lockForUpdate()->firstOrFail();
            $output = json_decode($proposal->corrected_output, true, 512, JSON_THROW_ON_ERROR);
            if ($receiptNumber !== null) {
                abort_unless((int) $this->receipt($run, $receiptNumber)->id === (int) ($output['receipt_document_id'] ?? 0), 404);
            }
            if (($output['kind'] ?? null) !== self::Kind || (int) $proposal->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($proposal->status === 'approved') {
                return $proposal;
            }
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('production_run_correction.invalid_state'));
            }
            if (! hash_equals($proposal->fingerprint, hash('sha256', $proposal->source_snapshot))) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $receipt = InventoryDocument::query()->where('company_id', $run->company_id)
                ->where('production_run_id', $run->id)->whereKey($output['receipt_document_id'])->firstOrFail();
            $preview = $this->preview($run, $receipt->doc_num);
            $this->assertEligible($preview);
            $this->assertFingerprint($preview['snapshot'], $proposal->fingerprint);
            $target = $this->context->target($run, $proposal->posting_date, $proposal->correction_mode, (int) $proposal->posting_financial_period_id);
            $quantity = $this->receiptQuantity($preview['snapshot'], (int) $receipt->id);
            $line = $run->orderLine()->lockForUpdate()->firstOrFail();
            $salesLine = $line->sales_order_line_id === null ? null : SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
            if (bccomp((string) $line->received_base_quantity, $quantity, 8) < 0
                || ($salesLine !== null && bccomp((string) $salesLine->produced_base_quantity, $quantity, 8) < 0)) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            DB::table('production_run_corrections')->where('id', $correctionId)->update(['status' => 'applying', 'updated_at' => now()]);
            $this->releaseReservations($run, $receipt, $preview['snapshot'], $proposal->reason);
            $this->posting->reverseForProductionCorrection($receipt, $run, $correctionId, $proposal->reason);
            $line->decrement('received_base_quantity', $quantity);
            if ($salesLine !== null) {
                $salesQuantity = bcdiv($quantity, (string) $salesLine->conversion_factor, 8);
                if (bccomp((string) $salesLine->produced_quantity, $salesQuantity, 8) < 0) {
                    throw new DomainException(__('production_run_correction.lineage_invalid'));
                }
                $salesLine->decrement('produced_base_quantity', $quantity);
                $salesLine->decrement('produced_quantity', $salesQuantity);
            }
            $run->update(['received_base_quantity' => bcsub((string) $run->received_base_quantity, $quantity, 8),
                'status' => ProductionRun::StatusRunning, 'active_correction_id' => $correctionId,
                'correction_document_date' => $proposal->posting_date, 'correction_posting_financial_period_id' => $target->id,
                'updated_by' => auth()->id()]);
            $order = $run->order()->lockForUpdate()->firstOrFail();
            $order->update(['status' => $order->lines()->where('received_base_quantity', '>', 0)->exists()
                ? ProductionOrder::StatusPartiallyCompleted : ProductionOrder::StatusInProgress, 'updated_by' => auth()->id()]);
            $this->reopenReceiptStage($run);
            DB::table('production_run_corrections')->where('id', $correctionId)->update([
                'status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->log($run, $receipt, $correctionId, 'approved');

            return DB::table('production_run_corrections')->find($correctionId);
        }, attempts: 3);
    }

    public function registerWarehouseRecovery(ProductionRun $run, InventoryDocument $receipt, InventoryMovementCorrection $proposal): void
    {
        app(ProductionWarehouseReceiptCorrectionService::class)->execution((int) $proposal->id, (int) $receipt->id);
        if ($run->status !== ProductionRun::StatusCompleted || (int) $run->company_id !== (int) $receipt->company_id
            || (int) $run->branch_id !== (int) $receipt->branch_id || ! $receipt->lines()->where('production_run_id', $run->id)->exists()) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $mode = (int) $run->financial_period_id === (int) $proposal->posting_financial_period_id
            ? ProductionCorrectionContextService::OriginalPeriod : ProductionCorrectionContextService::LaterPeriod;
        $this->context->target($run, $proposal->posting_date->toDateString(), $mode, (int) $proposal->posting_financial_period_id);
        $snapshot = ['run' => $run->getRawOriginal(), 'receipt_document_id' => $receipt->id,
            'document_lines' => $receipt->lines()->where('production_run_id', $run->id)->orderBy('id')->get()->map->getRawOriginal()->all(),
            'documents' => InventoryDocument::query()->where('company_id', $run->company_id)
                ->where('document_type', InventoryDocument::TypeProductionReceipt)
                ->where(fn ($query) => $query->where('production_run_id', $run->id)->orWhereHas('lines', fn ($line) => $line->where('production_run_id', $run->id)))
                ->orderBy('id')->get()->map->getRawOriginal()->all(), 'warehouse_correction_id' => $proposal->id];
        $snapshotJson = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $id = DB::table('production_run_corrections')->insertGetId([
            'company_id' => $run->company_id, 'production_run_id' => $run->id, 'financial_period_id' => $run->financial_period_id,
            'posting_financial_period_id' => $proposal->posting_financial_period_id, 'posting_date' => $proposal->posting_date->toDateString(),
            'correction_mode' => $mode, 'reason' => $proposal->reason, 'fingerprint' => hash('sha256', $snapshotJson),
            'source_snapshot' => $snapshotJson, 'corrected_output' => json_encode([
                ...$run->only(['good_base_quantity', 'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity']),
                'kind' => self::Kind, 'receipt_document_id' => $receipt->id, 'warehouse_correction_id' => $proposal->id,
            ], JSON_THROW_ON_ERROR), 'prepared_by' => $proposal->prepared_by, 'approved_by' => $proposal->approved_by,
            'approved_at' => $proposal->approved_at, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $run->update(['status' => ProductionRun::StatusRunning, 'active_correction_id' => $id,
            'correction_document_date' => $proposal->posting_date->toDateString(),
            'correction_posting_financial_period_id' => $proposal->posting_financial_period_id, 'updated_by' => auth()->id()]);
        $this->reopenReceiptStage($run);
        $this->log($run, $receipt, $id, 'warehouse_recovery_registered');
    }

    private function reopenReceiptStage(ProductionRun $run): void
    {
        if (! app(ProductionOutputEvidenceService::class)->isFactoryWorkflow($run->fresh())) {
            return;
        }
        $roles = collect($run->material_evidence_policy['stage_roles'])->keyBy('stage_public_id');
        foreach ($run->order->orderStageSnapshots()->where('is_required', true)->lockForUpdate()->get() as $stage) {
            if (($roles[$stage->public_id]['role'] ?? null) !== 'receipt') {
                continue;
            }
            $previous = $stage->status;
            $stage->update(['status' => ProductionOrderStageSnapshot::StatusInProgress]);
            app(ProductionRoutingService::class)->recordStageEvent($stage, self::Kind, $previous, $stage->status, (int) $run->id);
        }
    }

    public function isReceiptOnlyRecovery(ProductionRun $run): bool
    {
        return in_array($run->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld], true) && $run->active_correction_id !== null
            && DB::table('production_run_corrections')->where('id', $run->active_correction_id)
                ->where('company_id', $run->company_id)->where('production_run_id', $run->id)
                ->where('status', 'approved')->where('corrected_output->kind', self::Kind)->exists();
    }

    public function assertManufacturingAllowed(ProductionRun $run): void
    {
        if ($this->isReceiptOnlyRecovery($run)) {
            throw new DomainException(__('production_receipt_cancellation.receipt_only_recovery'));
        }
    }

    /** @return array{basis: list<array<string, mixed>>, received: string} */
    public function replacementBasis(ProductionRun $run): array
    {
        $proposal = DB::table('production_run_corrections')->where('id', $run->active_correction_id)
            ->where('company_id', $run->company_id)->where('production_run_id', $run->id)->where('status', 'approved')->firstOrFail();
        $snapshot = json_decode($proposal->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals($proposal->fingerprint, hash('sha256', $proposal->source_snapshot))) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $basis = collect($snapshot['document_lines'])->where('inventory_document_id', $snapshot['receipt_document_id'])
            ->map(fn (array $line): array => ['quantity' => $line['quantity'], 'batch_lot' => $line['batch_lot'],
                'manufacture_date' => $line['manufacture_date'], 'expiry_date' => $line['expiry_date']])->values()->all();
        $received = DB::table('inventory_document_lines as line')->join('inventory_documents as document', 'document.id', '=', 'line.inventory_document_id')
            ->where('document.company_id', $run->company_id)->where(fn ($query) => $query->where('document.production_run_id', $run->id)->orWhere('line.production_run_id', $run->id))
            ->where('document.document_type', InventoryDocument::TypeProductionReceipt)->where('document.status', InventoryDocument::StatusPosted)
            ->whereNotIn('document.id', array_column($snapshot['documents'], 'id'))->whereNull('document.deleted_at')->whereNull('line.deleted_at')->sum('line.quantity');

        return ['basis' => $basis, 'received' => bcadd((string) $received, '0', 8)];
    }

    public function assertSelectedReceipt(object $proposal, InventoryDocument $document): void
    {
        $output = json_decode($proposal->corrected_output, true, 512, JSON_THROW_ON_ERROR);
        if (($output['kind'] ?? null) === self::Kind && ($document->document_type !== InventoryDocument::TypeProductionReceipt
            || (int) ($output['receipt_document_id'] ?? 0) !== (int) $document->id)) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
    }

    private function lockCompany(): void
    {
        Company::query()->whereKey(app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->firstOrFail();
    }

    private function receipt(ProductionRun $run, string $number): InventoryDocument
    {
        return InventoryDocument::query()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)
            ->where('production_run_id', $run->id)->where('source_document_type', ProductionRun::class)
            ->where('source_document_id', $run->id)->where('document_type', InventoryDocument::TypeProductionReceipt)
            ->where('doc_num', $number)->firstOrFail();
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function snapshot(ProductionRun $run, InventoryDocument $receipt, array $snapshot): array
    {
        $snapshot['receipt_document_id'] = (int) $receipt->id;
        $snapshot['order'] = $run->order->getAttributes();
        $snapshot['order_line'] = $run->orderLine->getAttributes();
        $snapshot['sales_line'] = $run->orderLine->salesOrderLine?->getAttributes();
        foreach (['production_quality_output_batches', 'production_shift_entries'] as $table) {
            $snapshot[$table] = DB::table($table)->where('company_id', $run->company_id)->where('production_run_id', $run->id)->orderBy('id')->get();
        }
        $snapshot['quality_receipt_allocations'] = DB::table('production_quality_receipt_allocations')
            ->whereIn('inventory_document_id', $snapshot['documents']->pluck('id'))->orderBy('id')->get();
        $snapshot['receipt_dependencies'] = $this->dependencies->snapshot($run, collect([(object) $receipt->getAttributes()]),
            $snapshot['transactions']->where('source_id', $receipt->id)->values());

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot @return array{list<string>, list<array<string, mixed>>} */
    private function blockers(ProductionRun $run, InventoryDocument $receipt, array $snapshot): array
    {
        $blockers = [];
        if ($run->material_accounting_mode !== ProductionOutputEvidenceService::Mode || $run->status !== ProductionRun::StatusCompleted) {
            $blockers[] = __('production_receipt_cancellation.completed_evidence_required');
        }
        if ($receipt->status !== InventoryDocument::StatusPosted) {
            $blockers[] = __('production_receipt_cancellation.already_cancelled');
        }
        $steps = $this->dependencies->steps(['inventory' => $snapshot['receipt_dependencies']['inventory'], 'payroll' => []]);
        if ($steps !== []) {
            $blockers[] = __('production_run_correction.dependencies_required');
        }
        $posted = $snapshot['documents']->where('status', InventoryDocument::StatusPosted)->where('document_type', InventoryDocument::TypeProductionReceipt)->pluck('id');
        $received = $snapshot['document_lines']->whereIn('inventory_document_id', $posted)
            ->reduce(fn (string $sum, object $line): string => bcadd($sum, (string) $line->quantity, 8), '0');
        if (bccomp($received, (string) $run->received_base_quantity, 8) !== 0
            || bccomp((string) $run->received_base_quantity, (string) $run->good_base_quantity, 8) !== 0
            || bccomp($this->receiptQuantity($snapshot, (int) $receipt->id), '0', 8) <= 0) {
            $blockers[] = __('production_run_correction.lineage_invalid');
        }
        foreach ($snapshot['transactions']->where('source_id', $receipt->id)->where('is_reversal', false) as $transaction) {
            if ($transaction->total_cost === null || bccomp((string) $transaction->quantity_in, '0', 8) <= 0) {
                $blockers[] = __('production_run_correction.lineage_invalid');

                continue;
            }
            $lineage = app(InventoryLayerService::class)->receiptLineageTransactionIds((int) $transaction->id);
            $remaining = DB::table('inventory_receipt_layers')->whereIn('receipt_transaction_id', $lineage)
                ->where('branch_store_id', $transaction->branch_store_id)->where('product_id', $transaction->product_id)
                ->where('stock_status', $transaction->stock_status)->where('batch_lot', $transaction->batch_lot)
                ->where('warehouse_location_id', $transaction->warehouse_location_id)->sum('remaining_quantity');
            if (bccomp((string) $remaining, (string) $transaction->quantity_in, 8) < 0) {
                $blockers[] = __('production_receipt_cancellation.stock_used');
            }
        }
        if ($run->production_order_stage_snapshot_id !== null && ProductionRun::query()
            ->where('production_order_line_id', $run->production_order_line_id)->whereKeyNot($run->id)
            ->where('status', '<>', ProductionRun::StatusCancelled)
            ->whereHas('stageSnapshot', fn ($query) => $query->where('sequence', '>', $run->stageSnapshot->sequence))->exists()) {
            $blockers[] = __('production_receipt_cancellation.downstream_stage');
        }
        try {
            $this->reservations($run, $receipt, $snapshot);
        } catch (DomainException $exception) {
            $blockers[] = $exception->getMessage();
        }
        $active = FinancialPeriod::query()->where('company_id', $run->company_id)
            ->find(request()->session()->get(OperatingContextService::FinancialPeriodIdKey));
        if ($active === null || $active->is_closed) {
            $blockers[] = __('production_run_correction.open_period_required');
        }

        return [array_values(array_unique($blockers)), $steps];
    }

    /** @param array<string, mixed> $snapshot */
    private function receiptQuantity(array $snapshot, int $documentId): string
    {
        return $snapshot['document_lines']->where('inventory_document_id', $documentId)
            ->reduce(fn (string $sum, object $line): string => bcadd($sum, (string) $line->quantity, 8), '0');
    }

    /** @param array<string, mixed> $snapshot @return Collection<int, object> */
    private function reservations(ProductionRun $run, InventoryDocument $receipt, array $snapshot): Collection
    {
        $lines = $snapshot['document_lines']->where('inventory_document_id', $receipt->id);
        $documents = $snapshot['documents']->keyBy('id');
        $siblings = $snapshot['documents']->where('status', InventoryDocument::StatusPosted)
            ->where('document_type', InventoryDocument::TypeProductionReceipt)->where('id', '<>', $receipt->id)->pluck('id');
        $matched = collect();
        foreach ($snapshot['reservations']->where('status', InventoryReservation::StatusActive)->whereNotNull('sales_order_line_id') as $reservation) {
            $matches = fn (object $line): bool => (int) $line->product_id === (int) $reservation->product_id
                && (int) $documents[$line->inventory_document_id]->branch_store_id === (int) $reservation->branch_store_id
                && $line->batch_lot === $reservation->batch_lot
                && (int) $line->destination_warehouse_location_id === (int) $reservation->warehouse_location_id;
            if (! $lines->contains($matches)) {
                continue;
            }
            if ((int) $reservation->sales_order_line_id !== (int) $run->orderLine->sales_order_line_id
                || bccomp((string) $reservation->consumed_quantity, '0', 8) > 0
                || $snapshot['document_lines']->whereIn('inventory_document_id', $siblings)->contains($matches)) {
                throw new DomainException(__('production_receipt_cancellation.reservation_ambiguous'));
            }
            $matched->push($reservation);
        }
        $total = $matched->reduce(fn (string $sum, object $reservation): string => bcadd($sum,
            bcsub(bcsub((string) $reservation->quantity, (string) $reservation->consumed_quantity, 8), (string) $reservation->released_quantity, 8), 8), '0');
        if (bccomp($total, $this->receiptQuantity($snapshot, (int) $receipt->id), 8) > 0) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }

        return $matched;
    }

    /** @param array<string, mixed> $snapshot */
    private function releaseReservations(ProductionRun $run, InventoryDocument $receipt, array $snapshot, string $reason): void
    {
        foreach ($this->reservations($run, $receipt, $snapshot) as $row) {
            $reservation = InventoryReservation::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $line = SalesOrderLine::query()->whereKey($reservation->sales_order_line_id)->lockForUpdate()->firstOrFail();
            $remaining = (string) $reservation->remaining_quantity;
            $quantity = bcdiv($remaining, (string) $reservation->conversion_factor, 8);
            if (bccomp((string) $line->reserved_base_quantity, $remaining, 8) < 0 || bccomp((string) $line->reserved_quantity, $quantity, 8) < 0) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            $line->decrement('reserved_base_quantity', $remaining);
            $line->decrement('reserved_quantity', $quantity);
            $reservation->update(['released_quantity' => bcadd((string) $reservation->released_quantity, $remaining, 8),
                'status' => InventoryReservation::StatusReleased, 'released_by' => auth()->id(), 'released_at' => now(),
                'release_reason' => $reason, 'updated_by' => auth()->id()]);
        }
    }

    /** @param array<string, mixed> $preview */
    private function assertEligible(array $preview): void
    {
        if ($preview['blockers'] !== []) {
            throw new DomainException(implode(' ', $preview['blockers']));
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $snapshot */
    private function assertFingerprint(array $snapshot, string $fingerprint): void
    {
        if (! hash_equals($this->fingerprint($snapshot), $fingerprint)) {
            throw new DomainException(__('production_run_correction.stale'));
        }
    }

    private function log(ProductionRun $run, InventoryDocument $receipt, int $correctionId, string $action): void
    {
        $this->audit->log(request(), 'production', 'production.receipt_cancellation.'.$action, 'success', [
            'subject' => $receipt, 'causer' => auth()->user(), 'company_id' => $run->company_id,
            'production_run_id' => $run->id, 'correction_id' => $correctionId,
        ]);
    }
}
