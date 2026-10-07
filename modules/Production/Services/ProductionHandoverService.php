<?php

namespace Modules\Production\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionRunBatch;

class ProductionHandoverService
{
    public function __construct(
        private readonly ProductionCycleService $cycle,
        private readonly InventoryMovementService $movements,
        private readonly InventoryDocumentPostingService $posting,
        private readonly ActivityLogger $audit,
    ) {}

    /** @return Collection<int, ProductionRun> */
    public function runs(ProductionRun $anchor): Collection
    {
        $this->context($anchor);

        return ProductionRun::query()->where('company_id', $anchor->company_id)->where('branch_id', $anchor->branch_id)
            ->where('production_order_id', $anchor->production_order_id)
            ->when($anchor->production_run_batch_id !== null,
                fn ($query) => $query->where('production_run_batch_id', $anchor->production_run_batch_id),
                fn ($query) => $query->whereKey($anchor->id))
            ->with(['product', 'unit', 'orderLine.product', 'stageSnapshot', 'order', 'inspections.qualityType'])
            ->orderBy('id')->get();
    }

    /** @return array{produced: string, received: string, committed: string, remaining: string, quality_available: ?string} */
    public function quantities(ProductionRun $run): array
    {
        $committed = $this->committedQuantity($run);
        $remaining = bcsub(bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8), $committed, 8);

