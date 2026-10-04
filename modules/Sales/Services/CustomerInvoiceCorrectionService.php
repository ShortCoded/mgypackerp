<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryAccountingPostingService;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Sales\Models\CustomerCreditAllocation;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesRequestLine;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnLine;

final class CustomerInvoiceCorrectionService
{
    public function __construct(private readonly SalesCycleAuditService $audit) {}

    /** @return array<string,mixed> */
    public function preview(CustomerInvoice $invoice): array
    {
        abort_unless(Gate::any(['customer_invoices.correct_prepare', 'customer_invoices.correct_approve']), 403);

        return DB::transaction(function () use ($invoice): array {
            $invoice = $this->scoped($invoice);
            $snapshot = $this->snapshot($invoice);
            $approved = null;
            if ($invoice->hasApprovedCorrection()) {
                $credit = $invoice->creditNotes()->where('source_type', CustomerInvoiceCorrection::class)
                    ->where('posting_status', 'posted')->sole();
                $approved = CustomerInvoiceCorrection::query()->where('company_id', $invoice->company_id)->findOrFail($credit->source_id);
                $this->assertApproved($approved);
            }

            return ['invoice' => $invoice, 'snapshot' => $snapshot, 'fingerprint' => $this->digest($snapshot),
                'dependencies' => $approved === null ? $this->dependencies($snapshot) : [],
                'target' => FinancialPeriod::query()->where('company_id', $invoice->company_id)
                    ->find(request()->session()->get(OperatingContextService::FinancialPeriodIdKey)),
                'history' => CustomerInvoiceCorrection::query()->with(['preparer', 'approver'])->where('company_id', $invoice->company_id)
                    ->where('customer_invoice_id', $invoice->id)->latest('id')->paginate(20)];
        });
    }

    /** @param array<string,mixed> $data */
    public function prepare(CustomerInvoice $invoice, array $data): CustomerInvoiceCorrection
    {
        Gate::authorize('customer_invoices.correct_prepare');

        return DB::transaction(function () use ($invoice, $data): CustomerInvoiceCorrection {
            $invoice = $this->scoped($invoice);
            $snapshot = $this->snapshot($invoice);
            $target = $this->target($snapshot, (string) ($data['posting_date'] ?? ''));
            if (! hash_equals($this->digest($snapshot), (string) ($data['source_fingerprint'] ?? ''))
                || trim((string) ($data['reason'] ?? '')) === '' || mb_strlen((string) $data['reason']) > 3000
                || trim((string) ($data['recovery_reference'] ?? '')) === '' || mb_strlen((string) $data['recovery_reference']) > 255) {
                throw new DomainException(__('invoice_correction.stale'));
            }
            $this->assertCorrectable($snapshot);
            $proposal = new CustomerInvoiceCorrection(['company_id' => $invoice->company_id, 'branch_id' => $invoice->branch_id,
                'customer_invoice_id' => $invoice->id, 'posting_financial_period_id' => $target->id, 'posting_date' => $data['posting_date'],
                'reason' => trim($data['reason']), 'recovery_reference' => trim($data['recovery_reference']), 'source_snapshot' => $snapshot,
                'source_fingerprint' => $this->digest($snapshot), 'prepared_by' => auth()->id(), 'status' => 'prepared']);
            $proposal->proposal_fingerprint = $this->proposalDigest($proposal);
            $pending = CustomerInvoiceCorrection::query()->where('company_id', $invoice->company_id)->where('status', 'prepared')->lockForUpdate()->get();
            foreach ($pending as $other) {
                if (array_intersect(array_column($other->source_snapshot['invoices'], 'id'), array_column($snapshot['invoices'], 'id')) !== []) {
                    $this->assertSeal($other);
                    if (hash_equals($other->proposal_fingerprint, $proposal->proposal_fingerprint)) {
                        return $other;
                    }
                    throw new DomainException(__('invoice_correction.pending'));
                }
            }
            $proposal->save();
            $this->audit->record($proposal, 'customer_invoice.correction_prepared', ['invoice' => $invoice->doc_num,
                'invoices' => array_column($snapshot['invoices'], 'doc_num'), 'deliveries' => array_column($snapshot['documents'], 'doc_num')]);

            return $proposal;
        }, 3);
    }

