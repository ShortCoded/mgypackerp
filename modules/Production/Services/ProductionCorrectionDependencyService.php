<?php

namespace Modules\Production\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryLayerAllocation;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryLayerService;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Models\CustomerInvoice;

final class ProductionCorrectionDependencyService
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
    ) {}

    /**
     * @param  Collection<int, object>  $documents
     * @param  Collection<int, object>  $transactions
     * @return array<string, mixed>
     */
    public function snapshot(ProductionRun $run, Collection $documents, Collection $transactions, bool $lock = false): array
    {
        return [
            'inventory' => $this->inventorySnapshot($run, $documents, $transactions, $lock),
            'payroll' => $this->payrollSnapshot($run, $lock),
        ];
    }

    /** @param array<string, mixed> $snapshot @return list<array<string, mixed>> */
    public function steps(array $snapshot): array
    {
        $steps = collect($snapshot['inventory']['steps'] ?? [])->map(function (array $step): array {
            $permission = $step['permission'] ?? null;

            return [
                'doc_num' => $step['doc_num'],
                'action' => $step['action'],
                'context_label' => $step['context_label'],
                'source_url' => $step['source_url'],
                'correction_url' => $step['correction_url'],
                'permitted' => $permission === null || Gate::any((array) $permission),
            ];
        });

        foreach ($snapshot['payroll']['runs'] ?? [] as $payroll) {
            $payrollAccessible = $this->canAccessPayroll($payroll);
            foreach ($payroll['overhead_allocations'] as $allocation) {
                $url = route('admin.costing.overhead-allocation-run.index', ['run' => $allocation['public_id']]);
                if ($allocation['status'] === 'reversed' && $allocation['recovery_valid']) {
                    continue;
                }
                $steps->push([
                    'doc_num' => $allocation['doc_num'],
                    'action' => $allocation['status'] === 'posted' ? 'reverse_overhead_allocation' : 'repair_overhead_reversal_evidence',
                    'context_label' => __('production_run_correction.payroll_context', ['run' => $payroll['id']]),
                    'source_url' => $url,
                    'correction_url' => $url,
                    'permitted' => $allocation['status'] === 'posted' && $payrollAccessible
                        && (int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey) === (int) $allocation['financial_period_id']
                        && Gate::allows('costing.overhead_allocation_run.reverse'),
                ]);
            }

            foreach ($payroll['payments'] as $payment) {
                $voidedDraft = $payment['voucher_status'] === 'draft' && $payment['voucher_deleted_at'] !== null
                    && $payment['journal_entry_id'] === null;
                if ($payment['recovery_valid']) {
                    continue;
                }
                $url = route('admin.finance.cash-payment-vouchers.show', $payment['voucher_doc_num']);
                $steps->push([
                    'doc_num' => $payment['voucher_doc_num'],
                    'action' => ($payment['status'] === 'cancelled' || $payment['voucher_status'] === 'cancelled' || $voidedDraft)
                        ? 'repair_payroll_payment_reversal_evidence' : 'cancel_payroll_payment',
                    'context_label' => __('production_run_correction.payroll_context', ['run' => $payroll['id']]),
                    'source_url' => $url,
                    'correction_url' => $url,
                    'permitted' => $payment['status'] !== 'cancelled' && $payment['voucher_status'] !== 'cancelled' && ! $voidedDraft
                        && $payrollAccessible && Gate::allows('cash_payment_vouchers.cancel'),
                ]);
            }

            if ($payroll['status'] === 'posted') {
                $steps->push([
                    'doc_num' => __('production_run_correction.payroll_document', ['run' => $payroll['id']]),
                    'action' => 'correct_posted_payroll',
                    'context_label' => __('production_run_correction.independent_payroll_correction'),
                    'source_url' => route('admin.hr.payroll-preparation.index'),
                    'correction_url' => route('admin.hr.payroll-runs.corrections.index', $payroll['id']),
                    'permitted' => $payrollAccessible && Gate::any(['hr.payroll_approval.correct', 'hr.payroll_approval.correct_approve']),
                ]);
            } elseif ($payroll['status'] === 'reversed' && ! $payroll['correction_recovery_valid']) {
                $steps->push([
                    'doc_num' => __('production_run_correction.payroll_document', ['run' => $payroll['id']]),
                    'action' => 'repair_payroll_correction_evidence',
                    'context_label' => __('production_run_correction.payroll_reversal_invalid'),
                    'source_url' => route('admin.hr.payroll-runs.corrections.index', $payroll['id']),
                    'correction_url' => route('admin.hr.payroll-runs.corrections.index', $payroll['id']),
                    'permitted' => false,
                ]);
            } elseif ($payroll['status'] === 'under_review') {
                $steps->push([
                    'doc_num' => __('production_run_correction.payroll_document', ['run' => $payroll['id']]),
                    'action' => 'return_payroll_for_recalculation',
                    'context_label' => __('production_run_correction.reviewed_payroll_context'),
                    'source_url' => route('admin.hr.payroll-preparation.index'),
                    'correction_url' => route('admin.hr.payroll-preparation.index'),
                    'permitted' => $payrollAccessible && Gate::allows('hr.payroll_approval.review'),
                ]);
            } elseif ($payroll['status'] === 'calculated'
                && (! $payrollAccessible || ! Gate::allows('hr.payroll_preparation.calculate'))) {
                $steps->push([
                    'doc_num' => __('production_run_correction.payroll_document', ['run' => $payroll['id']]),
                    'action' => 'authorize_payroll_recalculation',
                    'context_label' => __('production_run_correction.payroll_recalculation_authority'),
                    'source_url' => route('admin.hr.payroll-preparation.index'),
                    'correction_url' => route('admin.hr.payroll-preparation.index'),
                    'permitted' => false,
                ]);
            } elseif (! in_array($payroll['status'], ['draft', 'calculated', 'reversed'], true)) {
                $steps->push([
                    'doc_num' => __('production_run_correction.payroll_document', ['run' => $payroll['id']]),
                    'action' => 'resolve_payroll_state',
                    'context_label' => __('production_run_correction.payroll_state_context', ['status' => $payroll['status']]),
                    'source_url' => route('admin.hr.payroll-preparation.index'),
                    'correction_url' => route('admin.hr.payroll-preparation.index'),
                    'permitted' => false,
                ]);
            }
        }

        return $steps->values()->all();
    }

    /** @param array<string, mixed> $snapshot @return list<int> */
    public function linkedPayrollRunIds(array $snapshot): array
    {
        return collect($snapshot['payroll']['runs'] ?? [])->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @param array<string, mixed> $snapshot */
    public function assertCanInvalidateCalculatedPayroll(array $snapshot): void
    {
        foreach ($snapshot['payroll']['runs'] ?? [] as $payroll) {
            if ($payroll['status'] === 'calculated'
                && (! $this->canAccessPayroll($payroll) || ! Gate::allows('hr.payroll_preparation.calculate'))) {
                throw new \DomainException(__('production_run_correction.payroll_recalculation_authority'));
            }
        }
    }

    /**
     * @param  Collection<int, object>  $documents
     * @param  Collection<int, object>  $transactions
     * @return array{positions: list<array<string, mixed>>, steps: list<array<string, mixed>>}
     */
    private function inventorySnapshot(ProductionRun $run, Collection $documents, Collection $transactions, bool $lock): array
    {
        $positions = [];
        $steps = [];
        foreach ($documents->where('status', InventoryDocument::StatusPosted)->where('document_type', InventoryDocument::TypeProductionReceipt) as $document) {
            foreach ($transactions->where('source_id', $document->id)->where('is_reversal', false) as $receipt) {
                if (bccomp((string) $receipt->quantity_in, '0', 8) <= 0) {
                    continue;
                }
                $lineage = app(InventoryLayerService::class)->receiptLineageTransactionIds((int) $receipt->id);
                $layers = DB::table('inventory_receipt_layers')->whereIn('receipt_transaction_id', $lineage)
                    ->where('branch_store_id', $receipt->branch_store_id)->where('product_id', $receipt->product_id)
                    ->where('stock_status', $receipt->stock_status)->where('batch_lot', $receipt->batch_lot)
                    ->where('warehouse_location_id', $receipt->warehouse_location_id)->orderBy('id')->get();
                $this->assertRecoveredCorrections($run, $layers, $lock);
                $remaining = $layers->reduce(
                    fn (string $sum, object $layer): string => bcadd($sum, (string) $layer->remaining_quantity, 8),
                    '0.00000000',
                );
                $positions[] = [
                    'receipt_transaction_id' => (int) $receipt->id,
                    'lineage_transaction_ids' => array_map('intval', $lineage),
                    'required_quantity' => (string) $receipt->quantity_in,
                    'remaining_quantity' => $remaining,
                    'layers' => $layers->map(fn (object $layer): array => (array) $layer)->all(),
                ];
                if (bccomp($remaining, (string) $receipt->quantity_in, 8) >= 0) {
                    continue;
                }
                $source = InventoryDocument::query()->where('company_id', $run->company_id)->findOrFail($document->id);
                foreach (app(InventoryMovementCorrectionService::class)->dependencySteps($source) as $dependency) {
                    $steps[$dependency['id']] = $this->inventoryStep($run, $dependency);
                }
            }
        }

        return ['positions' => $positions, 'steps' => array_values($steps)];
    }

    /** @param Collection<int, object> $layers */
    private function assertRecoveredCorrections(ProductionRun $run, Collection $layers, bool $lock): void
    {
        $layerIds = $layers->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($layerIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($layerIds), '?'));
        $sealedAllocationQuery = DB::getDriverName() === 'pgsql'
            ? "exists (select 1 from jsonb_array_elements(coalesce(execution_snapshot::jsonb->'allocations', '[]'::jsonb)) allocation where (allocation->>'inventory_receipt_layer_id')::bigint in ({$placeholders}))"
            : "exists (select 1 from json_each(execution_snapshot, '$.allocations') allocation where json_extract(allocation.value, '$.inventory_receipt_layer_id') in ({$placeholders}))";
        $sealedProposals = InventoryMovementCorrection::query()->where('company_id', $run->company_id)->where('status', 'approved')
            ->whereRaw($sealedAllocationQuery, $layerIds)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        foreach ($sealedProposals as $proposal) {
            app(InventoryMovementCorrectionService::class)->assertApproved($proposal);
        }
        $issueIds = InventoryLayerAllocation::query()->whereIn('inventory_receipt_layer_id', $layers->pluck('id'))
            ->pluck('issue_transaction_id')->unique();
        $documents = InventoryDocument::withTrashed()->where('company_id', $run->company_id)
            ->whereIn('id', InventoryTransaction::query()->whereIn('id', $issueIds)->where('is_reversal', false)
                ->where('source_type', InventoryDocument::class)->select('source_id'))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        foreach ($documents as $document) {
            $proposal = InventoryMovementCorrection::query()->where('company_id', $run->company_id)
                ->where('inventory_document_id', $document->id)->where('status', 'approved')->latest('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($proposal !== null) {
                app(InventoryMovementCorrectionService::class)->assertApproved($proposal);

                continue;
            }
            if ($document->status === InventoryDocument::StatusReversed
                && $document->document_type === InventoryDocument::TypeSalesDelivery
                && app(InventoryMovementCorrectionService::class)->supportsSource($document)) {
                throw new \DomainException(__('inventory_correction.stale'));
            }
        }
    }

    /** @param array<string, mixed> $dependency @return array<string, mixed> */
    private function inventoryStep(ProductionRun $run, array $dependency): array
    {
        $document = InventoryDocument::withTrashed()->where('company_id', $run->company_id)->findOrFail($dependency['id']);
        if ($document->document_type === InventoryDocument::TypeSalesDelivery) {
            $invoice = CustomerInvoice::query()->where('company_id', $run->company_id)
                ->where('status', '<>', CustomerInvoice::StatusCancelled)
                ->where(function ($query) use ($document): void {
                    $query->where('delivery_document_id', $document->id)
                        ->orWhereHas('deliveries', fn ($deliveries) => $deliveries->where('inventory_documents.id', $document->id))
                        ->orWhereHas('lines', fn ($lines) => $lines->whereIn('delivery_line_id', $document->lines()->select('id')));
                })->orderBy('id')->first();

            return [
                'doc_num' => $document->doc_num,
                'action' => $invoice === null ? 'recover_unbilled_sales_delivery' : 'correct_customer_invoice',
                'context_label' => $invoice?->doc_num ?? __('production_run_correction.unlinked_sales_delivery'),
                'source_url' => $dependency['source_url'],
                'correction_url' => $invoice === null ? $dependency['correction_url'] : route('admin.sales.sales-invoices.corrections.index', $invoice),
                'permission' => $invoice === null ? ['inventory.documents.correct_prepare', 'inventory.documents.correct_approve']
                    : ['customer_invoices.correct_prepare', 'customer_invoices.correct_approve'],
            ];
        }

        return [
            'doc_num' => $dependency['document'],
            'action' => 'correct_inventory_movement',
            'context_label' => __('inventory.movements.types.'.$document->document_type),
            'source_url' => $dependency['source_url'],
            'correction_url' => $dependency['correction_url'],
            'permission' => ['inventory.documents.correct_prepare', 'inventory.documents.correct_approve'],
        ];
    }

    /** @return array{runs: list<array<string, mixed>>} */
    private function payrollSnapshot(ProductionRun $run, bool $lock): array
    {
        $approvalIds = DB::table('production_piece_approvals')->where('production_run_id', $run->getKey())->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)->all();
        $items = DB::table('hr_payslip_items as item')
            ->join('hr_payslips as slip', 'slip.id', '=', 'item.payslip_id')
            ->join('hr_payroll_runs as payroll', 'payroll.id', '=', 'slip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'payroll.payroll_period_id')
            ->where('period.company_id', $run->company_id)
            ->where(fn ($query) => $query->whereNull('payroll.branch_id')->orWhere('payroll.branch_id', $run->branch_id))
            ->where('slip.company_id', $run->company_id)->where('slip.branch_id', $run->branch_id)
            ->when($run->actual_end_at !== null, fn ($query) => $query
                ->whereDate('period.period_start', '<=', $run->actual_end_at->toDateString())
                ->whereDate('period.period_end', '>=', $run->actual_end_at->toDateString()))
            ->whereNull('payroll.deleted_at')->whereNull('period.deleted_at')
            ->whereNotNull('item.source_snapshot')->orderBy('item.id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['item.id', 'item.payslip_id', 'item.source_snapshot', 'slip.payroll_run_id'])
            ->filter(fn (object $item): bool => $this->itemReferencesRun((string) $item->source_snapshot, (int) $run->getKey(), $approvalIds));
        $runIds = $items->pluck('payroll_run_id')->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values();
        if ($runIds->isEmpty()) {
            return ['runs' => []];
        }
        $payrollRuns = DB::table('hr_payroll_runs as payroll')->join('hr_payroll_periods as period', 'period.id', '=', 'payroll.payroll_period_id')
            ->whereIn('payroll.id', $runIds)->orderBy('payroll.id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['payroll.*', 'period.company_id', 'period.period_start', 'period.period_end']);
        $slips = DB::table('hr_payslips')->whereIn('payroll_run_id', $runIds)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $allItems = DB::table('hr_payslip_items')->whereIn('payslip_id', $slips->pluck('id'))->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($lock) {
            DB::table('hr_payroll_payments')->whereIn('payroll_run_id', $runIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $payments = DB::table('hr_payroll_payments as payment')
            ->leftJoin('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
            ->leftJoin('cashboxes as cashbox', 'cashbox.id', '=', 'voucher.cashbox_id')
            ->whereIn('payment.payroll_run_id', $runIds)->orderBy('payment.id')
            ->get(['payment.*', 'voucher.doc_num as voucher_doc_num', 'voucher.status as voucher_status', 'voucher.deleted_at as voucher_deleted_at',
                'voucher.company_id as voucher_company_id', 'voucher.voucher_type', 'cashbox.branch_id as voucher_branch_id']);
        $postings = DB::table('hr_payroll_postings')->whereIn('payroll_run_id', $runIds)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $corrections = DB::table('hr_payroll_corrections')->whereIn('payroll_run_id', $runIds)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $allocationRuns = DB::table('cost_overhead_allocation_runs as allocation')
            ->join('cost_overhead_allocation_sources as source', 'source.allocation_run_id', '=', 'allocation.id')
            ->join('journal_entry_lines as journal_line', 'journal_line.id', '=', 'source.journal_entry_line_id')
            ->whereIn('journal_line.journal_entry_id', $postings->pluck('journal_entry_id'))
            ->orderBy('allocation.id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['allocation.*'])->unique('id')->values();
        $allocationSources = DB::table('cost_overhead_allocation_sources as source')
            ->join('journal_entry_lines as journal_line', 'journal_line.id', '=', 'source.journal_entry_line_id')
            ->whereIn('source.allocation_run_id', $allocationRuns->pluck('id'))->orderBy('source.id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['source.*', 'journal_line.journal_entry_id as source_journal_entry_id']);
        $allocationLines = DB::table('cost_overhead_allocation_lines')->whereIn('allocation_run_id', $allocationRuns->pluck('id'))->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $journalIds = $postings->pluck('journal_entry_id')
            ->concat($payments->pluck('journal_entry_id'))->concat($payments->pluck('reversal_journal_entry_id'))
            ->concat($allocationRuns->pluck('journal_entry_id'))->concat($allocationRuns->pluck('reversal_journal_entry_id'))
            ->concat($corrections->pluck('original_journal_entry_id'))->concat($corrections->pluck('reversal_journal_entry_id'))
            ->concat($payrollRuns->pluck('reversal_journal_entry_id'))->filter()->unique()->values();
        $journals = DB::table('journal_entries')->whereIn('id', $journalIds)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $journalLines = DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();

        return ['runs' => $payrollRuns->map(function (object $payroll) use ($slips, $allItems, $payments, $postings, $corrections, $allocationRuns, $allocationSources, $allocationLines, $journals, $journalLines): array {
            $runSlips = $slips->where('payroll_run_id', $payroll->id);
            $posting = $postings->firstWhere('payroll_run_id', $payroll->id);
            $overheads = $posting === null ? collect() : $allocationRuns->filter(fn (object $allocation): bool => $allocationSources
                ->where('allocation_run_id', $allocation->id)->contains('source_journal_entry_id', $posting->journal_entry_id));
            $runCorrections = $corrections->where('payroll_run_id', $payroll->id);
            $approvedCorrection = $runCorrections->where('status', 'approved')->sortByDesc('id')->first();
            $runPayments = $payments->where('payroll_run_id', $payroll->id);

            return [
                ...(array) $payroll,
                'slips' => $runSlips->map(fn (object $row): array => (array) $row)->values()->all(),
                'items' => $allItems->whereIn('payslip_id', $runSlips->pluck('id'))->map(fn (object $row): array => (array) $row)->values()->all(),
                'payments' => $runPayments->map(function (object $payment) use ($payroll, $journals, $journalLines): array {
                    $row = (array) $payment;
                    $row['original_journal'] = $this->journalEvidence($payment->journal_entry_id, $journals, $journalLines);
                    $row['reversal_journal'] = $this->journalEvidence($payment->reversal_journal_entry_id, $journals, $journalLines);
                    $row['recovery_valid'] = $this->paymentRecoveryIsValid($row, (int) $payroll->company_id);

                    return $row;
                })->values()->all(),
                'posting' => $posting === null ? null : (array) $posting,
                'posting_journal' => $this->journalEvidence($posting?->journal_entry_id, $journals, $journalLines),
                'corrections' => $runCorrections->map(fn (object $row): array => (array) $row)->values()->all(),
                'approved_correction' => $approvedCorrection === null ? null : (array) $approvedCorrection,
                'correction_recovery_valid' => $this->payrollCorrectionRecoveryIsValid($payroll, $posting, $approvedCorrection, $journals, $journalLines),
                'overhead_allocations' => $overheads->map(function (object $allocation) use ($payroll, $allocationSources, $allocationLines, $journals, $journalLines): array {
                    $row = [
                        ...(array) $allocation,
                        'sources' => $allocationSources->where('allocation_run_id', $allocation->id)->map(fn (object $row): array => (array) $row)->values()->all(),
                        'lines' => $allocationLines->where('allocation_run_id', $allocation->id)->map(fn (object $row): array => (array) $row)->values()->all(),
                        'original_journal' => $this->journalEvidence($allocation->journal_entry_id, $journals, $journalLines),
                        'reversal_journal' => $this->journalEvidence($allocation->reversal_journal_entry_id, $journals, $journalLines),
                    ];
                    $row['recovery_valid'] = $allocation->status === 'reversed'
                        && (int) $allocation->company_id === (int) $payroll->company_id
                        && $this->inverseJournalIsValid($row['original_journal'], $row['reversal_journal'], (int) $allocation->company_id,
                            'overhead_allocation', 'overhead_allocation_reversal', (int) $allocation->id, (int) $allocation->id);

                    return $row;
                })->values()->all(),
            ];
        })->values()->all()];
    }

    /** @return array<string, mixed>|null */
    private function journalEvidence(mixed $journalId, Collection $journals, Collection $lines): ?array
    {
        if ($journalId === null) {
            return null;
        }
        $journal = $journals->firstWhere('id', $journalId);

        return $journal === null ? null : [
            'header' => (array) $journal,
            'lines' => $lines->where('journal_entry_id', $journalId)->map(fn (object $line): array => (array) $line)->values()->all(),
        ];
    }

    /** @param array<string, mixed> $payment */
    private function paymentRecoveryIsValid(array $payment, int $payrollCompanyId): bool
    {
        $sameOwner = (int) $payment['company_id'] === $payrollCompanyId
            && (int) $payment['voucher_company_id'] === $payrollCompanyId
            && (int) $payment['voucher_branch_id'] === (int) $payment['branch_id']
            && $payment['voucher_type'] === 'payment';
        $voidedDraft = $payment['status'] === 'cancelled' && $payment['voucher_status'] === 'draft'
            && $payment['voucher_deleted_at'] !== null && $payment['journal_entry_id'] === null
            && $payment['reversal_journal_entry_id'] === null;
        if ($voidedDraft) {
            return $sameOwner;
        }

        return $payment['status'] === 'cancelled' && $payment['voucher_status'] === 'cancelled'
            && $payment['voucher_deleted_at'] === null && $sameOwner
            && $payment['journal_entry_id'] !== null && $payment['reversal_journal_entry_id'] !== null
            && $this->inverseJournalIsValid(
                $payment['original_journal'],
                $payment['reversal_journal'],
                (int) $payment['company_id'],
                'hr_payroll_payment',
                'hr_payroll_payment_reversal',
                (int) $payment['id'],
                (int) $payment['id'],
            );
    }

    private function payrollCorrectionRecoveryIsValid(
        object $payroll,
        ?object $posting,
        ?object $correction,
        Collection $journals,
        Collection $lines,
    ): bool {
        if ($payroll->status !== 'reversed') {
            return false;
        }
        if ($posting === null || $correction === null || $correction->status !== 'approved'
            || (int) $correction->company_id !== (int) $payroll->company_id
            || (int) $correction->payroll_run_id !== (int) $payroll->id
            || (int) $correction->original_journal_entry_id !== (int) $posting->journal_entry_id
            || (int) $correction->reversal_journal_entry_id !== (int) $payroll->reversal_journal_entry_id
            || (int) $correction->prepared_by === (int) $correction->approved_by
            || $correction->approved_at === null || $payroll->reversed_at === null || $payroll->reversed_by === null) {
            return false;
        }
        $source = json_decode((string) $correction->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals((string) $correction->fingerprint, hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)))) {
            return false;
        }

        return $this->inverseJournalIsValid(
            $this->journalEvidence($posting->journal_entry_id, $journals, $lines),
            $this->journalEvidence($correction->reversal_journal_entry_id, $journals, $lines),
            (int) $payroll->company_id,
            'hr_payroll_run',
            'hr_payroll_run_reversal',
            (int) $correction->id,
            (int) $payroll->id,
        );
    }

    /**
     * @param  array<string, mixed>|null  $original
     * @param  array<string, mixed>|null  $reversal
     */
    private function inverseJournalIsValid(
        ?array $original,
        ?array $reversal,
        int $companyId,
        string $originalSourceType,
        string $reversalSourceType,
        int $reversalSourceId,
        ?int $originalSourceId = null,
    ): bool {
        if ($original === null || $reversal === null) {
            return false;
        }
        $originalHeader = $original['header'];
        $reversalHeader = $reversal['header'];
        if ((int) $originalHeader['company_id'] !== $companyId || (int) $reversalHeader['company_id'] !== $companyId
            || $originalHeader['source_type'] !== $originalSourceType
            || ($originalSourceId !== null && (int) $originalHeader['source_id'] !== $originalSourceId)
            || $originalHeader['status'] !== 'posted' || ! (bool) $originalHeader['is_posted']
            || (int) $originalHeader['reversed_entry_id'] !== (int) $reversalHeader['id']
            || $reversalHeader['source_type'] !== $reversalSourceType
            || (int) $reversalHeader['source_id'] !== $reversalSourceId
            || $reversalHeader['status'] !== 'posted' || ! (bool) $reversalHeader['is_posted']
            || $originalHeader['deleted_at'] !== null || $reversalHeader['deleted_at'] !== null) {
            return false;
        }
        $expected = collect($original['lines'])->map(fn (array $line): string => json_encode($this->journalLineSignature($line, true), JSON_THROW_ON_ERROR))->sort()->values()->all();
        $actual = collect($reversal['lines'])->map(fn (array $line): string => json_encode($this->journalLineSignature($line), JSON_THROW_ON_ERROR))->sort()->values()->all();

        return $expected !== [] && $expected === $actual;
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    private function journalLineSignature(array $line, bool $inverse = false): array
    {
        return [
            'account_id' => (int) $line['account_id'],
            'debit_amount' => bcadd((string) $line[$inverse ? 'credit_amount' : 'debit_amount'], '0', 4),
            'credit_amount' => bcadd((string) $line[$inverse ? 'debit_amount' : 'credit_amount'], '0', 4),
            'customer_id' => $line['customer_id'] === null ? null : (int) $line['customer_id'],
            'supplier_id' => $line['supplier_id'] === null ? null : (int) $line['supplier_id'],
            'employee_id' => $line['employee_id'] === null ? null : (int) $line['employee_id'],
            'bank_account_id' => $line['bank_account_id'] === null ? null : (int) $line['bank_account_id'],
            'cost_center_id' => $line['cost_center_id'] === null ? null : (int) $line['cost_center_id'],
            'department_id' => $line['department_id'] === null ? null : (int) $line['department_id'],
            'branch_id' => $line['branch_id'] === null ? null : (int) $line['branch_id'],
        ];
    }

    /** @param list<int> $approvalIds */
    private function itemReferencesRun(string $encoded, int $runId, array $approvalIds): bool
    {
        $snapshot = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $evidence = data_get($snapshot, 'accrual.evidence', []);
        if (in_array($runId, array_map('intval', (array) ($evidence['production_run_ids'] ?? [])), true)) {
            return true;
        }

        return collect($evidence['sources'] ?? [])->contains(function (mixed $source) use ($runId, $approvalIds): bool {
            return is_array($source) && ((int) ($source['production_run_id'] ?? 0) === $runId
                || in_array((int) ($source['piece_approval_id'] ?? 0), $approvalIds, true));
        });
    }

    /** @param array<string, mixed> $payroll */
    private function canAccessPayroll(array $payroll): bool
    {
        $company = $this->companies->currentCompany();
        $user = auth()->user();
        if ($company === null || $user === null || (int) $company->getKey() !== (int) $payroll['company_id']) {
            return false;
        }
        $branchAllowed = $payroll['branch_id'] === null
            ? $this->scope->hasUnrestrictedBranchAccess($user)
            : $this->scope->allowedBranchQuery($user, [$company->doc_num])->where('branches.id', $payroll['branch_id'])->exists();
        if (! $branchAllowed) {
            return false;
        }

        return $this->scope->hasUnrestrictedFinancialPeriodAccess($user)
            || $this->scope->allowedFinancialPeriodQuery($user, [$company->doc_num])
                ->whereDate('financial_periods.from_date', '<=', $payroll['period_start'])
                ->whereDate('financial_periods.to_date', '>=', $payroll['period_end'])->exists();
    }
}
