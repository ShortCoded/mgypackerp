<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryCostPolicy;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventorySerialIdentity;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;
use Modules\Sales\Services\SalesCycleAuditService;
use Modules\Sales\Services\SalesReturnCorrectionService;

final class InventoryMovementCorrectionService
{
    /** @return array<string, mixed> */
    public function preview(InventoryDocument $document): array
    {
        abort_unless(Gate::any(['inventory.documents.correct_prepare', 'inventory.documents.correct_approve']), 403);

        return DB::transaction(function () use ($document): array {
            $document = $this->source($document);
            $legacy = app(LegacyReceiptAllocationRepairService::class)->supports($document);
            $steps = $legacy ? [] : $this->dependencySteps($document);
            $snapshot = $this->snapshot($document, $steps, $legacy ? $this->preparedLegacyProposalId($document) : null);

            return ['document' => $document->load('lines.product'), 'steps' => $steps, 'source_fingerprint' => $this->digest($snapshot), 'legacy_repair' => $snapshot['legacy_repair'] ?? null,
                'target' => FinancialPeriod::query()->where('company_id', $document->company_id)
                    ->find(request()->session()->get(OperatingContextService::FinancialPeriodIdKey)),
                'reverse_only' => $document->document_type === InventoryDocument::TypeSalesDelivery,
                'history' => InventoryMovementCorrection::query()->with(['preparer', 'approver', 'replacementDocument'])
                    ->where('company_id', $document->company_id)->where('inventory_document_id', $document->id)->latest('id')->paginate(20)];
        });
    }

    /** @return list<array<string, mixed>> */
    public function dependencySteps(InventoryDocument $document): array
    {
        $ordered = $visiting = $visited = [];
        $walk = function (InventoryDocument $parent) use (&$walk, &$ordered, &$visiting, &$visited, $document): void {
            if (isset($visiting[$parent->id])) {
                throw new DomainException(__('inventory_correction.lineage'));
            }
            if (isset($visited[$parent->id])) {
                return;
            }
            $visiting[$parent->id] = true;
            $receiptIds = $parent->transactions()->where('is_reversal', false)->where('quantity_in', '>', 0)->pluck('id');
            $lineageIds = $receiptIds->flatMap(fn ($id): array => app(InventoryLayerService::class)->receiptLineageTransactionIds((int) $id))->unique();
            $issues = InventoryTransaction::query()->where('is_reversal', false)->whereIn('id', InventoryLayerAllocation::query()
                ->whereHas('layer', fn ($layer) => $layer->whereIn('receipt_transaction_id', $lineageIds))->select('issue_transaction_id'))
                ->where(fn ($query) => $query->where('source_type', '<>', InventoryDocument::class)->orWhere('source_id', '<>', $parent->id))
                ->orderBy('id')->get();
            foreach ($issues as $issue) {
                if ((int) $issue->company_id !== (int) $document->company_id || $issue->source_type !== InventoryDocument::class) {
                    throw new DomainException(__('inventory_correction.lineage'));
                }
                $dependent = InventoryDocument::withTrashed()->where('company_id', $document->company_id)->findOrFail($issue->source_id);
                if ($dependent->status !== InventoryDocument::StatusPosted) {
                    continue;
                }
                $this->assertScope($dependent);
                $walk($dependent);
                $ordered[$dependent->id] = ['id' => $dependent->id, 'kind' => 'inventory_document', 'document' => $dependent->doc_num,
                    'status' => $dependent->status, 'branch_id' => $dependent->branch_id, 'period_id' => $dependent->financial_period_id,
                    'source_url' => route('admin.inventory.documents.show', $dependent),
                    'correction_url' => $this->supportsSource($dependent) && Gate::any(['inventory.documents.correct_prepare', 'inventory.documents.correct_approve'])
                        ? route('admin.inventory.documents.corrections.index', $dependent) : null,
                    'source_owned' => ! $this->supportsSource($dependent),
                    'quantity_out' => $dependent->transactions()->where('is_reversal', false)->sum('quantity_out'),
                    'value_out' => $dependent->transactions()->where('is_reversal', false)->where('quantity_out', '>', 0)->sum('total_cost')];
            }
            unset($visiting[$parent->id]);
            $visited[$parent->id] = true;
        };
        $walk($document);

        return array_values($ordered);
    }