    public function approve(CustomerInvoice $invoice, int $id, string $reason): CustomerInvoiceCorrection
    {
        Gate::authorize('customer_invoices.correct_approve');

        return DB::transaction(function () use ($invoice, $id, $reason): CustomerInvoiceCorrection {
            $invoice = $this->scoped($invoice);
            $proposal = CustomerInvoiceCorrection::query()->where('company_id', $invoice->company_id)->where('customer_invoice_id', $invoice->id)
                ->lockForUpdate()->findOrFail($id);
            if ((int) $proposal->prepared_by === (int) auth()->id() || trim($reason) === '' || mb_strlen($reason) > 3000) {
                throw new DomainException(__('invoice_correction.independent'));
            }
            $this->assertSeal($proposal);
            $this->authorizePeriods($proposal->source_snapshot);
            if ($proposal->status === 'approved') {
                $this->assertApproved($proposal);

                return $proposal;
            }
            $snapshot = $this->snapshot($invoice);
            if ($proposal->status !== 'prepared' || ! hash_equals($proposal->source_fingerprint, $this->digest($snapshot))) {
                throw new DomainException(__('invoice_correction.stale'));
            }
            $this->target($snapshot, $proposal->posting_date->toDateString(), (int) $proposal->posting_financial_period_id);
            $this->assertCorrectable($snapshot);
            $proposal->forceFill(['status' => 'applying', 'approved_by' => auth()->id(), 'approved_at' => now()->startOfSecond(), 'approval_reason' => trim($reason)]);
            $proposal->approval_fingerprint = $this->approvalDigest($proposal);
            $proposal->save();
            $credits = [];
            foreach ($snapshot['invoices'] as $row) {
                $credits[] = $this->credit(CustomerInvoice::query()->lockForUpdate()->findOrFail($row['id']), $proposal)->id;
            }
            foreach ($snapshot['documents'] as $row) {
                app(InventoryDocumentPostingService::class)->reverseForInvoiceCorrection(
                    InventoryDocument::query()->lockForUpdate()->findOrFail($row['id']), (int) $proposal->id);
            }
            foreach ($snapshot['invoices'] as $row) {
                $sourceInvoice = CustomerInvoice::query()->with('lines')->lockForUpdate()->findOrFail($row['id']);
                $this->releaseSourceQuantities($sourceInvoice);
                $sourceInvoice->issueOrder()->update(['status' => SalesIssueOrder::StatusCorrected]);
            }
            $effect = $this->executionSnapshot($proposal, $credits);
            $proposal->forceFill(['status' => 'approved', 'execution_snapshot' => $effect, 'execution_fingerprint' => $this->digest($effect)]);
            $proposal->approval_fingerprint = $this->approvalDigest($proposal);
            $proposal->save();
            $this->audit->record($proposal, 'customer_invoice.correction_approved', ['invoice' => $invoice->doc_num,
                'credit_note_ids' => $credits, 'recovery_reference' => $proposal->recovery_reference]);

            return $proposal->refresh();
        }, 3);
    }

