<?php

namespace Modules\Production\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Production\Models\ProductionMaterialRequirement;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionOrderStageSnapshot;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Models\SalesOrderLine;

final class ProductionRunCorrectionService
{
    private const OutputFields = ['good_base_quantity', 'rejected_base_quantity', 'rework_base_quantity', 'scrap_base_quantity'];

    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly InventoryDocumentPostingService $posting,
        private readonly ActivityLogger $audit,
        private readonly ProductionCorrectionDependencyService $dependencies,
    ) {}

    /** @return array<string, mixed> */
    public function preview(ProductionRun $run): array
    {
        abort_unless(auth()->user()?->canAny(['production.runs.correct', 'production.runs.correct_approve']), 403);
        $run = $this->scopedRun($run);
        $snapshot = $this->snapshot($run);

        return ['record' => $run->load('product', 'requirements.product'), 'snapshot' => $snapshot,
            'default_mode' => (int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey) === (int) $run->financial_period_id
                ? ProductionCorrectionContextService::OriginalPeriod : ProductionCorrectionContextService::LaterPeriod,
            'posting_period' => FinancialPeriod::query()->where('company_id', $run->company_id)->find(request()->session()->get(OperatingContextService::FinancialPeriodIdKey)),
            'fingerprint' => $this->fingerprint($snapshot),
            'impact' => $this->impact($snapshot),
            'correction_steps' => $this->dependencies->steps($snapshot['dependency_snapshot']),
            'corrections' => DB::table('production_run_corrections')->where('production_run_id', $run->getKey())->orderByDesc('id')->get()];
    }

    /** @param array<string, mixed> $output */
    public function propose(ProductionRun $run, array $output, string $reason, string $fingerprint, string $postingDate, array $receiptDates = [], ?string $dateEvidence = null, string $mode = ProductionCorrectionContextService::OriginalPeriod): object
    {
        Gate::authorize('production.runs.correct');

        return DB::transaction(function () use ($run, $output, $reason, $fingerprint, $postingDate, $receiptDates, $dateEvidence, $mode): object {
            Company::query()->whereKey($this->companies->requireCompanyId())->lockForUpdate()->firstOrFail();
            $run = $this->scopedRun($run, true);
            $snapshot = $this->snapshot($run, true);
            $this->assertCorrectable($run, $snapshot);
            $this->assertFingerprint($snapshot, $fingerprint);
            $target = $this->assertPostingDate($run, $postingDate, $mode);
            $dateBasis = $this->receiptDateBasis($run, $snapshot, $receiptDates, $dateEvidence);
            $output = $this->output($run, $output);
            if (trim($reason) === '' || mb_strlen($reason) > 2000) {
                throw new DomainException(__('production_run_correction.reason_required'));
            }
            $pending = DB::table('production_run_corrections')->where('production_run_id', $run->getKey())->where('status', 'prepared')->lockForUpdate()->first();
            if ($pending !== null) {
                if ($pending->fingerprint === $fingerprint && $pending->reason === trim($reason) && $pending->posting_date === $postingDate
                    && ($pending->correction_mode ?? ProductionCorrectionContextService::OriginalPeriod) === $mode
                    && (int) ($pending->posting_financial_period_id ?? $pending->financial_period_id) === (int) $target->id
                    && json_decode((string) $pending->receipt_date_basis, true, 512, JSON_THROW_ON_ERROR) === $dateBasis
                    && $pending->receipt_date_evidence === (filled($dateEvidence) ? trim($dateEvidence) : null)
                    && json_decode($pending->corrected_output, true, 512, JSON_THROW_ON_ERROR) === $output) {
                    return $pending;
                }
                throw new DomainException(__('production_run_correction.reject_existing'));
            }
            $id = DB::table('production_run_corrections')->insertGetId([
                'company_id' => $run->company_id, 'production_run_id' => $run->getKey(), 'financial_period_id' => $run->financial_period_id,
                'reason' => trim($reason), 'fingerprint' => $fingerprint, 'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'posting_date' => $postingDate,
                'posting_financial_period_id' => $target->id, 'correction_mode' => $mode,
                'receipt_date_basis' => json_encode($dateBasis, JSON_THROW_ON_ERROR), 'receipt_date_evidence' => filled($dateEvidence) ? trim($dateEvidence) : null,
                'corrected_output' => json_encode($output, JSON_THROW_ON_ERROR), 'prepared_by' => auth()->id(), 'status' => 'prepared',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->log($run, $id, 'prepared');

            return DB::table('production_run_corrections')->find($id);
        }, attempts: 3);
    }

    public function approve(ProductionRun $run, int $correctionId): object
    {
        Gate::authorize('production.runs.correct_approve');

        return DB::transaction(function () use ($run, $correctionId): object {
            Company::query()->whereKey($this->companies->requireCompanyId())->lockForUpdate()->firstOrFail();
            $run = $this->scopedRun($run, true);
            $proposal = $this->proposal($run, $correctionId);
            if (($proposal->correction_mode ?? ProductionCorrectionContextService::OriginalPeriod) === ProductionCorrectionContextService::LaterPeriod) {
                Gate::authorize('production.runs.correct_later_period');
            }
            if ((int) $proposal->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('production_run_correction.independent_approval'));
            }
            if ($proposal->status === 'approved') {
                return $proposal;
            }
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('production_run_correction.invalid_state'));
            }
            if ($proposal->posting_financial_period_id === null || $proposal->correction_mode === null) {
                throw new DomainException(__('production_run_correction.stale'));
            }
            $snapshot = $this->snapshot($run, true);
            $this->assertCorrectable($run, $snapshot);
            $this->assertFingerprint($snapshot, $proposal->fingerprint);
            $target = $this->assertPostingDate($run, (string) $proposal->posting_date,
                $proposal->correction_mode ?? ProductionCorrectionContextService::OriginalPeriod,
                (int) ($proposal->posting_financial_period_id ?? $proposal->financial_period_id));
            if ($proposal->receipt_date_basis === null) {
                throw new DomainException(__('production_run_correction.receipt_dates_required'));
            }
            $dateBasis = json_decode($proposal->receipt_date_basis, true, 512, JSON_THROW_ON_ERROR);
            $output = $this->output($run, json_decode($proposal->corrected_output, true, 512, JSON_THROW_ON_ERROR));
            DB::table('production_run_corrections')->where('id', $correctionId)->update(['status' => 'applying']);
            $this->releaseFinishedReservations($run);
            $documents = $run->inventoryDocuments()->where('status', InventoryDocument::StatusPosted)
                ->whereIn('document_type', [InventoryDocument::TypeProductionReceipt, InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste])
                ->orderByRaw('case when document_type = ? then 0 else 1 end', [InventoryDocument::TypeProductionReceipt])->orderByDesc('id')->get();
            foreach ($documents as $document) {
                $this->posting->reverseForProductionCorrection($document, $run, $correctionId, $proposal->reason);
            }
            $line = $run->orderLine()->lockForUpdate()->firstOrFail();
            if (bccomp((string) $line->received_base_quantity, (string) $run->received_base_quantity, 8) < 0) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            $line->decrement('received_base_quantity', $run->received_base_quantity);
            if ($line->sales_order_line_id !== null) {
                $salesLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
                $transactionQuantity = bcdiv((string) $run->received_base_quantity, (string) $salesLine->conversion_factor, 8);
                if (bccomp((string) $salesLine->produced_base_quantity, (string) $run->received_base_quantity, 8) < 0) {
                    throw new DomainException(__('production_run_correction.lineage_invalid'));
                }
                $salesLine->decrement('produced_base_quantity', $run->received_base_quantity);
                $salesLine->decrement('produced_quantity', $transactionQuantity);
            }
            $this->invalidatePiecePayroll($run, $correctionId, $snapshot['piece_approvals'], $snapshot['dependency_snapshot']);
            $delta = [];
            foreach (self::OutputFields as $field) {
                $delta[$field] = bcsub($output[$field], (string) $run->{$field}, 8);
            }
            $run->progressEntries()->create([...$delta, 'production_run_correction_id' => $correctionId,
                'recorded_at' => now(), 'recorded_by' => auth()->id(), 'notes' => $proposal->reason]);
            $run->requirements()->update(['consumed_quantity' => '0', 'waste_quantity' => '0', 'updated_at' => now()]);
            $labor = collect($run->labor_details ?? [])->map(function (array $row): array {
                unset($row['approved_piece_quantity'], $row['piece_quantity_approved_at'], $row['piece_quantity_approved_by']);

                return $row;
            })->all();
            $run->update([...$output, 'received_base_quantity' => '0', 'status' => ProductionRun::StatusRunning,
                'correction_sequence' => $run->correction_sequence + 1, 'completed_by' => null,
                'correction_document_date' => $proposal->posting_date,
                'correction_posting_financial_period_id' => $target->id, 'active_correction_id' => $correctionId,
                'correction_receipt_basis' => $dateBasis,
                'labor_details' => $labor, 'updated_by' => auth()->id()]);
            if ($run->production_order_stage_snapshot_id !== null) {
                $stage = ProductionOrderStageSnapshot::query()->lockForUpdate()->findOrFail($run->production_order_stage_snapshot_id);
                $previous = $stage->status;
                $stage->update(['status' => ProductionOrderStageSnapshot::StatusInProgress, 'completed_at' => null, 'completed_by' => null]);
                app(ProductionRoutingService::class)->recordStageEvent($stage, 'run_correction', $previous, $stage->status, $run->getKey());
            }
            $order = $run->order()->lockForUpdate()->firstOrFail();
            $hasReceipts = $order->lines()->where('received_base_quantity', '>', 0)->exists();
            $order->update(['status' => $hasReceipts ? ProductionOrder::StatusPartiallyCompleted : ProductionOrder::StatusInProgress, 'updated_by' => auth()->id()]);
            DB::table('production_run_corrections')->where('id', $correctionId)->update([
                'status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'updated_at' => now(),
            ]);
            $this->log($run, $correctionId, 'approved');

            return DB::table('production_run_corrections')->find($correctionId);
        }, attempts: 3);
    }

    public function reject(ProductionRun $run, int $correctionId): void
    {
        Gate::authorize('production.runs.correct_approve');
        DB::transaction(function () use ($run, $correctionId): void {
            Company::query()->whereKey($this->companies->requireCompanyId())->lockForUpdate()->firstOrFail();
            $run = $this->scopedRun($run, true);
            $proposal = $this->proposal($run, $correctionId);
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('production_run_correction.invalid_state'));
            }
            DB::table('production_run_corrections')->where('id', $correctionId)->update(['status' => 'rejected', 'rejected_at' => now(), 'updated_at' => now()]);
            $this->log($run, $correctionId, 'rejected');
        });
    }

    private function scopedRun(ProductionRun $run, bool $lock = false): ProductionRun
    {
        $company = $this->companies->currentCompany();
        abort_unless($company !== null && $this->scope->canAccessCompany(auth()->user(), $company), 404);
        $run = ProductionRun::query()->where('company_id', $company->getKey())->whereKey($run->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($this->scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $run->branch_id)->exists(), 404);
        abort_unless((int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $run->branch_id, 404);
        $period = $this->scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $run->financial_period_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $active = $this->scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
            ->where('financial_periods.id', request()->session()->get(OperatingContextService::FinancialPeriodIdKey))->firstOrFail();
        if ((int) $active->id !== (int) $period->id) {
            Gate::authorize('production.runs.correct_later_period');
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function snapshot(ProductionRun $run, bool $lock = false): array
    {
        if ($lock) {
            ProductionOrderLine::query()->whereKey($run->production_order_line_id)->lockForUpdate()->firstOrFail();
            ProductionOrder::query()->whereKey($run->production_order_id)->lockForUpdate()->firstOrFail();
            ProductionOrderStageSnapshot::query()->where('production_order_id', $run->production_order_id)->orderBy('id')->lockForUpdate()->get();
        }
        $query = fn (string $table) => DB::table($table)->where('production_run_id', $run->getKey())->orderBy('id')->when($lock, fn ($builder) => $builder->lockForUpdate());
        $documents = $query('inventory_documents')->get();
        $transactions = DB::table('inventory_transactions')->where('source_type', InventoryDocument::class)->whereIn('source_id', $documents->pluck('id'))->orderBy('id')->get();
        $layers = DB::table('inventory_receipt_layers')->whereIn('receipt_transaction_id', $transactions->pluck('id'))->orderBy('id')->get();

        $snapshot = ['run' => $run->getAttributes(), 'requirements' => $query('production_material_requirements')->get(),
            'documents' => $documents, 'transactions' => $transactions, 'layers' => $layers,
            'document_lines' => DB::table('inventory_document_lines')->whereIn('inventory_document_id', $documents->pluck('id'))->whereNull('deleted_at')->orderBy('id')->get(),
            'journal_lines' => DB::table('journal_entry_lines')->whereIn('journal_entry_id', $documents->pluck('journal_entry_id')->filter())->orderBy('id')->get(),
            'journals' => DB::table('journal_entries')->whereIn('id', $documents->pluck('journal_entry_id')->filter())->orderBy('id')->get(),
            'allocations' => DB::table('inventory_layer_allocations')->whereIn('inventory_receipt_layer_id', $layers->pluck('id'))->orderBy('id')->get(),
            'reservations' => $query('inventory_reservations')->get(), 'progress' => $query('production_progress_entries')->get(),
            'inspections' => $query('quality_inspections')->get(), 'piece_approvals' => $query('production_piece_approvals')->get(),
            'expenses' => $query('production_expense_requests')->get()];
        $snapshot['dependency_snapshot'] = $this->dependencies->snapshot($run, $documents, $transactions, $lock);
        $snapshot['impact'] = $this->impact($snapshot);

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function assertCorrectable(ProductionRun $run, array $snapshot): void
    {
        if ($run->status !== ProductionRun::StatusCompleted) {
            throw new DomainException(__('production_run_correction.completed_required'));
        }
        $steps = $this->dependencies->steps($snapshot['dependency_snapshot']);
        if ($steps !== []) {
            throw new DomainException(__('production_run_correction.dependencies_required').' '.implode(', ', array_unique(array_column($steps, 'doc_num'))));
        }
        foreach ($snapshot['documents']->where('status', InventoryDocument::StatusPosted)->where('document_type', InventoryDocument::TypeProductionReceipt) as $document) {
            foreach ($snapshot['transactions']->where('source_id', $document->id)->where('is_reversal', false) as $receipt) {
                $lineage = app(InventoryLayerService::class)->receiptLineageTransactionIds((int) $receipt->id);
                $remaining = DB::table('inventory_receipt_layers')->whereIn('receipt_transaction_id', $lineage)
                    ->where('branch_store_id', $receipt->branch_store_id)->where('product_id', $receipt->product_id)
                    ->where('stock_status', $receipt->stock_status)->where('batch_lot', $receipt->batch_lot)
                    ->where('warehouse_location_id', $receipt->warehouse_location_id)->sum('remaining_quantity');
                if (bccomp((string) $remaining, (string) $receipt->quantity_in, 8) < 0) {
                    $dependents = DB::table('inventory_layer_allocations as allocation')
                        ->join('inventory_receipt_layers as layer', 'layer.id', '=', 'allocation.inventory_receipt_layer_id')
                        ->join('inventory_transactions as issue', 'issue.id', '=', 'allocation.issue_transaction_id')
                        ->whereIn('layer.receipt_transaction_id', $lineage)->where('issue.is_reversal', false)
                        ->distinct()->pluck('issue.source_doc_num')->all();
                    throw new DomainException(__('production_run_correction.stock_dependency', ['receipt' => $document->doc_num, 'documents' => implode(', ', $dependents)]));
                }
            }
        }
        $received = DB::table('inventory_document_lines as line')->join('inventory_documents as document', 'document.id', '=', 'line.inventory_document_id')
            ->where('document.production_run_id', $run->getKey())->where('document.document_type', InventoryDocument::TypeProductionReceipt)
            ->where('document.status', InventoryDocument::StatusPosted)->whereNull('line.deleted_at')->sum('line.quantity');
        if (bccomp((string) $received, (string) $run->received_base_quantity, 8) !== 0) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        foreach ($snapshot['requirements'] as $requirement) {
            foreach ([InventoryDocument::TypeMaterialConsumption => 'consumed_quantity', InventoryDocument::TypeProductionWaste => 'waste_quantity'] as $type => $counter) {
                $documentIds = $snapshot['documents']->where('status', InventoryDocument::StatusPosted)->where('document_type', $type)->pluck('id');
                $lines = $snapshot['document_lines']->whereIn('inventory_document_id', $documentIds)
                    ->where('source_line_type', ProductionMaterialRequirement::class)->where('source_line_id', $requirement->id);
                $quantity = $lines->reduce(fn (string $sum, object $line): string => bcadd($sum, (string) $line->quantity, 8), '0');
                if (bccomp($quantity, (string) $requirement->{$counter}, 8) !== 0) {
                    throw new DomainException(__('production_run_correction.lineage_invalid'));
                }
            }
        }
        $executionPeriodId = app(ProductionCorrectionContextService::class)->executionPeriodId($run);
        if ($snapshot['documents']->contains(fn (object $document): bool => $document->status === InventoryDocument::StatusPosted
            && in_array($document->document_type, [InventoryDocument::TypeProductionReceipt, InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste], true)
            && ((int) $document->financial_period_id !== $executionPeriodId || $document->source_document_type !== ProductionRun::class
                || (int) $document->source_document_id !== (int) $run->getKey()))) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        if ($run->production_order_stage_snapshot_id !== null) {
            $stage = $run->stageSnapshot;
            $dependent = ProductionRun::query()->where('production_order_line_id', $run->production_order_line_id)
                ->whereKeyNot($run->getKey())->where('status', '<>', ProductionRun::StatusCancelled)
                ->whereHas('stageSnapshot', fn ($query) => $query->where('sequence', '>', $stage->sequence))->first();
            if ($dependent !== null) {
                throw new DomainException(__('production_run_correction.downstream_run', ['run' => $dependent->run_number]));
            }
        }
    }

    /** @param array<string, mixed> $output @return array<string, string> */
    private function output(ProductionRun $run, array $output): array
    {
        $result = [];
        $total = '0.00000000';
        foreach (self::OutputFields as $field) {
            $value = (string) ($output[$field] ?? '0');
            if (! preg_match('/^\d+(?:\.\d{1,8})?$/D', $value)) {
                throw new DomainException(__('production_run_correction.output_invalid'));
            }
            $result[$field] = bcadd($value, '0', 8);
            $total = bcadd($total, $result[$field], 8);
        }
        $allowed = bcmul((string) $run->planned_base_quantity, bcadd('1', bcdiv((string) $run->order->overproduction_tolerance_percent, '100', 8), 8), 8);
        if (bccomp($result['good_base_quantity'], '0', 8) <= 0 || bccomp($total, $allowed, 8) > 0) {
            throw new DomainException(__('production_run_correction.output_invalid'));
        }

        return $result;
    }

    private function assertPostingDate(ProductionRun $run, string $date, string $mode = ProductionCorrectionContextService::OriginalPeriod, ?int $expectedPeriodId = null): FinancialPeriod
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! CarbonImmutable::hasFormat($date, 'Y-m-d')) {
            throw new DomainException(__('production_run_correction.posting_date_invalid'));
        }

        return app(ProductionCorrectionContextService::class)->target($run, $date, $mode, $expectedPeriodId);
    }

    /** @param array<string, mixed> $snapshot @param array<int, array<string, string|null>> $dates @return list<array<string, mixed>> */
    private function receiptDateBasis(ProductionRun $run, array $snapshot, array $dates, ?string $evidence): array
    {
        $documents = $snapshot['documents']->where('document_type', InventoryDocument::TypeProductionReceipt)->where('status', InventoryDocument::StatusPosted)->pluck('id');
        $lines = $snapshot['document_lines']->whereIn('inventory_document_id', $documents);
        if (array_diff(array_map('intval', array_keys($dates)), $lines->pluck('id')->all()) !== []) {
            throw new DomainException(__('production_run_correction.lineage_invalid'));
        }
        $basis = [];
        foreach ($lines as $line) {
            $manufacture = $line->manufacture_date === null ? ($dates[$line->id]['manufacture_date'] ?? null) : CarbonImmutable::parse($line->manufacture_date)->toDateString();
            $expiry = $line->expiry_date === null ? ($dates[$line->id]['expiry_date'] ?? null) : CarbonImmutable::parse($line->expiry_date)->toDateString();
            $supplemented = ($line->manufacture_date === null && $manufacture !== null) || ($line->expiry_date === null && $expiry !== null);
            if ($manufacture === null || ! CarbonImmutable::hasFormat($manufacture, 'Y-m-d')
                || ($run->product->tracks_expiry && $expiry === null)
                || ($expiry !== null && (! CarbonImmutable::hasFormat($expiry, 'Y-m-d') || $expiry < $manufacture))
                || ($supplemented && blank($evidence))) {
                throw new DomainException(__('production_run_correction.receipt_dates_required'));
            }
            foreach (['manufacture_date', 'expiry_date'] as $field) {
                if ($line->{$field} !== null && filled($dates[$line->id][$field] ?? null) && $dates[$line->id][$field] !== CarbonImmutable::parse($line->{$field})->toDateString()) {
                    throw new DomainException(__('production_run_correction.receipt_dates_immutable'));
                }
            }
            $basis[] = ['source_line_id' => $line->id, 'quantity' => (string) $line->quantity,
                'batch_lot' => $line->batch_lot ?? $run->batch_lot, 'manufacture_date' => $manufacture, 'expiry_date' => $expiry];
        }

        return $basis;
    }

    /** @param array<string, mixed> $snapshot @return array{stock: list<array<string, mixed>>, journals: list<array<string, mixed>>} */
    private function impact(array $snapshot): array
    {
        $documents = $snapshot['documents']->where('status', InventoryDocument::StatusPosted)
            ->whereIn('document_type', [InventoryDocument::TypeProductionReceipt, InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeProductionWaste])->keyBy('id');
        $products = DB::table('products')->whereIn('id', $snapshot['transactions']->pluck('product_id'))->get()->keyBy('id');
        $stock = $snapshot['transactions']->whereIn('source_id', $documents->keys())->where('is_reversal', false)
            ->map(fn (object $transaction): array => [
                'document' => $documents[$transaction->source_id]->doc_num,
                'date' => $transaction->transaction_date,
                'product' => ($products[$transaction->product_id]->doc_num ?? $transaction->product_id).' — '.($products[$transaction->product_id]->name ?? ''),
                'stock_status' => $transaction->stock_status,
                'quantity_change' => bcsub((string) $transaction->quantity_out, (string) $transaction->quantity_in, 8),
                'value_change' => $transaction->total_cost === null ? null : (bccomp((string) $transaction->quantity_in, '0', 8) > 0
                    ? bcsub('0', (string) $transaction->total_cost, 8) : (string) $transaction->total_cost),
            ])->values()->all();
        $journalDocuments = $documents->filter(fn (object $document): bool => $document->journal_entry_id !== null)->keyBy('journal_entry_id');
        $accounts = DB::table('accounts')->whereIn('id', $snapshot['journal_lines']->pluck('account_id'))->get()->keyBy('id');
        $branches = DB::table('branches')->whereIn('id', $snapshot['journal_lines']->pluck('branch_id'))->pluck('name', 'id');
        $centers = DB::table('cost_centers')->whereIn('id', $snapshot['journal_lines']->pluck('cost_center_id')->filter())->pluck('name', 'id');
        $journals = $snapshot['journal_lines']->whereIn('journal_entry_id', $journalDocuments->keys())
            ->map(fn (object $line): array => [
                'document' => $journalDocuments[$line->journal_entry_id]->doc_num,
                'account' => ($accounts[$line->account_id]->account_code ?? $line->account_id).' — '.($accounts[$line->account_id]->name ?? ''),
                'branch' => $branches->get($line->branch_id, '—'), 'cost_center' => $centers->get($line->cost_center_id, '—'),
                'debit' => (string) $line->credit_amount, 'credit' => (string) $line->debit_amount,
            ])->values()->all();

        return compact('stock', 'journals');
    }

    private function releaseFinishedReservations(ProductionRun $run): void
    {
        foreach ($run->reservations()->whereNotNull('sales_order_line_id')->where('status', InventoryReservation::StatusActive)->lockForUpdate()->get() as $reservation) {
            $line = SalesOrderLine::query()->lockForUpdate()->findOrFail($reservation->sales_order_line_id);
            if (bccomp((string) $line->reserved_base_quantity, (string) $reservation->quantity, 8) < 0) {
                throw new DomainException(__('production_run_correction.lineage_invalid'));
            }
            $remaining = $reservation->remaining_quantity;
            $line->decrement('reserved_base_quantity', $remaining);
            $line->decrement('reserved_quantity', bcdiv($remaining, (string) $reservation->conversion_factor, 8));
            $reservation->update(['released_quantity' => bcadd((string) $reservation->released_quantity, $remaining, 8), 'status' => InventoryReservation::StatusReleased, 'released_at' => now(), 'release_reason' => __('production_run_correction.title'), 'updated_by' => auth()->id()]);
        }
    }

    /** @param iterable<object> $approvals @param array<string, mixed> $dependencySnapshot */
    private function invalidatePiecePayroll(ProductionRun $run, int $correctionId, iterable $approvals, array $dependencySnapshot): void
    {
        $this->dependencies->assertCanInvalidateCalculatedPayroll($dependencySnapshot);
        $ids = collect($approvals)->whereNull('revoked_at')->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        $payrolls = DB::table('hr_payroll_runs')->whereIn('id', $this->dependencies->linkedPayrollRunIds($dependencySnapshot))
            ->whereNotIn('status', ['draft', 'reversed'])->orderBy('id')->lockForUpdate()->get(['id', 'status']);
        foreach ($payrolls as $payroll) {
            if ($payroll->status !== 'calculated') {
                throw new DomainException(__('production_run_correction.payroll_dependency', ['run' => $payroll->id]));
            }
            DB::table('hr_payroll_runs')->where('id', $payroll->id)->update(['status' => 'draft', 'calculated_at' => null, 'updated_at' => now()]);
            DB::table('hr_payslips')->where('payroll_run_id', $payroll->id)->update(['status' => 'draft', 'updated_at' => now()]);
            DB::table('hr_payroll_run_employees')->where('payroll_run_id', $payroll->id)->update(['status' => 'draft', 'updated_at' => now()]);
        }
        DB::table('production_piece_approvals')->whereIn('id', $ids)->update([
            'revoked_at' => now(), 'revoked_by' => auth()->id(), 'production_run_correction_id' => $correctionId, 'updated_at' => now(),
        ]);
    }

    private function proposal(ProductionRun $run, int $id): object
    {
        return DB::table('production_run_corrections')->where('company_id', $run->company_id)->where('production_run_id', $run->getKey())->where('id', $id)->lockForUpdate()->firstOrFail();
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

    private function log(ProductionRun $run, int $id, string $action): void
    {
        $this->audit->log(request(), 'production', 'production.run_correction.'.$action, 'success', [
            'subject' => $run, 'causer' => auth()->user(), 'company_id' => $run->company_id, 'correction_id' => $id,
        ]);
    }
}