    /** @param array<string, mixed> $data */
    public function prepare(InventoryDocument $document, array $data): InventoryMovementCorrection
    {
        Gate::authorize('inventory.documents.correct_prepare');

        return DB::transaction(function () use ($document, $data): InventoryMovementCorrection {
            $document = $this->source($document);
            $legacy = ($data['operation'] ?? '') === 'repair_lineage';
            if ($legacy) {
                if (! app(LegacyReceiptAllocationRepairService::class)->supports($document)) {
                    throw new DomainException(__('inventory_correction.legacy_inconsistent'));
                }
            } else {
                $this->assertPostedSource($document);
            }
            if ($document->document_type === InventoryDocument::TypeSalesDelivery && ($data['operation'] ?? '') !== 'reverse') {
                throw new DomainException(__('inventory_correction.sales_delivery_replacement'));
            }
            $target = $this->target($document, (string) ($data['posting_date'] ?? ''));
            $steps = $legacy ? [] : $this->dependencySteps($document);
            if ($steps !== []) {
                throw new DomainException(__('inventory_correction.dependencies'));
            }
            if (! $legacy) {
                app(InventoryAccountingPostingService::class)->assertManualCorrectionAccounting($document);
            }
            $snapshot = $this->snapshot($document, $steps, $legacy ? $this->preparedLegacyProposalId($document) : null);
            if (! $this->matches((string) ($data['source_fingerprint'] ?? ''), $snapshot)
                || mb_strlen(trim((string) ($data['reason'] ?? ''))) < 5 || mb_strlen((string) $data['reason']) > 3000
                || ! in_array($data['operation'] ?? '', ['reverse', 'replace', 'repair_lineage'], true)
                || ($legacy && bccomp($snapshot['legacy_repair']['misplaced_quantity'], '0', 8) <= 0)) {
                throw new DomainException(__('inventory_correction.stale'));
            }
            $payload = $data['operation'] === 'replace' ? $this->payload($document, $data['lines'] ?? []) : [];
            $proposal = new InventoryMovementCorrection(['company_id' => $document->company_id, 'branch_id' => $document->branch_id,
                'inventory_document_id' => $document->id, 'source_financial_period_id' => $document->financial_period_id,
                'posting_financial_period_id' => $target->id, 'posting_date' => $data['posting_date'], 'operation' => $data['operation'],
                'reason' => trim($data['reason']), 'replacement_payload' => $payload, 'source_snapshot' => $snapshot,
                'source_fingerprint' => $this->digest($snapshot), 'prepared_by' => auth()->id(), 'status' => 'prepared']);
            $proposal->proposal_fingerprint = $this->digest($this->proposalData($proposal));
            $existing = InventoryMovementCorrection::query()->where('inventory_document_id', $document->id)->where('status', 'prepared')->lockForUpdate()->first();
            if ($existing !== null) {
                if ($this->matches($existing->proposal_fingerprint, $this->proposalData($proposal))) {
                    return $existing;
                }
                throw new DomainException(__('inventory_correction.reject_existing'));
            }
            $proposal->save();
            $this->audit($proposal, 'prepared');

            return $proposal;
        }, 3);
    }

    public function approve(InventoryDocument $document, int $proposalId, string $reason): InventoryMovementCorrection
    {
        Gate::authorize('inventory.documents.correct_approve');

        return DB::transaction(function () use ($document, $proposalId, $reason): InventoryMovementCorrection {
            $document = $this->source($document);
            $proposal = InventoryMovementCorrection::query()->where('company_id', $document->company_id)
                ->where('inventory_document_id', $document->id)->lockForUpdate()->findOrFail($proposalId);
            if ((int) $proposal->prepared_by === (int) auth()->id() || mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 3000) {
                throw new DomainException(__('inventory_correction.independent'));
            }
            $this->assertSeal($proposal);
            if ($proposal->status === 'approved') {
                $this->assertApproved($proposal);

                return $proposal;
            }
            $legacy = $proposal->operation === 'repair_lineage';
            if (! $legacy) {
                $this->assertPostedSource($document);
            }
            $steps = $legacy ? [] : $this->dependencySteps($document);
            if ($proposal->status !== 'prepared' || $steps !== [] || ! $this->matches($proposal->source_fingerprint, $this->snapshot($document, $steps, $legacy ? (int) $proposal->id : null))) {
                throw new DomainException(__('inventory_correction.stale'));
            }
            $this->target($document, $proposal->posting_date->toDateString(), (int) $proposal->posting_financial_period_id);
            if (! $legacy) {
                app(InventoryAccountingPostingService::class)->assertManualCorrectionAccounting($document);
            }
            $proposal->forceFill(['status' => 'applying', 'approved_by' => auth()->id(), 'approved_at' => now()->startOfSecond(), 'approval_reason' => trim($reason)]);
            $proposal->approval_fingerprint = $this->digest($this->approvalData($proposal));
            $proposal->save();
            if ($legacy) {
                $result = app(LegacyReceiptAllocationRepairService::class)->apply($document, $proposal);
                $proposal->forceFill(['status' => 'approved', 'execution_snapshot' => $result, 'execution_fingerprint' => $this->digest($result)]);
                $proposal->approval_fingerprint = $this->digest($this->approvalData($proposal));
                $proposal->save();
                $this->assertApproved($proposal);
                $this->audit($proposal, 'approved');

                return $proposal->refresh();
            }
            app(InventoryDocumentPostingService::class)->reverseForManualCorrection($document, (int) $proposal->id);
            $replacement = null;
            if ($proposal->operation === 'replace') {
                $header = $document->only(['company_id', 'branch_id', 'branch_store_id', 'branch_hall_id', 'warehouse_location_id',
                    'destination_branch_store_id', 'destination_warehouse_location_id', 'document_type', 'purpose', 'movement_reason',
                    'source_stock_status', 'destination_stock_status']);
                $header = [...$header, 'financial_period_id' => $proposal->posting_financial_period_id, 'document_date' => $proposal->posting_date->toDateString(), 'notes' => $proposal->reason];
                $replacement = app(InventoryMovementService::class)->createDraft($header, $this->replacementLines($document, $proposal));
                $proposal->forceFill(['replacement_document_id' => $replacement->id]);
                $proposal->approval_fingerprint = $this->digest($this->approvalData($proposal));
                $proposal->save();
                app(InventoryDocumentPostingService::class)->post($replacement);
            }
            $result = $this->executionSnapshot($proposal, $document->fresh(), $replacement?->fresh());
            $proposal->forceFill(['status' => 'approved', 'execution_snapshot' => $result, 'execution_fingerprint' => $this->digest($result)]);
            $proposal->approval_fingerprint = $this->digest($this->approvalData($proposal));
            $proposal->save();
            $this->assertApproved($proposal);
            $this->audit($proposal, 'approved');

            return $proposal->refresh();
        }, 3);
    }