    public function reject(CustomerInvoice $invoice, int $id): void
    {
        Gate::authorize('customer_invoices.correct_approve');
        DB::transaction(function () use ($invoice, $id): void {
            $invoice = $this->scoped($invoice);
            $proposal = CustomerInvoiceCorrection::query()->where('company_id', $invoice->company_id)
                ->where('customer_invoice_id', $invoice->id)->lockForUpdate()->findOrFail($id);
            $this->assertSeal($proposal);
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('invoice_correction.stale'));
            }
            $proposal->forceFill(['status' => 'rejected', 'rejected_at' => now()])->save();
            $this->audit->record($proposal, 'customer_invoice.correction_rejected');
        });
    }

    public function execution(int $id, int $documentId): CustomerInvoiceCorrection
    {
        Gate::authorize('customer_invoices.correct_approve');
        if (DB::transactionLevel() < 1) {
            throw new DomainException(__('invoice_correction.stale'));
        }
        $proposal = CustomerInvoiceCorrection::query()->where('status', 'applying')->lockForUpdate()->findOrFail($id);
        $this->scoped(CustomerInvoice::query()->findOrFail($proposal->customer_invoice_id));
        $this->assertSeal($proposal);
        $source = collect($proposal->source_snapshot['documents'])->firstWhere('id', $documentId);
        if ($source === null || $source['document_type'] !== InventoryDocument::TypeSalesDelivery
            || (int) $proposal->approved_by !== (int) auth()->id()) {
            throw new DomainException(__('invoice_correction.stale'));
        }
        $this->target($proposal->source_snapshot, $proposal->posting_date->toDateString(), (int) $proposal->posting_financial_period_id);

        return $proposal;
    }

    public function assertApproved(CustomerInvoiceCorrection $proposal): void
    {
        $this->assertSeal($proposal);
        if ($proposal->status !== 'approved') {
            throw new DomainException(__('invoice_correction.stale'));
        }
        $creditIds = array_column($proposal->execution_snapshot['credits'], 'id');
        $current = $this->executionSnapshot($proposal, $creditIds);
        if (! hash_equals($proposal->execution_fingerprint, $this->digest($current))) {
            throw new DomainException(__('invoice_correction.stale'));
        }
        $this->assertSourceCounters($proposal);
        foreach ($proposal->source_snapshot['invoices'] as $source) {
            $credit = CustomerInvoice::query()->whereIn('id', $creditIds)->where('original_invoice_id', $source['id'])->sole();
            if ($credit->source_type !== CustomerInvoiceCorrection::class || (int) $credit->source_id !== (int) $proposal->id
                || bccomp($credit->total_amount, $source['total_amount'], 4) !== 0) {
                throw new DomainException(__('invoice_correction.stale'));
            }
            app(SalesReturnCorrectionService::class)->assertInverse(JournalEntry::query()->findOrFail($source['journal_entry_id']),
                $credit->journalEntry, (int) $proposal->posting_financial_period_id, $proposal->posting_date->toDateString());
        }
        foreach ($proposal->source_snapshot['documents'] as $source) {
            $document = InventoryDocument::query()->findOrFail($source['id']);
            app(InventoryAccountingPostingService::class)->assertCorrectionCompletionReversal($document);
            app(SalesReturnCorrectionService::class)->assertInverse(JournalEntry::query()->findOrFail($source['journal_entry_id']),
                $document->reversalJournalEntry, (int) $proposal->posting_financial_period_id, $proposal->posting_date->toDateString());
            foreach ($document->transactions()->where('is_reversal', false)->get() as $issue) {
                $inverse = InventoryTransaction::query()->where('reversal_of_id', $issue->id)->where('is_reversal', true)->sole();
                $layers = InventoryReceiptLayer::query()->where('receipt_transaction_id', $inverse->id)->get();
                if (bccomp($layers->reduce(fn ($sum, $layer) => bcadd($sum, $layer->original_quantity, 8), '0'), $issue->quantity_out, 8) !== 0
                    || $layers->contains(fn ($layer) => $layer->source_allocation_id === null)
                    || bccomp($layers->reduce(fn ($sum, $layer) => bcadd($sum, (string) $layer->source_allocation_cost_snapshot, 8), '0'), $inverse->total_cost, 8) !== 0) {
                    throw new DomainException(__('invoice_correction.stale'));
                }
            }
        }
    }

    private function scoped(CustomerInvoice $invoice): CustomerInvoice
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $invoice = CustomerInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
        $scope = app(OperatingScopeAccessService::class);
        abort_unless((int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $invoice->branch_id
            && $scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $invoice->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $invoice->financial_period_id)->exists(), 404);

        return $invoice;
    }

    /** Connected invoices and deliveries must be corrected together when a delivery was invoiced in parts.
     * @return array<string,mixed>
     */
    private function snapshot(CustomerInvoice $anchor): array
    {
        $invoiceIds = [(int) $anchor->id];
        $documentIds = [];
        do {
            $before = [count($invoiceIds), count($documentIds)];
            $documentIds = collect($documentIds)->concat(DB::table('customer_invoice_deliveries')->whereIn('customer_invoice_id', $invoiceIds)->pluck('inventory_document_id'))
                ->concat(CustomerInvoice::query()->whereIn('id', $invoiceIds)->pluck('delivery_document_id'))
                ->concat(DB::table('inventory_document_lines')->whereIn('id', CustomerInvoiceLine::query()->whereIn('customer_invoice_id', $invoiceIds)->select('delivery_line_id'))
                    ->pluck('inventory_document_id'))->filter()->unique()->sort()->values()->all();
            $invoiceIds = collect($invoiceIds)->concat(CustomerInvoice::query()->where('document_type', CustomerInvoice::TypeInvoice)->where('status', '<>', CustomerInvoice::StatusCancelled)
                ->where(fn ($query) => $query->whereIn('delivery_document_id', $documentIds)
                    ->orWhereHas('deliveries', fn ($q) => $q->whereIn('inventory_documents.id', $documentIds))
                    ->orWhereHas('lines', fn ($q) => $q->whereIn('delivery_line_id', DB::table('inventory_document_lines')->whereIn('inventory_document_id', $documentIds)->select('id'))))
                ->pluck('id'))->unique()->sort()->values()->all();
        } while ($before !== [count($invoiceIds), count($documentIds)]);
        $invoices = CustomerInvoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();
        $documents = InventoryDocument::query()->withTrashed()->whereIn('id', $documentIds)->orderBy('id')->lockForUpdate()->get();
        foreach ($invoices as $invoice) {
            $this->scoped($invoice);
        }
        foreach ($documents as $document) {
            if ($document->trashed() || (int) $document->company_id !== (int) $anchor->company_id) {
                throw new DomainException(__('invoice_correction.source_invalid'));
            }
            if ((int) $document->branch_id !== (int) $anchor->branch_id) {
                Gate::authorize('customer_invoices.correct_company_warehouse');
            }
            abort_unless(app(OperatingScopeAccessService::class)->allowedBranchQuery(auth()->user(), [Company::query()->findOrFail($anchor->company_id)->doc_num])
                ->where('branches.id', $document->branch_id)->exists(), 404);
        }
        $rows = fn (string $table, string $field, array $ids): array => DB::table($table)->whereIn($field, $ids)->orderBy('id')->lockForUpdate()->get()->map(fn ($row): array => (array) $row)->all();
        $transactions = InventoryTransaction::query()->where('source_type', InventoryDocument::class)->whereIn('source_id', $documentIds)->orderBy('id')->lockForUpdate()->get();
        $allocations = $rows('customer_receipt_allocations', 'customer_invoice_id', $invoiceIds);
        $credits = CustomerInvoice::query()->withTrashed()->where('document_type', CustomerInvoice::TypeCreditNote)->whereIn('original_invoice_id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();
        $receipts = $rows('customer_receipts', 'id', array_unique(array_column($allocations, 'customer_receipt_id')));
        $journalIds = $invoices->pluck('journal_entry_id')->concat($documents->pluck('journal_entry_id'))
            ->concat(array_column($receipts, 'journal_entry_id'))->concat(array_column($receipts, 'reversal_journal_entry_id'))->filter()->unique()->all();
        $journals = JournalEntry::query()->withTrashed()->whereIn('id', $journalIds)->orderBy('id')->lockForUpdate()->get();
        $snapshot = ['invoices' => $invoices->map->getAttributes()->all(), 'lines' => $rows('customer_invoice_lines', 'customer_invoice_id', $invoiceIds),
            'schedules' => $rows('customer_invoice_payment_schedules', 'customer_invoice_id', $invoiceIds),
            'documents' => $documents->map->getAttributes()->all(), 'document_lines' => $rows('inventory_document_lines', 'inventory_document_id', $documentIds),
            'transactions' => $transactions->map->getAttributes()->all(), 'layers' => $rows('inventory_receipt_layers', 'receipt_transaction_id', $transactions->modelKeys()),
            'layer_allocations' => $rows('inventory_layer_allocations', 'issue_transaction_id', $transactions->modelKeys()),
            'invoice_links' => $rows('customer_invoice_deliveries', 'customer_invoice_id', $invoiceIds), 'allocations' => $allocations, 'receipts' => $receipts,
            'credits' => $credits->map->getAttributes()->all(), 'credit_allocations' => $rows('customer_credit_allocations', 'target_invoice_id', $invoiceIds),
            'returns' => SalesReturn::query()->withTrashed()->where(fn ($q) => $q->whereIn('customer_invoice_id', $invoiceIds)->orWhereIn('delivery_document_id', $documentIds))
                ->orderBy('id')->lockForUpdate()->get()->map->getAttributes()->all(),
            'journals' => $journals->map->getAttributes()->all(), 'journal_lines' => $rows('journal_entry_lines', 'journal_entry_id', $journalIds),
            'order_lines' => $rows('sales_order_lines', 'sales_order_id', $invoices->pluck('sales_order_id')->filter()->unique()->all()),
            'source_requests' => $rows('sales_requests', 'id', $invoices->where('source_type', 'sales_request')->pluck('source_id')->filter()->unique()->all()),
            'source_request_lines' => $rows('sales_request_lines', 'sales_request_id', $invoices->where('source_type', 'sales_request')->pluck('source_id')->filter()->unique()->all()),
            'issue_orders' => $rows('sales_issue_orders', 'customer_invoice_id', $invoiceIds)];
        $snapshot['periods'] = $rows('financial_periods', 'id', $invoices->pluck('financial_period_id')->concat($documents->pluck('financial_period_id'))->unique()->all());
        $snapshot['signatures'] = $rows('sales_delivery_receipts', 'inventory_document_id', $documentIds);
        $parentLayerIds = array_unique(array_column($snapshot['layer_allocations'], 'inventory_receipt_layer_id'));
        $snapshot['source_layers'] = $rows('inventory_receipt_layers', 'id', $parentLayerIds);
        $snapshot['allocation_completions'] = $rows('inventory_allocation_cost_completions', 'inventory_layer_allocation_id', array_column($snapshot['layer_allocations'], 'id'));
        $snapshot['receipt_cost_bases'] = $rows('inventory_receipt_cost_bases', 'inventory_receipt_layer_id', $parentLayerIds);
        $adjustmentIds = array_unique([...array_column($snapshot['allocation_completions'], 'inventory_value_adjustment_id'), ...array_column($snapshot['receipt_cost_bases'], 'inventory_value_adjustment_id')]);
        $snapshot['adjustments'] = $rows('inventory_value_adjustments', 'id', $adjustmentIds);
        $snapshot['adjustment_lines'] = $rows('inventory_value_adjustment_lines', 'inventory_value_adjustment_id', $adjustmentIds);
        $snapshot['adjustment_journals'] = $rows('journal_entries', 'id', array_filter(array_column($snapshot['adjustments'], 'journal_entry_id')));
        $snapshot['adjustment_journal_lines'] = $rows('journal_entry_lines', 'journal_entry_id', array_column($snapshot['adjustment_journals'], 'id'));
        $snapshot['periods'] = $rows('financial_periods', 'id', array_unique([...array_column($snapshot['periods'], 'id'),
            ...array_column($snapshot['adjustments'], 'financial_period_id'), ...array_column($snapshot['adjustment_journals'], 'financial_period_id')]));
        $returnIds = array_column($snapshot['returns'], 'id');
        $snapshot['recovered_return_documents'] = InventoryDocument::query()->withTrashed()->where('source_document_type', SalesReturn::class)
            ->whereIn('source_document_id', $returnIds)->orderBy('id')->lockForUpdate()->get()->map->getAttributes()->all();
        $snapshot['recovered_return_transactions'] = InventoryTransaction::query()->where('source_type', InventoryDocument::class)
            ->whereIn('source_id', array_column($snapshot['recovered_return_documents'], 'id'))->orderBy('id')->lockForUpdate()->get()->map->getAttributes()->all();
        $this->authorizePeriods($snapshot);

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private function authorizePeriods(array $snapshot): void
    {
        $company = Company::query()->findOrFail($snapshot['invoices'][0]['company_id']);
        foreach ($snapshot['periods'] as $period) {
            abort_unless(app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
                ->where('financial_periods.id', $period['id'])->exists(), 404);
            if ($period['is_closed']) {
                Gate::authorize('customer_invoices.correct_later_period');
            }
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function target(array $snapshot, string $date, ?int $expected = null): FinancialPeriod
    {
        $this->authorizePeriods($snapshot);
        $companyId = (int) $snapshot['invoices'][0]['company_id'];
        $company = Company::query()->findOrFail($companyId);
        $target = app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
            ->where('financial_periods.id', request()->session()->get(OperatingContextService::FinancialPeriodIdKey))->lockForUpdate()->firstOrFail();
        $last = max([...array_column($snapshot['invoices'], 'invoice_date'), ...array_column($snapshot['documents'], 'document_date'),
            ...array_column($snapshot['transactions'], 'transaction_date'), ...array_column($snapshot['journals'], 'entry_date'),
            ...array_column($snapshot['adjustments'], 'posting_date')]);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || $date < substr($last, 0, 10)
            || ($expected !== null && (int) $target->id !== $expected)
            || collect($snapshot['periods'])->contains(fn ($period): bool => (bool) $period['is_closed'] && $target->from_date->toDateString() <= substr($period['to_date'], 0, 10))) {
            throw new DomainException(__('invoice_correction.target_invalid'));
        }

        return app(FinancialPeriodService::class)->resolveOpenForPostingDate($companyId, $date, expectedPeriodId: (int) $target->id, lockForUpdate: true);
    }

    /** @param array<string,mixed> $snapshot */
    private function assertCorrectable(array $snapshot): void
    {
        if ($this->dependencies($snapshot) !== []) {
            throw new DomainException(__('invoice_correction.recover_first'));
        }
        foreach ($snapshot['invoices'] as $row) {
            $invoice = CustomerInvoice::query()->findOrFail($row['id']);
            $journal = $invoice->journalEntry;
            if ($invoice->document_type !== CustomerInvoice::TypeInvoice || $invoice->posting_status !== 'posted' || $invoice->status !== CustomerInvoice::StatusPosted
                || $invoice->source_type === 'fixed_asset_disposal' || $invoice->hasApprovedCorrection()
                || filled($invoice->electronic_invoice_uuid) || ! in_array($invoice->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)
                || $journal === null || ! $journal->is_posted || $journal->status !== JournalEntry::StatusPosted || $journal->reversed_entry_id !== null
                || (int) $journal->company_id !== (int) $invoice->company_id || (int) $journal->branch_id !== (int) $invoice->branch_id
                || (int) $journal->financial_period_id !== (int) $invoice->financial_period_id || (int) $journal->currency_id !== (int) $invoice->currency_id
                || (int) $journal->source_id !== (int) $invoice->id || $journal->source_type !== ((int) $invoice->posting_revision === 0 ? 'customer_invoice' : 'customer_invoice_post_'.$invoice->posting_revision)
                || bccomp($invoice->paid_amount, '0', 4) !== 0 || bccomp($invoice->credited_amount, '0', 4) !== 0
                || bccomp($invoice->applied_advance_amount, '0', 4) !== 0 || bccomp($invoice->remaining_amount, $invoice->total_amount, 4) !== 0
                || bccomp((string) $invoice->paymentSchedules()->sum('amount'), $invoice->total_amount, 4) !== 0) {
                throw new DomainException(__('invoice_correction.source_invalid'));
            }
            app(SalesAccountingService::class)->assertInvoiceCorrectionSource($invoice);
        }
        foreach ($snapshot['documents'] as $row) {
            $document = InventoryDocument::query()->findOrFail($row['id']);
            $journal = $document->journalEntry;
            if ($document->document_type !== InventoryDocument::TypeSalesDelivery || $document->status !== InventoryDocument::StatusPosted
                || $journal === null || ! $journal->is_posted || $journal->status !== JournalEntry::StatusPosted || $journal->reversed_entry_id !== null
                || (int) $journal->company_id !== (int) $document->company_id || (int) $journal->branch_id !== (int) $document->branch_id
                || (int) $journal->financial_period_id !== (int) $document->financial_period_id || (int) $journal->source_id !== (int) $document->id
                || $journal->source_type !== 'sales_delivery_cogs') {
                throw new DomainException(__('invoice_correction.source_invalid'));
            }
            app(SalesAccountingService::class)->assertDeliveryCorrectionSource($document);
            app(InventoryAccountingPostingService::class)->assertInvoiceCorrectionCompletionSource($document);
        }
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,mixed>> */
    private function dependencies(array $snapshot): array
    {
        $steps = [];
        foreach ($snapshot['receipts'] as $receipt) {
            if ($receipt['status'] !== 'cancelled') {
                $steps[] = ['document' => $receipt['doc_num'], 'action' => __('invoice_correction.recover_collection'),
                    'url' => route('admin.sales.customer-receipts.show', $receipt['doc_num']), 'permission' => 'customer_receipts.cancel'];
            } elseif ($receipt['journal_entry_id'] !== null) {
                $source = JournalEntry::query()->findOrFail($receipt['journal_entry_id']);
                $inverse = JournalEntry::query()->find($receipt['reversal_journal_entry_id']);
                if ($inverse === null) {
                    throw new DomainException(__('invoice_correction.source_invalid'));
                }
                app(SalesReturnCorrectionService::class)->assertInverse($source, $inverse, (int) $inverse->financial_period_id, $inverse->entry_date->toDateString());
            }
        }
        foreach ($snapshot['credit_allocations'] as $allocation) {
            if ($allocation['status'] === 'applied') {
                $credit = CustomerInvoice::query()->findOrFail($allocation['credit_note_id']);
                $steps[] = ['document' => $allocation['doc_num'], 'action' => __('invoice_correction.recover_credit'),
                    'url' => route('admin.sales.sales-invoices.show', $credit), 'permission' => 'customer_credits.reverse_allocation'];
            } elseif (! app(SalesReturnService::class)->hasValidAllocationReversalEvidence(
                CustomerCreditAllocation::query()->findOrFail($allocation['id']), CustomerInvoice::query()->findOrFail($allocation['credit_note_id']))) {
                throw new DomainException(__('invoice_correction.source_invalid'));
            }
        }
        foreach ($snapshot['returns'] as $return) {
            if (! in_array($return['status'], [SalesReturn::StatusCancelled, 'reversed'], true)) {
                $steps[] = ['document' => $return['doc_num'], 'action' => __('invoice_correction.recover_return'),
                    'url' => route('admin.sales.sales-returns.show', $return['doc_num']), 'permission' => 'sales_returns.view'];
            } else {
                app(SalesReturnService::class)->assertCancelledRecovery(SalesReturn::query()->findOrFail($return['id']));
            }
        }
        foreach ($snapshot['credits'] as $credit) {
            if ($credit['posting_status'] === 'posted') {
                $steps[] = ['document' => $credit['doc_num'], 'action' => __('invoice_correction.recover_credit'),
                    'url' => route('admin.sales.sales-invoices.show', $credit['doc_num']), 'permission' => 'customer_credits.reverse_allocation'];
            } else {
                $source = CustomerInvoice::query()->findOrFail($credit['id']);
                if ($source->posting_status !== 'reversed' || $source->reversalJournalEntry === null || $source->journalEntry === null) {
                    throw new DomainException(__('invoice_correction.source_invalid'));
                }
                app(SalesReturnCorrectionService::class)->assertInverse($source->journalEntry, $source->reversalJournalEntry,
                    (int) $source->reversalJournalEntry->financial_period_id, $source->reversalJournalEntry->entry_date->toDateString());
            }
        }

        return $steps;
    }

    private function credit(CustomerInvoice $invoice, CustomerInvoiceCorrection $proposal): CustomerInvoice
    {
        $numbers = app(DocumentNumberService::class)->nextForCompany('customer_credit_notes', CustomerInvoice::class, (int) $invoice->company_id);
        $credit = CustomerInvoice::query()->create([...$invoice->only(['company_id', 'branch_id', 'customer_id', 'sales_order_id', 'currency_id', 'exchange_rate',
            'subtotal_amount', 'discount_amount', 'taxable_amount', 'tax_amount', 'total_amount']), ...$numbers,
            'financial_period_id' => $proposal->posting_financial_period_id, 'invoice_date' => $proposal->posting_date, 'due_date' => $proposal->posting_date,
            'document_type' => CustomerInvoice::TypeCreditNote, 'original_invoice_id' => $invoice->id, 'remaining_amount' => '0',
            'source_type' => CustomerInvoiceCorrection::class, 'source_id' => $proposal->id, 'source_doc_num' => $invoice->doc_num,
            'created_by' => auth()->id(), 'notes' => $proposal->reason.' — '.$proposal->recovery_reference]);
        foreach ($invoice->lines()->orderBy('id')->get() as $line) {
            $credit->lines()->create([...$line->only(['sales_order_line_id', 'delivery_line_id', 'product_id', 'unit_id', 'line_number', 'conversion_factor',
                'base_quantity', 'description', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'line_total', 'is_service', 'unit_cost']),
                'source_snapshot' => [...($line->source_snapshot ?? []), 'original_invoice_line_public_id' => $line->public_id,
                    'customer_invoice_correction_id' => $proposal->id]]);
        }
        $journal = app(JournalEntryService::class)->createPostedReversalFromSource($invoice->journalEntry,
            ['company_id' => $invoice->company_id, 'branch_id' => $invoice->branch_id, 'financial_period_id' => $proposal->posting_financial_period_id,
                'entry_date' => $proposal->posting_date->toDateString(), 'currency_id' => $invoice->currency_id, 'exchange_rate' => $invoice->exchange_rate,
                'description' => __('invoice_correction.credit').' '.$invoice->doc_num, 'notes' => $credit->notes,
                'source_type' => 'customer_invoice_correction_credit', 'source_id' => $credit->id, 'source_doc_num' => $credit->doc_num]);
        $scheduleCredits = [];
        foreach ($invoice->paymentSchedules()->orderBy('id')->lockForUpdate()->get() as $schedule) {
            if (bccomp($schedule->collected_amount, '0', 4) !== 0 || bccomp($schedule->credited_amount, '0', 4) !== 0) {
                throw new DomainException(__('invoice_correction.stale'));
            }
            $scheduleCredits[] = ['schedule_id' => (int) $schedule->id, 'amount' => (string) $schedule->amount];
            $schedule->update(['credited_amount' => $schedule->amount]);
        }
        $credit->update(['status' => CustomerInvoice::StatusPosted, 'posting_status' => 'posted', 'is_closed' => true, 'journal_entry_id' => $journal->id,
            'issued_by' => auth()->id(), 'issued_at' => now(), 'credit_application_snapshot' => ['original_invoice_id' => (int) $invoice->id,
                'applied_to_original' => (string) $invoice->total_amount, 'schedules' => $scheduleCredits, 'customer_invoice_correction_id' => (int) $proposal->id]]);
        $invoice->update(['credited_amount' => $invoice->total_amount, 'remaining_amount' => '0']);

        return $credit->refresh();
    }

    private function releaseSourceQuantities(CustomerInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if ($line->sales_order_line_id !== null) {
                $orderLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
                if ((int) $orderLine->sales_order_id !== (int) $invoice->sales_order_id || (int) $orderLine->product_id !== (int) $line->product_id
                    || bccomp($orderLine->invoiced_quantity, $line->quantity, 8) < 0 || bccomp($orderLine->invoiced_base_quantity, $line->base_quantity, 8) < 0) {
                    throw new DomainException(__('invoice_correction.source_invalid'));
                }
                $orderLine->update(['invoiced_quantity' => bcsub($orderLine->invoiced_quantity, $line->quantity, 8),
                    'invoiced_base_quantity' => bcsub($orderLine->invoiced_base_quantity, $line->base_quantity, 8)]);
            }
        }
        if ($invoice->source_type === 'sales_request' && $invoice->source_id !== null) {
            $request = SalesRequest::query()->with('lines')->where('company_id', $invoice->company_id)->where('branch_id', $invoice->branch_id)
                ->lockForUpdate()->findOrFail($invoice->source_id);
            foreach ($invoice->lines as $line) {
                $source = $request->lines->firstWhere('public_id', $line->source_snapshot['sales_request_line_public_id'] ?? null);
                if ($source === null || (int) $source->product_id !== (int) $line->product_id || (int) $source->unit_id !== (int) $line->unit_id
                    || bccomp($source->converted_quantity, $line->quantity, 8) < 0) {
                    throw new DomainException(__('invoice_correction.source_invalid'));
                }
                $source->update(['converted_quantity' => bcsub($source->converted_quantity, $line->quantity, 8)]);
            }
            $status = ! $request->lines()->where('converted_quantity', '>', 0)->exists() ? SalesRequest::StatusApproved
                : ($request->lines()->whereColumn('converted_quantity', '<', 'quantity')->exists() ? 'partially_converted' : 'converted');
            $request->update(['status' => $status, 'status_history' => [...($request->status_history ?? []),
                ['event' => 'invoice_correction_conversion_reversed', 'invoice' => $invoice->doc_num, 'to' => $status,
                    'at' => now()->toIso8601String(), 'by' => auth()->id()]]]);
        }
    }

    private function assertSourceCounters(CustomerInvoiceCorrection $proposal): void
    {
        foreach ($proposal->source_snapshot['order_lines'] as $original) {
            if (! in_array($original['id'], array_column($proposal->source_snapshot['lines'], 'sales_order_line_id'), true)) {
                continue;
            }
            $line = SalesOrderLine::query()->findOrFail($original['id']);
            $active = CustomerInvoiceLine::query()->where('sales_order_line_id', $line->id)->whereHas('invoice', fn ($q) => $q
                ->where('document_type', CustomerInvoice::TypeInvoice)->where('status', '<>', CustomerInvoice::StatusCancelled)
                ->where('posting_status', '<>', 'reversed')->whereDoesntHave('creditNotes', fn ($credit) => $credit
                ->where('source_type', CustomerInvoiceCorrection::class)->where('posting_status', 'posted')))->get();
            foreach (['quantity' => 'invoiced_quantity', 'base_quantity' => 'invoiced_base_quantity'] as $quantity => $counter) {
                if (bccomp($line->{$counter}, $active->reduce(fn ($sum, $row) => bcadd($sum, $row->{$quantity}, 8), '0'), 8) !== 0) {
                    throw new DomainException(__('invoice_correction.stale'));
                }
            }
            $deliveries = DB::table('inventory_document_lines as line')->join('inventory_documents as doc', 'doc.id', '=', 'line.inventory_document_id')
                ->where('line.source_line_type', SalesOrderLine::class)->where('line.source_line_id', $line->id)
                ->where('doc.status', InventoryDocument::StatusPosted)->where('doc.document_type', InventoryDocument::TypeSalesDelivery)
                ->whereNull('line.deleted_at')->whereNull('doc.deleted_at')->get(['line.transaction_quantity', 'line.quantity']);
            $unbilled = SalesReturnLine::query()->where('sales_order_line_id', $line->id)->whereNull('customer_invoice_line_id')
                ->whereHas('salesReturn', fn ($q) => $q->whereIn('status', [SalesReturn::StatusReceived, SalesReturn::StatusInspected, SalesReturn::StatusClosed]))->get();
            foreach (['transaction_quantity' => ['delivered_quantity', 'quantity'], 'quantity' => ['delivered_base_quantity', 'base_quantity']] as $quantity => [$counter, $returned]) {
                $expected = bcsub($deliveries->reduce(fn ($sum, $row) => bcadd($sum, $row->{$quantity}, 8), '0'),
                    $unbilled->reduce(fn ($sum, $row) => bcadd($sum, $row->{$returned}, 8), '0'), 8);
                if (bccomp($line->{$counter}, $expected, 8) !== 0) {
                    throw new DomainException(__('invoice_correction.stale'));
                }
            }
        }
        foreach ($proposal->source_snapshot['source_request_lines'] as $original) {
            $line = SalesRequestLine::query()->findOrFail($original['id']);
            $quantity = SalesOrderLine::query()->where('sales_request_line_id', $line->id)->whereHas('order', fn ($q) => $q->where('status', '<>', 'cancelled'))->get()
                ->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 8), '0');
            $direct = CustomerInvoiceLine::query()->whereHas('invoice', fn ($q) => $q->where('source_type', 'sales_request')->where('source_id', $line->sales_request_id)
                ->where('document_type', CustomerInvoice::TypeInvoice)->where('status', '<>', CustomerInvoice::StatusCancelled)
                ->whereDoesntHave('creditNotes', fn ($credit) => $credit->where('source_type', CustomerInvoiceCorrection::class)->where('posting_status', 'posted')))->get();
            foreach ($direct as $row) {
                if (($row->source_snapshot['sales_request_line_public_id'] ?? null) === $line->public_id) {
                    $quantity = bcadd($quantity, $row->quantity, 8);
                }
            }
            if (bccomp($line->converted_quantity, $quantity, 8) !== 0) {
                throw new DomainException(__('invoice_correction.stale'));
            }
        }
    }

    /** Capture immutable effects, not future source settlement counters or layer remaining balances.
     * @param  list<int>  $creditIds  @return array<string,mixed>
     */
    private function executionSnapshot(CustomerInvoiceCorrection $proposal, array $creditIds): array
    {
        $rows = fn (string $table, string $field, array $ids): array => DB::table($table)->whereIn($field, $ids)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $credits = $rows('customer_invoices', 'id', $creditIds);
        $documentIds = array_column($proposal->source_snapshot['documents'], 'id');
        $documents = InventoryDocument::query()->whereIn('id', $documentIds)->orderBy('id')->get();
        $journalIds = collect(array_column($credits, 'journal_entry_id'))->concat($documents->pluck('reversal_journal_entry_id'))
            ->concat(array_column($proposal->source_snapshot['journals'], 'id'))
            ->concat(array_column($proposal->source_snapshot['adjustment_journals'], 'id'))
            ->concat(JournalEntry::query()->where('company_id', $proposal->company_id)->where('source_type', 'inventory_document_cost_completion_reversal')
                ->whereIn('source_id', $documentIds)->pluck('id'))->filter()->unique()->all();
        $reversals = InventoryTransaction::query()->where('source_type', InventoryDocument::class)->whereIn('source_id', $documentIds)->where('is_reversal', true)->pluck('id')->all();

        return ['credits' => $credits, 'credit_lines' => $rows('customer_invoice_lines', 'customer_invoice_id', $creditIds),
            'source_invoice_settlements' => CustomerInvoice::query()->whereIn('id', array_column($proposal->source_snapshot['invoices'], 'id'))->orderBy('id')->get()
                ->map(fn ($source): array => $source->only(['id', 'company_id', 'branch_id', 'financial_period_id', 'invoice_date', 'total_amount', 'journal_entry_id',
                    'delivery_document_id', 'paid_amount', 'credited_amount', 'remaining_amount', 'status', 'posting_status', 'is_closed']))->all(),
            'source_schedules' => $rows('customer_invoice_payment_schedules', 'customer_invoice_id', array_column($proposal->source_snapshot['invoices'], 'id')),
            'issue_orders' => $rows('sales_issue_orders', 'customer_invoice_id', array_column($proposal->source_snapshot['invoices'], 'id')),
            'restored_layers' => array_map(fn ($row): array => array_diff_key($row, array_flip(['remaining_quantity', 'updated_at'])), $rows('inventory_receipt_layers', 'receipt_transaction_id', $reversals)),
            'source_lines' => $rows('customer_invoice_lines', 'customer_invoice_id', array_column($proposal->source_snapshot['invoices'], 'id')),
            'document_lines' => $rows('inventory_document_lines', 'inventory_document_id', $documentIds),
            'documents' => $documents->map(fn ($document): array => $document->only(['id', 'company_id', 'branch_id', 'financial_period_id', 'document_date',
                'source_document_type', 'source_document_id', 'status', 'journal_entry_id', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'reversal_reason']))->all(),
            'transactions' => InventoryTransaction::query()->where('source_type', InventoryDocument::class)->whereIn('source_id', $documentIds)
                ->orderBy('id')->get()->map->getAttributes()->all(), 'journals' => $rows('journal_entries', 'id', $journalIds),
            'journal_lines' => $rows('journal_entry_lines', 'journal_entry_id', $journalIds)];
    }

    private function assertSeal(CustomerInvoiceCorrection $proposal): void
    {
        if (! hash_equals($proposal->source_fingerprint, $this->digest($proposal->source_snapshot))
            || ! hash_equals($proposal->proposal_fingerprint, $this->proposalDigest($proposal))
            || (in_array($proposal->status, ['applying', 'approved'], true) && ($proposal->approved_at === null
                || (int) $proposal->prepared_by === (int) $proposal->approved_by || ! is_string($proposal->approval_fingerprint)
                || ! hash_equals($proposal->approval_fingerprint, $this->approvalDigest($proposal))))
            || ($proposal->status === 'approved' && (! is_array($proposal->execution_snapshot) || ! is_string($proposal->execution_fingerprint)
                || ! hash_equals($proposal->execution_fingerprint, $this->digest($proposal->execution_snapshot))))) {
            throw new DomainException(__('invoice_correction.stale'));
        }
    }

    private function proposalDigest(CustomerInvoiceCorrection $proposal): string
    {
        return $this->digest(['company' => (int) $proposal->company_id, 'branch' => (int) $proposal->branch_id, 'invoice' => (int) $proposal->customer_invoice_id,
            'target' => (int) $proposal->posting_financial_period_id, 'date' => $proposal->posting_date->toDateString(), 'reason' => $proposal->reason,
            'recovery_reference' => $proposal->recovery_reference, 'source' => $proposal->source_fingerprint, 'preparer' => (int) $proposal->prepared_by]);
    }

    private function approvalDigest(CustomerInvoiceCorrection $proposal): string
    {
        return $this->digest(['proposal' => $proposal->proposal_fingerprint, 'approver' => (int) $proposal->approved_by,
            'at' => $proposal->approved_at?->toISOString(), 'reason' => $proposal->approval_reason, 'execution' => $proposal->execution_fingerprint]);
    }

    /** @param array<mixed> $value */
    private function digest(array $value): string
    {
        return hash_hmac('sha256', 'mgypack.customer-invoice-correction.v1:'.json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