        return ['produced' => (string) $run->good_base_quantity, 'received' => (string) $run->received_base_quantity,
            'committed' => $committed, 'remaining' => bccomp($remaining, '0', 8) < 0 ? '0.00000000' : $remaining,
            'quality_available' => app(ProductionQualityQuantityService::class)->usesQuantityBatches($run)
                ? app(ProductionQualityQuantityService::class)->availableQuantity($run) : null];
    }

    /** @param list<array{run_public_id: string, quantity: string, serial_numbers?: list<string>}> $inputs */
    public function createHandover(ProductionRun $anchor, int $storeId, string $date, array $inputs, ?string $notes = null): InventoryDocument
    {
        Gate::authorize('production.handovers.create');

        return DB::transaction(function () use ($anchor, $storeId, $date, $inputs, $notes): InventoryDocument {
            $this->lockCompany($anchor);
            $context = $this->context($anchor, $date);
            $allowed = $this->runs($anchor)->keyBy('public_id');
            $seen = [];
            $lines = [];
            foreach ($inputs as $input) {
                $run = $allowed->get($input['run_public_id']);
                if (! $run instanceof ProductionRun || isset($seen[$run->id])) {
                    throw new DomainException(__('production_handover.invalid_line'));
                }
                $seen[$run->id] = true;
                $run = ProductionRun::query()->lockForUpdate()->findOrFail($run->id);
                $this->assertRunExecutionContext($run);
                $this->assertDate($date, $run->actual_start_at?->toDateString());
                $quantity = $this->quantity(bcmul($this->quantity($input['quantity']), (string) $run->conversion_factor, 8));
                if ($run->status !== ProductionRun::StatusRunning || bccomp($quantity, '0', 8) <= 0
                    || bccomp($quantity, $this->quantities($run)['remaining'], 8) > 0) {
                    throw new DomainException(__('production_handover.exceeds_output'));
                }
                $manufacture = $date;
                $expiry = $run->product->tracks_expiry && $run->product->default_shelf_life_days
                    ? CarbonImmutable::parse($manufacture)->addDays((int) $run->product->default_shelf_life_days)->toDateString() : null;
                $basisLines = app(ProductionReceiptCancellationService::class)->isReceiptOnlyRecovery($run) || $run->correction_sequence > 0
                    ? $this->cycle->finishedGoodsReplacementLines($run, $quantity)
                    : [['quantity' => $quantity, 'batch_lot' => $run->batch_lot, 'manufacture_date' => $manufacture, 'expiry_date' => $expiry]];
                $serialNumbers = $input['serial_numbers'] ?? [];
                if (($run->product->tracks_serials && bccomp((string) count($serialNumbers), $quantity, 8) !== 0)
                    || (! $run->product->tracks_serials && $serialNumbers !== [])) {
                    throw new DomainException(__('inventory_serial.count_mismatch'));
                }
                foreach ($basisLines as $basisLine) {
                    $lines[] = ['product_id' => $run->product_id, 'unit_id' => $run->product->item_unit_id,
                        'transaction_unit_id' => $run->unit_id, 'quantity' => $basisLine['quantity'], 'transaction_quantity' => bcdiv((string) $basisLine['quantity'], (string) $run->conversion_factor, 8),
                        'conversion_factor' => $run->conversion_factor, 'production_run_id' => $run->id,
                        'source_line_type' => ProductionRun::class, 'source_line_id' => $run->id, 'source_line_public_id' => $run->public_id,
                        'batch_lot' => $basisLine['batch_lot'], 'manufacture_date' => $basisLine['manufacture_date'], 'expiry_date' => $basisLine['expiry_date'],
                        'serial_numbers' => $run->product->tracks_serials ? array_splice($serialNumbers, 0, (int) $basisLine['quantity']) : [],
                        'product_snapshot' => ['doc_num' => $run->product->doc_num, 'name' => $run->product->name,
                            'run_number' => $run->run_number, 'transaction_unit_name' => $run->unit?->name]];
                }
            }
            if ($lines === []) {
                throw new DomainException(__('production_handover.positive_line_required'));
            }
            $document = $this->movements->createDraft([...$context, 'branch_store_id' => $storeId,
                'document_type' => InventoryDocument::TypeProductionHandover, 'document_date' => $date,
                'production_order_id' => $anchor->production_order_id, 'production_run_batch_id' => $anchor->production_run_batch_id,
                'production_run_id' => $anchor->production_run_batch_id === null ? $anchor->id : null,
                'source_document_type' => $anchor->production_run_batch_id === null ? ProductionRun::class : ProductionRunBatch::class,
                'source_document_id' => $anchor->production_run_batch_id ?? $anchor->id,
                'source_doc_num' => $anchor->production_run_batch_id === null ? $anchor->run_number : $anchor->batch->batch_number,
                'purpose' => __('production_handover.title'), 'notes' => $notes], $lines);
            $this->log($document, 'production.handover.created', ['production_run_ids' => array_keys($seen)]);

            return $document;
        }, 3);
    }

    public function approveHandover(InventoryDocument $document): InventoryDocument
    {
        Gate::authorize('production.handovers.approve');

        return DB::transaction(function () use ($document): InventoryDocument {
            $this->lockCompany($document);
            $locked = $this->handover($document, lock: true);
            $this->context($locked, now()->toDateString());
            if ($locked->status === InventoryDocument::StatusApproved) {
                return $locked;
            }
            if (! $locked->isUntouchedDraft()) {
                throw new DomainException(__('production_handover.invalid_state'));
            }
            foreach ($locked->lines->groupBy('production_run_id') as $runId => $lines) {
                $run = ProductionRun::query()->lockForUpdate()->findOrFail($runId);
                if ($lines->contains(fn ($line) => $line->source_line_type !== ProductionRun::class
                    || (int) $line->source_line_id !== (int) $run->id || (int) $line->product_id !== (int) $run->product_id
                    || (int) $line->unit_id !== (int) $run->product->item_unit_id || (int) $line->transaction_unit_id !== (int) $run->unit_id
                    || bccomp((string) $line->conversion_factor, (string) $run->conversion_factor, 8) !== 0)
                    || (int) $run->company_id !== (int) $locked->company_id || (int) $run->branch_id !== (int) $locked->branch_id
                    || (int) $run->production_order_id !== (int) $locked->production_order_id) {
                    throw new DomainException(__('production_handover.invalid_line'));
                }
                $this->assertRunExecutionContext($run);
                $quantity = $this->sum($lines, 'quantity');
                if (bccomp(bcadd($quantity, $this->committedQuantity($run, $locked->id), 8),
                    bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8), 8) > 0) {
                    throw new DomainException(__('production_handover.exceeds_output'));
                }
                $available = app(ProductionQualityQuantityService::class)->usesQuantityBatches($run)
                    ? app(ProductionQualityQuantityService::class)->availableQuantity($run) : null;
                if ($available !== null && bccomp(bcadd($quantity, $this->committedQuantity($run, $locked->id), 8), $available, 8) > 0) {
                    throw new DomainException(__('production_execution.evidence.quality_receipt_exceeded'));
                }
                $this->cycle->prepareFinishedGoodsReceipt($run, (int) $locked->branch_store_id, $quantity,
                    serialNumbers: $lines->filter(fn ($line) => $line->serialIdentity !== null)->map(fn ($line) => $line->serialIdentity->serial_number)->values()->all(), includeCost: false);
            }
            $locked->forceFill(['status' => InventoryDocument::StatusApproved, 'approved_by' => auth()->id(), 'approved_at' => now(), 'updated_by' => auth()->id()])->save();
            $this->log($locked, 'production.handover.approved');

            return $locked->refresh()->load('lines');
        }, 3);
    }

    public function cancelHandover(InventoryDocument $document, string $reason): InventoryDocument
    {
        Gate::authorize('production.handovers.cancel');

        return DB::transaction(function () use ($document, $reason): InventoryDocument {
            $this->lockCompany($document);
            $locked = $this->handover($document, lock: true);
            $this->context($locked, now()->toDateString());
            $this->reason($reason);
            if ($locked->status === InventoryDocument::StatusCancelled) {
                return $locked;
            }
            if (! in_array($locked->status, [InventoryDocument::StatusDraft, InventoryDocument::StatusApproved], true)
                || $this->warehouseReceipts($locked)->withTrashed()->get()->contains(fn (InventoryDocument $receipt): bool => ($receipt->status !== InventoryDocument::StatusCancelled || $receipt->transactions()->exists() || $receipt->journal_entry_id !== null)
                    && ! app(ProductionWarehouseReceiptCorrectionService::class)->hasVerifiedReversal($receipt))
                || $locked->transactions()->exists() || $locked->journal_entry_id !== null) {
                throw new DomainException(__('production_handover.receipts_must_be_corrected'));
            }
            $locked->forceFill(['status' => InventoryDocument::StatusCancelled, 'cancelled_at' => now(), 'cancelled_by' => auth()->id(),
                'cancel_reason' => trim($reason), 'updated_by' => auth()->id()])->save();
            $this->log($locked, 'production.handover.cancelled', ['reason' => trim($reason)]);

            return $locked;
        }, 3);
    }

    /** @param list<array{line_public_id: string, quantity: string}> $inputs */
    public function createWarehouseReceipt(InventoryDocument $handover, string $date, array $inputs, ?string $notes = null): InventoryDocument
    {
        Gate::authorize('inventory.production_receipts.create');

        return DB::transaction(function () use ($handover, $date, $inputs, $notes): InventoryDocument {
            $this->lockCompany($handover);
            $handover = $this->handover($handover, lock: true);
            $context = $this->context($handover, $date);
            $this->assertDate($date, $handover->document_date->toDateString());
            if ($handover->status !== InventoryDocument::StatusApproved) {
                throw new DomainException(__('production_handover.approved_required'));
            }
            $sourceLines = $this->remainingLines($handover)->keyBy('public_id');
            $seen = [];
            $lines = [];
            foreach ($inputs as $input) {
                $source = $sourceLines->get($input['line_public_id']);
                if (! $source instanceof InventoryDocumentLine || isset($seen[$source->id])) {
                    throw new DomainException(__('production_handover.invalid_line'));
                }
                $seen[$source->id] = true;
                $quantity = bcmul($this->quantity($input['quantity']), (string) $source->conversion_factor, 8);
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, (string) $source->remaining_quantity, 8) > 0) {
                    throw new DomainException(__('production_handover.exceeds_handover'));
                }
                $run = ProductionRun::query()->lockForUpdate()->findOrFail($source->production_run_id);
                $this->assertRunExecutionContext($run);
                $this->cycle->prepareFinishedGoodsReceipt($run, (int) $handover->branch_store_id, $quantity,
                    serialNumbers: $source->serialIdentity === null ? [] : [$source->serialIdentity->serial_number], includeCost: false);
                $lines[] = ['product_id' => $source->product_id, 'unit_id' => $source->unit_id, 'transaction_unit_id' => $source->transaction_unit_id,
                    'quantity' => $quantity, 'transaction_quantity' => $input['quantity'], 'conversion_factor' => $source->conversion_factor,
                    'production_run_id' => $source->production_run_id, 'source_line_type' => InventoryDocumentLine::class,
                    'source_line_id' => $source->id, 'source_line_public_id' => $source->public_id,
                    'batch_lot' => $source->batch_lot, 'manufacture_date' => $source->manufacture_date?->toDateString(),
                    'expiry_date' => $source->expiry_date?->toDateString(), 'serial_number' => $source->serialIdentity?->serial_number,
                    'product_snapshot' => $source->product_snapshot];
            }
            if ($lines === []) {
                throw new DomainException(__('production_handover.positive_line_required'));
            }
            $receipt = $this->movements->createDraft([...$context, 'branch_store_id' => $handover->branch_store_id,
                'document_type' => InventoryDocument::TypeProductionReceipt, 'document_date' => $date,
                'production_order_id' => $handover->production_order_id, 'production_run_batch_id' => $handover->production_run_batch_id,
                'source_document_type' => InventoryDocument::class, 'source_document_id' => $handover->id, 'source_doc_num' => $handover->doc_num,
                'purpose' => __('production_handover.warehouse_title'), 'destination_stock_status' => InventoryTransaction::StatusAvailable,
                'notes' => $notes], $lines);
            $this->log($receipt, 'production.warehouse_receipt.created', ['handover_id' => $handover->id]);

            return $receipt;
        }, 3);
    }

    public function approveWarehouseReceipt(InventoryDocument $document): InventoryDocument
    {
        Gate::authorize('inventory.production_receipts.approve');

        return DB::transaction(function () use ($document): InventoryDocument {
            $this->lockCompany($document);
            $receipt = $this->warehouseReceipt($document, lock: true);
            $this->context($receipt, $receipt->document_date->toDateString());
            $handover = $this->handover(InventoryDocument::query()->findOrFail($receipt->source_document_id), lock: true);
            $this->context($handover);
            if ((int) $handover->approved_by === (int) auth()->id() || (int) $handover->created_by === (int) auth()->id()) {
                throw new DomainException(__('production_handover.warehouse_independent'));
            }
            if ($receipt->status === InventoryDocument::StatusPosted) {
                return $receipt;
            }
            if (! $receipt->isUntouchedDraft() || $handover->status !== InventoryDocument::StatusApproved) {
                throw new DomainException(__('production_handover.invalid_state'));
            }
            $remaining = $this->remainingLines($handover)->keyBy('id');
            foreach ($receipt->lines->groupBy('source_line_id') as $id => $lines) {
                $source = $remaining->get($id);
                if ($source === null || bccomp($this->sum($lines, 'quantity'), (string) $source->remaining_quantity, 8) > 0
                    || $lines->contains(fn ($line) => $line->source_line_type !== InventoryDocumentLine::class
                        || (int) $line->production_run_id !== (int) $source->production_run_id || (int) $line->product_id !== (int) $source->product_id
                        || (int) $line->unit_id !== (int) $source->unit_id || (int) $line->transaction_unit_id !== (int) $source->transaction_unit_id
                        || bccomp((string) $line->conversion_factor, (string) $source->conversion_factor, 8) !== 0
                        || (int) $line->inventory_serial_identity_id !== (int) $source->inventory_serial_identity_id
                        || $line->batch_lot !== $source->batch_lot || $line->manufacture_date?->toDateString() !== $source->manufacture_date?->toDateString()
                        || $line->expiry_date?->toDateString() !== $source->expiry_date?->toDateString())) {
                    throw new DomainException(__('production_handover.exceeds_handover'));
                }
            }
            $plans = [];
            foreach ($receipt->lines->groupBy('production_run_id') as $runId => $lines) {
                $run = ProductionRun::query()->lockForUpdate()->findOrFail($runId);
                $this->assertRunExecutionContext($run);
                $quantity = $this->sum($lines, 'quantity');
                $plan = $this->cycle->prepareFinishedGoodsReceipt($run, (int) $receipt->branch_store_id, $quantity,
                    serialNumbers: $lines->filter(fn ($line) => $line->serialIdentity !== null)->map(fn ($line) => $line->serialIdentity->serial_number)->values()->all());
                $cost = $plan['receipt_cost'];
                $unitCost = bcdiv($cost, $quantity, 8);
                $remainingCost = $cost;
                foreach ($lines->values() as $index => $line) {
                    $lineCost = $index === $lines->count() - 1 ? $remainingCost : bcmul((string) $line->quantity, $unitCost, 8);
                    $line->forceFill(['unit_cost' => bcdiv($lineCost, (string) $line->quantity, 8)])->save();
                    $remainingCost = bcsub($remainingCost, $lineCost, 8);
                }
                $plans[] = ['plan' => $plan, 'quantity' => $quantity];
            }
            $receipt = $this->posting->postProductionWarehouseReceipt($receipt->refresh());
            foreach ($plans as $row) {
                $this->cycle->recordFinishedGoodsReceipt($row['plan'], $receipt, (int) $receipt->branch_store_id, $row['quantity']);
            }
            $this->log($receipt, 'production.warehouse_receipt.approved', ['handover_id' => $handover->id]);

            return $receipt->refresh()->load('lines');
        }, 3);
    }

    public function cancelDraftWarehouseReceipt(InventoryDocument $document, string $reason): InventoryDocument
    {
        Gate::authorize('inventory.production_receipts.cancel');

        return DB::transaction(function () use ($document, $reason): InventoryDocument {
            $this->lockCompany($document);
            $receipt = $this->warehouseReceipt($document, lock: true);
            $this->context($receipt, now()->toDateString());
            $this->reason($reason);
            if ($receipt->status === InventoryDocument::StatusCancelled) {
                return $receipt;
            }
            if (! $receipt->isUntouchedDraft()) {
                throw new DomainException(__('production_handover.posted_receipt_correction_required'));
            }
            $receipt->forceFill(['status' => InventoryDocument::StatusCancelled, 'cancelled_at' => now(), 'cancelled_by' => auth()->id(),
                'cancel_reason' => trim($reason), 'updated_by' => auth()->id()])->save();
            $this->log($receipt, 'production.warehouse_receipt.cancelled', ['reason' => trim($reason)]);

            return $receipt;
        }, 3);
    }

    /** @return Collection<int, InventoryDocumentLine> */
    public function remainingLines(InventoryDocument $handover): Collection
    {
        $received = $this->postedBySource((int) $handover->company_id);

        return $handover->lines()->with(['product', 'unit', 'transactionUnit', 'productionRun', 'serialIdentity'])
            ->leftJoinSub($received, 'received', fn ($join) => $join->on('received.source_line_id', '=', 'inventory_document_lines.id'))
            ->select('inventory_document_lines.*')->selectRaw('coalesce(received.received_quantity, 0) as received_quantity, inventory_document_lines.quantity - coalesce(received.received_quantity, 0) as remaining_quantity')->get();
    }

    public function warehouseReceipts(InventoryDocument $handover): \Illuminate\Database\Eloquent\Builder
    {
        return InventoryDocument::query()->where('company_id', $handover->company_id)->where('document_type', InventoryDocument::TypeProductionReceipt)
            ->where('source_document_type', InventoryDocument::class)->where('source_document_id', $handover->id)->orderBy('id');
    }

    public function handover(InventoryDocument $document, bool $lock = false): InventoryDocument
    {
        $this->context($document);

        return InventoryDocument::query()->where('company_id', $document->company_id)->where('document_type', InventoryDocument::TypeProductionHandover)
            ->when($lock, fn ($query) => $query->lockForUpdate())->with(['lines.product', 'lines.productionRun', 'lines.serialIdentity'])->findOrFail($document->id);
    }

    public function warehouseReceipt(InventoryDocument $document, bool $lock = false): InventoryDocument
    {
        $this->context($document);

        return InventoryDocument::query()->where('company_id', $document->company_id)->where('document_type', InventoryDocument::TypeProductionReceipt)
            ->where('source_document_type', InventoryDocument::class)->when($lock, fn ($query) => $query->lockForUpdate())
            ->with(['lines.product', 'lines.transactionUnit', 'lines.serialIdentity'])->findOrFail($document->id);
    }

    /** @return array{company_id: int, branch_id: int, financial_period_id: int} */
    public function context(Model $source, ?string $postingDate = null): array
    {
        $context = app(OperatingContextService::class)->snapshot(request());
        $company = Company::query()->find($context['company_id']);
        $scope = app(OperatingScopeAccessService::class);
        if ($company === null || auth()->user() === null || ! $context['branch_id'] || ! $context['financial_period_id']
            || (int) $source->company_id !== (int) $context['company_id'] || (int) $source->branch_id !== (int) $context['branch_id']
            || ! $scope->canAccessCompany(auth()->user(), $company)
            || ! $scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->whereKey($source->branch_id)->exists()
            || ! $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->whereKey($source->financial_period_id)->exists()) {
            throw new DomainException(__('production_handover.context_mismatch'));
        }
        if ($postingDate !== null) {
            if (! $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num], openOnly: true)->whereKey($context['financial_period_id'])->exists()) {
                throw new DomainException(__('production_handover.context_mismatch'));
            }
            app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $source->company_id, $postingDate,
                (int) $context['financial_period_id'], lockForUpdate: DB::transactionLevel() > 0);
        }

        return ['company_id' => (int) $source->company_id, 'branch_id' => (int) $source->branch_id, 'financial_period_id' => (int) $context['financial_period_id']];
    }

    private function assertRunExecutionContext(ProductionRun $run): void
    {
        $context = $this->context($run);
        if ($run->active_correction_id === null && $run->correction_posting_financial_period_id === null) {
            return;
        }
        $corrections = app(ProductionCorrectionContextService::class);
        if ((int) $context['financial_period_id'] !== $corrections->executionPeriodId($run)) {
            throw new DomainException(__('production_run_correction.target_changed'));
        }
        if ($run->active_correction_id !== null) {
            $corrections->requireExecutionPeriod($run);
        }
    }

    private function committedQuantity(ProductionRun $run, ?int $ignoreId = null): string
    {
        $received = $this->postedBySource((int) $run->company_id);
        $quantity = DB::table('inventory_document_lines as line')->join('inventory_documents as handover', 'handover.id', '=', 'line.inventory_document_id')
            ->leftJoinSub($received, 'received', fn ($join) => $join->on('received.source_line_id', '=', 'line.id'))
            ->where('handover.company_id', $run->company_id)->where('handover.branch_id', $run->branch_id)
            ->where('handover.document_type', InventoryDocument::TypeProductionHandover)->where('handover.status', InventoryDocument::StatusApproved)
            ->where('line.production_run_id', $run->id)->whereNull('line.deleted_at')->whereNull('handover.deleted_at')
            ->when($ignoreId !== null, fn ($query) => $query->where('handover.id', '<>', $ignoreId))
            ->sum(DB::raw('line.quantity - coalesce(received.received_quantity, 0)'));

        return $this->quantity((string) $quantity);
    }

    private function postedBySource(int $companyId): Builder
    {
        return DB::table('inventory_document_lines as receipt_line')->join('inventory_documents as receipt', 'receipt.id', '=', 'receipt_line.inventory_document_id')
            ->where('receipt.company_id', $companyId)->where('receipt.document_type', InventoryDocument::TypeProductionReceipt)
            ->where('receipt.source_document_type', InventoryDocument::class)->where('receipt.status', InventoryDocument::StatusPosted)
            ->where('receipt_line.source_line_type', InventoryDocumentLine::class)->whereNull('receipt_line.deleted_at')->whereNull('receipt.deleted_at')
            ->groupBy('receipt_line.source_line_id')->selectRaw('receipt_line.source_line_id, sum(receipt_line.quantity) as received_quantity');
    }

    private function lockCompany(Model $record): void
    {
        $this->context($record);
        Company::query()->whereKey($record->company_id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $properties */
    private function log(Model $document, string $event, array $properties = []): void
    {
        $this->audit->log(request(), 'production', $event, 'success', [
            'subject' => $document, 'company_id' => $document->company_id, 'properties_only' => true,
            'properties' => $properties,
        ]);
    }

    private function assertDate(string $date, ?string $minimum = null): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $date > now()->toDateString() || ($minimum !== null && $date < $minimum)) {
            throw new DomainException(__('production_handover.date_invalid'));
        }
    }

    private function reason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 1000) {
            throw new DomainException(__('cancellation_review.reason_required'));
        }
    }

    private function quantity(string $value): string
    {
        $amount = (string) BigDecimal::of($value)->toScale(8, RoundingMode::Unnecessary);
        if (bccomp($amount, '0', 8) < 0) {
            throw new DomainException(__('production_handover.invalid_line'));
        }

        return $amount;
    }

    /** @param Collection<int, InventoryDocumentLine> $lines */
    private function sum(Collection $lines, string $field): string
    {
        return $lines->reduce(fn (string $sum, InventoryDocumentLine $line): string => bcadd($sum, (string) $line->{$field}, 8), '0.00000000');
    }
}