    public function reject(InventoryDocument $document, int $proposalId): void
    {
        Gate::authorize('inventory.documents.correct_approve');
        DB::transaction(function () use ($document, $proposalId): void {
            $document = $this->source($document);
            $proposal = InventoryMovementCorrection::query()->where('inventory_document_id', $document->id)->lockForUpdate()->findOrFail($proposalId);
            $this->assertSeal($proposal);
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('inventory_correction.stale'));
            }
            $proposal->forceFill(['status' => 'rejected', 'rejected_at' => now()])->save();
            $this->audit($proposal, 'rejected');
        });
    }

    public function execution(int $proposalId, int $documentId): InventoryMovementCorrection
    {
        Gate::authorize('inventory.documents.correct_approve');
        $proposal = InventoryMovementCorrection::query()->where('status', 'applying')->lockForUpdate()->findOrFail($proposalId);
        if (DB::transactionLevel() < 1 || (int) $proposal->inventory_document_id !== $documentId
            || (int) $proposal->approved_by !== (int) auth()->id() || (int) $proposal->prepared_by === (int) auth()->id()) {
            throw new DomainException(__('inventory_correction.stale'));
        }
        $this->assertSeal($proposal);
        $document = $this->source(InventoryDocument::query()->findOrFail($documentId));
        $this->target($document, $proposal->posting_date->toDateString(), (int) $proposal->posting_financial_period_id);

        return $proposal;
    }

    public function correctionReceiptSource(InventoryTransaction $transaction): ?InventoryReceiptLayer
    {
        if (preg_match('/^inventory-document:(\d+):line:(\d+):in$/D', $transaction->posting_key, $postingMatch) !== 1
            || (int) $postingMatch[1] !== (int) $transaction->source_id) {
            throw new DomainException(__('inventory_correction.lineage'));
        }
        $line = InventoryDocumentLine::query()->where('inventory_document_id', $transaction->source_id)->findOrFail((int) $postingMatch[2]);
        $id = $line->product_snapshot['inventory_movement_correction']['proposal_id'] ?? null;
        if ($id === null) {
            return null;
        }
        $proposal = InventoryMovementCorrection::query()->findOrFail($id);
        $this->execution((int) $id, (int) $proposal->inventory_document_id);
        $originId = (int) ($line->product_snapshot['inventory_movement_correction']['line_id'] ?? 0);
        $approvedLine = collect($proposal->replacement_payload)->firstWhere('line_id', $originId);
        if ((int) $proposal->replacement_document_id !== (int) $transaction->source_id || $approvedLine === null
            || bccomp($approvedLine['quantity'], (string) $transaction->quantity_in, 8) !== 0
            || bccomp($approvedLine['unit_cost'] ?? '0', (string) ($transaction->unit_cost ?? '0'), 8) !== 0) {
            throw new DomainException(__('inventory_correction.stale'));
        }
        $original = InventoryTransaction::query()->where('source_type', InventoryDocument::class)->where('source_id', $proposal->inventory_document_id)
            ->where('posting_key', "inventory-document:{$proposal->inventory_document_id}:line:{$originId}:in")->where('is_reversal', false)->where('quantity_in', '>', 0)->sole();
        if ($original->inventory_serial_identity_id === null) {
            return null;
        }
        if ((int) $transaction->company_id !== (int) $original->company_id || (int) $transaction->product_id !== (int) $original->product_id
            || (int) $transaction->inventory_serial_identity_id !== (int) $original->inventory_serial_identity_id) {
            throw new DomainException(__('inventory_correction.lineage'));
        }

        return InventoryReceiptLayer::query()->where('receipt_transaction_id', $original->id)
            ->where('inventory_serial_identity_id', $original->inventory_serial_identity_id)->lockForUpdate()->sole();
    }

    public function assertApproved(InventoryMovementCorrection $proposal): void
    {
        $this->assertSeal($proposal);
        if ($proposal->status !== 'approved') {
            throw new DomainException(__('inventory_correction.stale'));
        }
        if ($proposal->operation === 'repair_lineage') {
            if (! $this->matches((string) $proposal->execution_fingerprint, $proposal->execution_snapshot ?? [])) {
                throw new DomainException(__('inventory_correction.stale'));
            }
            app(LegacyReceiptAllocationRepairService::class)->assertApproved($proposal);

            return;
        }
        $document = InventoryDocument::withTrashed()->where('company_id', $proposal->company_id)
            ->findOrFail($proposal->inventory_document_id);
        $replacement = $proposal->replacement_document_id === null ? null
            : InventoryDocument::withTrashed()->where('company_id', $proposal->company_id)->findOrFail($proposal->replacement_document_id);
        $current = $this->executionSnapshot($proposal, $document, $replacement);
        if (! $this->matches((string) $proposal->execution_fingerprint, $current)) {
            throw new DomainException(__('inventory_correction.stale'));
        }
        $this->assertExecutionEffects($proposal, $document);
        $this->assertSourceCounters($document);
    }

    private function source(InventoryDocument $document): InventoryDocument
    {
        $company = app(OperatingCompanyContextService::class)->requireCompanyId();
        Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
        $document = InventoryDocument::withTrashed()->where('company_id', $company)->lockForUpdate()->findOrFail($document->id);
        abort_unless((int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $document->branch_id, 404);
        $this->assertScope($document);

        return $document;
    }

    private function assertScope(InventoryDocument $document): void
    {
        $scope = app(OperatingScopeAccessService::class);
        $company = Company::query()->findOrFail($document->company_id);
        $adjustments = InventoryValueAdjustment::query()->where('company_id', $document->company_id)->where('status', InventoryValueAdjustment::StatusPosted)
            ->whereIn('id', InventoryValueAdjustmentLine::query()->whereIn('source_transaction_id', $document->transactions()->select('id'))->select('inventory_value_adjustment_id'))
            ->with('lines')->get();
        $periodIds = $adjustments->pluck('financial_period_id')->merge($adjustments->flatMap(fn ($adjustment) => $adjustment->lines->pluck('financial_period_id')))
            ->push($document->financial_period_id)->filter()->unique()->values();
        abort_unless($scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->whereIn('financial_periods.id', $periodIds)->count() === $periodIds->count(), 404);
        $branches = $document->transactions()->pluck('branch_id')->merge([$document->branch_id]);
        if ($document->destination_branch_store_id !== null) {
            $branches->push($document->destinationBranchStore?->branch_id);
        }
        $ids = $branches->filter()->unique()->values();
        $adjustmentBranches = $adjustments->flatMap(fn ($adjustment) => $adjustment->lines->pluck('branch_id'));
        $ids = $ids->merge($adjustmentBranches)->filter()->unique()->values();
        abort_unless($scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->whereIn('branches.id', $ids)->count() === $ids->count(), 404);
    }

    private function target(InventoryDocument $document, string $date, ?int $expected = null): FinancialPeriod
    {
        $source = FinancialPeriod::query()->where('company_id', $document->company_id)->lockForUpdate()->findOrFail($document->financial_period_id);
        $id = (int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey);
        $company = Company::query()->findOrFail($document->company_id);
        abort_unless(app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $id)->exists(), 404);
        $target = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $document->company_id, $date, expectedPeriodId: $id, lockForUpdate: true);
        if (($expected !== null && $id !== $expected) || $date < $document->document_date->toDateString()
            || ($source->is_closed && ($id === (int) $source->id || $target->from_date->toDateString() <= $source->to_date->toDateString()))) {
            throw new DomainException(__('inventory_correction.target'));
        }
        if ($id !== (int) $source->id) {
            Gate::authorize('inventory.documents.correct_later_period');
        }
        foreach ($document->transactions()->where('is_reversal', false)->pluck('branch_store_id')->unique() as $store) {
            app(InventoryCostPolicyService::class)->assertPostingDateAllowed((int) $document->company_id, (int) $store, $date);
        }

        return $target;
    }

    public function supportsSource(InventoryDocument $document): bool
    {
        if ($document->document_type === InventoryDocument::TypeSalesDelivery) {
            return $this->isUnbilledDelivery($document);
        }

        return in_array($document->document_type, InventoryDocument::manualMovementTypes(), true) && $document->source_document_type === null
            && $document->source_document_id === null
            && $document->production_order_id === null && $document->production_run_id === null && $document->production_run_batch_id === null
            && ! $document->lines()->whereNotNull('source_line_type')->exists();
    }

    private function isUnbilledDelivery(InventoryDocument $document): bool
    {
        if ($document->source_document_type !== SalesOrder::class || $document->source_document_id === null
            || $document->production_run_id !== null || $document->customerDeliveryReceipt()->exists()) {
            return false;
        }
        $order = SalesOrder::query()->where('company_id', $document->company_id)->find($document->source_document_id);
        if ($order === null || $order->customer_id !== $document->customer_id || $document->lines()->doesntExist()) {
            return false;
        }
        foreach ($document->lines as $line) {
            if ($line->source_line_type !== SalesOrderLine::class || ! $order->lines()->whereKey($line->source_line_id)
                ->where('product_id', $line->product_id)->exists()) {
                return false;
            }
        }

        return ! CustomerInvoice::query()->where('company_id', $document->company_id)->where('status', '<>', CustomerInvoice::StatusCancelled)
            ->where(fn ($query) => $query->where('delivery_document_id', $document->id)
                ->orWhereHas('deliveries', fn ($deliveries) => $deliveries->where('inventory_documents.id', $document->id))
                ->orWhereHas('lines', fn ($lines) => $lines->whereIn('delivery_line_id', $document->lines()->select('id'))))->exists();
    }

    private function assertPostedSource(InventoryDocument $document): void
    {
        if ($document->trashed() || ! $this->supportsSource($document) || $document->status !== InventoryDocument::StatusPosted) {
            throw new DomainException(__('inventory_correction.source'));
        }
        if ($document->document_type === InventoryDocument::TypeSalesDelivery) {
            foreach ($document->lines->groupBy('source_line_id') as $id => $lines) {
                $source = SalesOrderLine::query()->lockForUpdate()->findOrFail($id);
                $quantity = $lines->reduce(fn (string $sum, InventoryDocumentLine $line): string => bcadd($sum, (string) $line->transaction_quantity, 8), '0');
                $baseQuantity = $lines->reduce(fn (string $sum, InventoryDocumentLine $line): string => bcadd($sum, (string) $line->quantity, 8), '0');
                if (bccomp((string) $source->delivered_quantity, $quantity, 8) < 0
                    || bccomp((string) $source->delivered_base_quantity, $baseQuantity, 8) < 0) {
                    throw new DomainException(__('inventory_correction.lineage'));
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $input @return list<array<string, mixed>> */
    private function payload(InventoryDocument $document, array $input): array
    {
        $document->load('lines.product');
        $payload = [];
        $seen = [];
        foreach ($input as $row) {
            $line = $document->lines->firstWhere('id', $row['line_id'] ?? 0);
            if ($line === null || isset($seen[$line->id]) || preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', (string) ($row['quantity'] ?? '')) !== 1
                || (isset($row['unit_cost']) && preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', (string) $row['unit_cost']) !== 1)) {
                throw new DomainException(__('inventory_correction.payload'));
            }
            $seen[$line->id] = true;
            $quantity = bcadd((string) $row['quantity'], '0', 8);
            if (bccomp($quantity, '0', 8) === 0) {
                continue;
            }
            if ($line->product->tracks_serials && bccomp($quantity, '1', 8) !== 0) {
                throw new DomainException(__('inventory_correction.payload'));
            }
            $receiptCost = in_array($document->document_type, [InventoryDocument::TypeReceipt, InventoryDocument::TypeReturn, InventoryDocument::TypeAdjustmentIn], true);
            $payload[] = ['line_id' => (int) $line->id, 'quantity' => $quantity,
                'unit_cost' => $receiptCost ? (isset($row['unit_cost']) ? bcadd((string) $row['unit_cost'], '0', 8) : $line->unit_cost) : null];
        }
        if ($payload === []) {
            throw new DomainException(__('inventory_correction.payload'));
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function replacementLines(InventoryDocument $document, InventoryMovementCorrection $proposal): array
    {
        $output = [];
        foreach ($proposal->replacement_payload as $row) {
            $line = $document->lines()->with('product')->findOrFail($row['line_id']);
            $input = $line->only(['product_id', 'unit_id', 'transaction_unit_id', 'conversion_factor', 'warehouse_location_id',
                'destination_warehouse_location_id', 'batch_lot', 'manufacture_date', 'expiry_date', 'notes']);
            $input = [...$input, 'quantity' => $row['quantity'], 'transaction_quantity' => bcdiv($row['quantity'], (string) $line->conversion_factor, 8),
                'unit_cost' => $row['unit_cost'], 'product_snapshot' => ['doc_num' => $line->product->doc_num, 'name' => $line->product->name,
                    'inventory_movement_correction' => ['proposal_id' => (int) $proposal->id, 'line_id' => (int) $line->id]]];
            if ($line->product->tracks_serials) {
                $input['serial_number'] = InventorySerialIdentity::query()->findOrFail($line->inventory_serial_identity_id)->serial_number;
            }
            if ($line->selected_receipt_layer_id !== null) {
                $issue = $document->transactions()->where('is_reversal', false)->where('posting_key', "inventory-document:{$document->id}:line:{$line->id}:out")->where('quantity_out', '>', 0)->sole();
                $inverse = InventoryTransaction::query()->where('reversal_of_id', $issue->id)->where('is_reversal', true)->sole();
                $remaining = $row['quantity'];
                $restored = InventoryReceiptLayer::query()->where('receipt_transaction_id', $inverse->id)->where('remaining_quantity', '>', 0)->orderBy('id')->lockForUpdate()->get();
                if (! $line->product->tracks_serials) {
                    $selected = InventoryReceiptLayer::query()->where('company_id', $document->company_id)
                        ->where('branch_store_id', $document->branch_store_id)->where('product_id', $line->product_id)
                        ->where('remaining_quantity', '>', 0)->lockForUpdate()->find($line->selected_receipt_layer_id);
                    if ($selected !== null) {
                        $restored->push($selected);
                    }
                }
                foreach ($restored as $layer) {
                    $quantity = bccomp((string) $layer->remaining_quantity, $remaining, 8) < 0 ? (string) $layer->remaining_quantity : $remaining;
                    if (bccomp($quantity, '0', 8) <= 0) {
                        break;
                    }
                    $output[] = [...collect($input)->except('serial_number')->all(), 'quantity' => $quantity, 'transaction_quantity' => bcdiv($quantity, (string) $line->conversion_factor, 8),
                        'selected_receipt_layer_id' => $layer->id];
                    $remaining = bcsub($remaining, $quantity, 8);
                }
                if (bccomp($remaining, '0', 8) !== 0) {
                    throw new DomainException(__('inventory_correction.payload'));
                }
            } else {
                $output[] = $input;
            }
        }

        return $output;
    }

    /** @param list<array<string, mixed>> $steps @return array<string, mixed> */
    private function snapshot(InventoryDocument $document, array $steps, ?int $legacyProposalId = null): array
    {
        $ids = array_merge([$document->id], array_column($steps, 'id'));
        $transactions = InventoryTransaction::query()->where('source_type', InventoryDocument::class)->whereIn('source_id', $ids)->orderBy('id')->get();
        $products = $transactions->pluck('product_id')->unique();
        $stores = $transactions->pluck('branch_store_id')->unique();
        $layers = InventoryReceiptLayer::query()->where('company_id', $document->company_id)->whereIn('product_id', $products)->whereIn('branch_store_id', $stores)->orderBy('id')->get();
        $allocations = InventoryLayerAllocation::query()->where(fn ($q) => $q->whereIn('inventory_receipt_layer_id', $layers->modelKeys())->orWhereIn('issue_transaction_id', $transactions->modelKeys()))->orderBy('id')->get();
        $adjustments = InventoryValueAdjustment::query()->where('company_id', $document->company_id)->whereHas('lines', fn ($q) => $q->whereIn('source_transaction_id', $transactions->modelKeys()))->orderBy('id')->get();
        $journalIds = InventoryDocument::query()->whereIn('id', $ids)->pluck('journal_entry_id')->merge($adjustments->pluck('journal_entry_id'))->filter();

        $order = $document->source_document_type === SalesOrder::class
            ? SalesOrder::query()->with('lines')->findOrFail($document->source_document_id) : null;

        return ['legacy_repair' => app(LegacyReceiptAllocationRepairService::class)->supports($document) ? app(LegacyReceiptAllocationRepairService::class)->plan($document, $legacyProposalId) : null,
            'sales_order' => $order === null ? null : ['header' => $order->getAttributes(), 'lines' => $order->lines->sortBy('id')->map->getAttributes()->values()->all()],
            'document' => $document->getAttributes(), 'lines' => $document->lines()->orderBy('id')->get()->map->getAttributes()->all(),
            'steps' => $steps, 'transactions' => $transactions->map->getAttributes()->all(), 'layers' => $layers->map->getAttributes()->all(),
            'allocations' => $allocations->map->getAttributes()->all(), 'adjustments' => $adjustments->map->getAttributes()->all(),
            'adjustment_lines' => InventoryValueAdjustmentLine::query()->whereIn('inventory_value_adjustment_id', $adjustments->modelKeys())->orderBy('id')->get()->map->getAttributes()->all(),
            'serials' => InventorySerialIdentity::query()->whereIn('id', $layers->pluck('inventory_serial_identity_id')->filter())->orderBy('id')->get()->map->getAttributes()->all(),
            'policies' => InventoryCostPolicy::query()->where('company_id', $document->company_id)->orderBy('id')->get()->map->getAttributes()->all(),
            'periods' => FinancialPeriod::query()->whereIn('id', [$document->financial_period_id, request()->session()->get(OperatingContextService::FinancialPeriodIdKey)])->orderBy('id')->get()->map->getAttributes()->all(),
            'journals' => JournalEntry::query()->whereIn('id', $journalIds)->orderBy('id')->get()->map(fn ($journal): array => ['header' => $journal->getAttributes(), 'lines' => $journal->lines()->orderBy('line_no')->get()->map->getAttributes()->all()])->all()];
    }

    private function preparedLegacyProposalId(InventoryDocument $document): ?int
    {
        $proposal = InventoryMovementCorrection::query()->where('company_id', $document->company_id)
            ->where('inventory_document_id', $document->id)->where('operation', 'repair_lineage')
            ->where('status', 'prepared')->lockForUpdate()->first();

        return $proposal === null ? null : (int) $proposal->id;
    }

    /** Capture immutable correction effects without freezing consumable layer balances or live order counters.
     * @return array<string, mixed>
     */
    private function executionSnapshot(InventoryMovementCorrection $proposal, InventoryDocument $document, ?InventoryDocument $replacement): array
    {
        $transactions = InventoryTransaction::query()->where('source_type', InventoryDocument::class)
            ->where('source_id', $document->id)->orderBy('id')->get();
        if ($replacement !== null) {
            $transactions = $transactions->concat(InventoryTransaction::query()->where('source_type', InventoryDocument::class)
                ->where('source_id', $replacement->id)->where('is_reversal', false)->orderBy('id')->get())->sortBy('id')->values();
        }
        $reversalIds = $transactions->where('source_id', $document->id)->where('is_reversal', true)
            ->where('quantity_in', '>', 0)->pluck('id');
        $journalIds = collect($proposal->source_snapshot['journals'] ?? [])->pluck('header.id')
            ->merge([$document->journal_entry_id, $document->reversal_journal_entry_id, $replacement?->journal_entry_id])
            ->merge(JournalEntry::query()->where('company_id', $proposal->company_id)
                ->where('source_type', 'inventory_document_cost_completion_reversal')->where('source_id', $document->id)->pluck('id'))
            ->filter()->unique()->values();
        $journals = JournalEntry::withTrashed()->whereIn('id', $journalIds)->orderBy('id')->get();
        $allocations = InventoryLayerAllocation::query()->whereIn('issue_transaction_id', $transactions->pluck('id'))
            ->orderBy('id')->get();

        return [
            'source' => $document->getAttributes(),
            'replacement' => $replacement === null ? null : collect($replacement->getAttributes())->except([
                'status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'updated_by', 'updated_at',
            ])->all(),
            'transactions' => $transactions->map->getAttributes()->all(),
            'allocations' => $allocations->map->getAttributes()->all(),
            'restored_layers' => InventoryReceiptLayer::query()->whereIn('receipt_transaction_id', $reversalIds)->orderBy('id')->get()
                ->map(fn (InventoryReceiptLayer $layer): array => collect($layer->getAttributes())->except(['remaining_quantity', 'updated_at'])->all())->all(),
            'journals' => $journals->map(fn (JournalEntry $journal): array => [
                'header' => (int) $journal->id === (int) $replacement?->journal_entry_id
                    ? collect($journal->getAttributes())->except(['reversed_entry_id', 'updated_at'])->all()
                    : $journal->getAttributes(),
                'lines' => $journal->lines()->orderBy('line_no')->get()->map->getAttributes()->all(),
            ])->all(),
        ];
    }

    private function assertExecutionEffects(InventoryMovementCorrection $proposal, InventoryDocument $document): void
    {
        if ($document->status !== InventoryDocument::StatusReversed
            || (int) $document->reversed_by !== (int) $proposal->approved_by
            || $document->reversed_at === null
            || $document->reversal_reason !== $proposal->reason) {
            throw new DomainException(__('inventory_correction.stale'));
        }
        foreach ($document->transactions()->where('is_reversal', false)->get() as $source) {
            $inverse = InventoryTransaction::query()->where('reversal_of_id', $source->id)->where('is_reversal', true)->sole();
            $quantity = bccomp((string) $source->quantity_in, '0', 8) > 0 ? (string) $source->quantity_in : (string) $source->quantity_out;
            $completedCost = $source->completedTotalCost();
            $expectedUnitCost = $completedCost === null ? null : bcdiv($completedCost, $quantity, 8);
            if ((int) $inverse->company_id !== (int) $source->company_id
                || (int) $inverse->source_id !== (int) $source->source_id
                || $inverse->source_type !== $source->source_type
                || (int) $inverse->financial_period_id !== (int) $proposal->posting_financial_period_id
                || $inverse->transaction_date->toDateString() !== $proposal->posting_date->toDateString()
                || $inverse->posting_key !== $source->posting_key.':reversal'
                || bccomp((string) $inverse->quantity_in, (string) $source->quantity_out, 8) !== 0
                || bccomp((string) $inverse->quantity_out, (string) $source->quantity_in, 8) !== 0
                || ! $this->sameAmount($inverse->unit_cost, $expectedUnitCost, 8)
                || ! $this->sameAmount($inverse->total_cost, $completedCost, 8)) {
                throw new DomainException(__('inventory_correction.stale'));
            }
            if (bccomp((string) $source->quantity_out, '0', 8) <= 0) {
                continue;
            }
            $layers = InventoryReceiptLayer::query()->where('receipt_transaction_id', $inverse->id)->get();
            $allocationIds = $layers->pluck('source_allocation_id')->filter()->all();
            if (count($allocationIds) !== $layers->count()
                || InventoryLayerAllocation::query()->whereIn('id', $allocationIds)->where('issue_transaction_id', '<>', $source->id)->exists()
                || bccomp($layers->reduce(fn (string $sum, InventoryReceiptLayer $layer): string => bcadd($sum, (string) $layer->original_quantity, 8), '0'), (string) $source->quantity_out, 8) !== 0
                || bccomp($layers->reduce(fn (string $sum, InventoryReceiptLayer $layer): string => bcadd($sum, (string) $layer->source_allocation_cost_snapshot, 8), '0'), (string) ($inverse->total_cost ?? '0'), 8) !== 0) {
                throw new DomainException(__('inventory_correction.stale'));
            }
            $sourceAllocations = InventoryLayerAllocation::query()->with('layer')->whereIn('id', $allocationIds)->get()->keyBy('id');
            foreach ($layers as $layer) {
                $allocation = $sourceAllocations->get($layer->source_allocation_id);
                if ($allocation === null || $allocation->layer === null
                    || ! $this->sameAmount($layer->original_quantity, $allocation->quantity, 8)
                    || ! $this->sameAmount($layer->source_allocation_cost_snapshot, $allocation->completedTotalCost(), 8)
                    || $layer->original_receipt_date->toDateString() !== $allocation->layer->original_receipt_date->toDateString()
                    || $layer->inventory_serial_identity_id !== $allocation->layer->inventory_serial_identity_id) {
                    throw new DomainException(__('inventory_correction.stale'));
                }
            }
        }
        app(InventoryAccountingPostingService::class)->assertCorrectionCompletionReversal($document);
        if ($document->journal_entry_id !== null) {
            if ($document->reversal_journal_entry_id === null) {
                throw new DomainException(__('inventory_correction.stale'));
            }
            app(SalesReturnCorrectionService::class)->assertInverse(
                JournalEntry::withTrashed()->findOrFail($document->journal_entry_id),
                JournalEntry::withTrashed()->findOrFail($document->reversal_journal_entry_id),
                (int) $proposal->posting_financial_period_id,
                $proposal->posting_date->toDateString(),
            );
        }
    }

    private function assertSourceCounters(InventoryDocument $document): void
    {
        if ($document->document_type !== InventoryDocument::TypeSalesDelivery) {
            return;
        }
        foreach ($document->lines()->where('source_line_type', SalesOrderLine::class)->pluck('source_line_id')->unique() as $lineId) {
            $line = SalesOrderLine::query()->findOrFail($lineId);
            $activeInvoices = CustomerInvoiceLine::query()->where('sales_order_line_id', $line->id)->whereHas('invoice', fn ($query) => $query
                ->where('document_type', CustomerInvoice::TypeInvoice)->where('status', '<>', CustomerInvoice::StatusCancelled)
                ->where('posting_status', '<>', 'reversed')->whereDoesntHave('creditNotes', fn ($credit) => $credit
                ->where('source_type', CustomerInvoiceCorrection::class)->where('posting_status', 'posted')))->get();
            foreach (['quantity' => 'invoiced_quantity', 'base_quantity' => 'invoiced_base_quantity'] as $quantity => $counter) {
                $expected = $activeInvoices->reduce(fn (string $sum, CustomerInvoiceLine $invoiceLine): string => bcadd($sum, (string) $invoiceLine->{$quantity}, 8), '0');
                if (bccomp((string) $line->{$counter}, $expected, 8) !== 0) {
                    throw new DomainException(__('inventory_correction.stale'));
                }
            }
            $deliveries = DB::table('inventory_document_lines as line')->join('inventory_documents as document', 'document.id', '=', 'line.inventory_document_id')
                ->where('line.source_line_type', SalesOrderLine::class)->where('line.source_line_id', $line->id)
                ->where('document.status', InventoryDocument::StatusPosted)->where('document.document_type', InventoryDocument::TypeSalesDelivery)
                ->whereNull('line.deleted_at')->whereNull('document.deleted_at')->get(['line.transaction_quantity', 'line.quantity']);
            $returns = SalesReturnLine::query()->where('sales_order_line_id', $line->id)->whereNull('customer_invoice_line_id')
                ->whereHas('salesReturn', fn ($query) => $query->whereIn('status', [SalesReturn::StatusReceived, SalesReturn::StatusInspected, SalesReturn::StatusClosed]))->get();
            foreach (['transaction_quantity' => ['delivered_quantity', 'quantity'], 'quantity' => ['delivered_base_quantity', 'base_quantity']] as $quantity => [$counter, $returned]) {
                $expected = bcsub($deliveries->reduce(fn (string $sum, object $delivery): string => bcadd($sum, (string) $delivery->{$quantity}, 8), '0'),
                    $returns->reduce(fn (string $sum, SalesReturnLine $return): string => bcadd($sum, (string) $return->{$returned}, 8), '0'), 8);
                if (bccomp((string) $line->{$counter}, $expected, 8) !== 0) {
                    throw new DomainException(__('inventory_correction.stale'));
                }
            }
        }
    }

    private function sameAmount(mixed $actual, mixed $expected, int $scale): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === null && $expected === null;
        }

        return bccomp((string) $actual, (string) $expected, $scale) === 0;
    }

    /** @return array<string, mixed> */
    private function proposalData(InventoryMovementCorrection $proposal): array
    {
        return $proposal->only(['company_id', 'branch_id', 'inventory_document_id', 'source_financial_period_id', 'posting_financial_period_id',
            'operation', 'reason', 'prepared_by', 'replacement_payload', 'source_fingerprint']) + ['posting_date' => $proposal->posting_date->toDateString()];
    }

    /** @return array<string, mixed> */
    private function approvalData(InventoryMovementCorrection $proposal): array
    {
        return ['proposal' => $proposal->proposal_fingerprint, 'approver' => $proposal->approved_by, 'date' => $proposal->approved_at?->toISOString(),
            'reason' => $proposal->approval_reason, 'replacement' => $proposal->replacement_document_id, 'execution' => $proposal->execution_fingerprint];
    }

    private function assertSeal(InventoryMovementCorrection $proposal): void
    {
        if (! $this->matches($proposal->source_fingerprint, $proposal->source_snapshot)
            || ! $this->matches($proposal->proposal_fingerprint, $this->proposalData($proposal))
            || (in_array($proposal->status, ['applying', 'approved'], true) && ($proposal->approved_at === null
                || (int) $proposal->prepared_by === (int) $proposal->approved_by || ! $this->matches((string) $proposal->approval_fingerprint, $this->approvalData($proposal))))
            || ($proposal->status === 'approved' && (! is_array($proposal->execution_snapshot)
                || ! $this->matches((string) $proposal->execution_fingerprint, $proposal->execution_snapshot)))) {
            throw new DomainException(__('inventory_correction.stale'));
        }
    }

    /** @param array<string, mixed> $data */
    private function digest(array $data, ?string $key = null): string
    {
        return hash_hmac('sha256', 'mgypack.inventory-movement-correction.v1|'.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $key ?? (string) config('app.key'));
    }

    /** @param array<string, mixed> $data */
    private function matches(string $seal, array $data): bool
    {
        foreach (array_merge([(string) config('app.key')], config('app.previous_keys', [])) as $key) {
            if (hash_equals($seal, $this->digest($data, $key))) {
                return true;
            }
        }

        return false;
    }

    private function audit(InventoryMovementCorrection $proposal, string $event): void
    {
        app(SalesCycleAuditService::class)->record($proposal, 'inventory.correction_'.$event,
            ['document_id' => $proposal->inventory_document_id, 'operation' => $proposal->operation, 'replacement_id' => $proposal->replacement_document_id]);
    }
}
