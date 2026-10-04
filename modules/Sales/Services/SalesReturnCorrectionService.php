<?php

namespace Modules\Sales\Services;

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
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Sales\Models\CustomerCreditAllocation;
use Modules\Sales\Models\CustomerCreditRefund;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Models\SalesReturnCorrection;

final class SalesReturnCorrectionService
{
    public function __construct(private readonly SalesCycleAuditService $audit) {}

    /** @return array<string, mixed> */
    public function preview(SalesReturn $return): array
    {
        abort_unless(Gate::any(['sales_returns.correct_prepare', 'sales_returns.correct_approve']), 403);

        return DB::transaction(function () use ($return): array {
            $return = $this->scopedReturn($return);
            $snapshot = $this->snapshot($return);
            $credit = $return->creditNote;
            $dependencies = $credit === null ? collect() : $credit->creditAllocations()->where('status', 'applied')->get()
                ->map(fn ($row): array => ['operation' => 'allocation', 'id' => $row->id, 'document' => $row->doc_num, 'amount' => $row->amount]);
            if ($credit !== null) {
                $dependencies = $dependencies->concat($credit->creditRefunds()->where('status', 'posted')->get()
                    ->map(fn ($row): array => ['operation' => 'refund', 'id' => $row->id, 'document' => $row->doc_num, 'amount' => $row->amount]));
            }

            return ['return' => $return->load('lines.product', 'invoice', 'delivery'), 'fingerprint' => $this->digest($snapshot),
                'source_period' => FinancialPeriod::query()->findOrFail($return->financial_period_id),
                'source_journals' => $snapshot['journals'], 'source_documents' => $snapshot['documents'],
                'dependencies' => $dependencies, 'target' => FinancialPeriod::query()->where('company_id', $return->company_id)
                    ->find(request()->session()->get(OperatingContextService::FinancialPeriodIdKey)),
                'history' => SalesReturnCorrection::query()->with(['preparer', 'approver', 'replacementReturn'])->where('company_id', $return->company_id)
                    ->where('sales_return_id', $return->id)->latest('id')->paginate(20)];
        });
    }

    /** @param array<string, mixed> $data */
    public function prepare(SalesReturn $return, array $data): SalesReturnCorrection
    {
        Gate::authorize('sales_returns.correct_prepare');

        return DB::transaction(function () use ($return, $data): SalesReturnCorrection {
            $return = $this->scopedReturn($return);
            $operation = (string) ($data['operation'] ?? '');
            $subject = $this->subject($return, $operation, (int) ($data['source_id'] ?? 0));
            Gate::authorize($this->permission($operation, $return));
            $target = $this->target($return, (string) ($data['posting_date'] ?? ''), $subject);
            $snapshot = $this->snapshot($return);
            if (! hash_equals($this->digest($snapshot), (string) ($data['source_fingerprint'] ?? ''))
                || trim((string) ($data['reason'] ?? '')) === '' || mb_strlen((string) $data['reason']) > 3000) {
                throw new DomainException(__('sales_return_plan.stale'));
            }
            if ($operation === 'return' && $return->creditNote !== null
                && ($return->creditNote->creditAllocations()->where('status', 'applied')->exists()
                    || $return->creditNote->creditRefunds()->where('status', 'posted')->exists())) {
                throw new DomainException(__('sales_return_plan.recover_first'));
            }
            $reference = trim((string) ($data['recovery_reference'] ?? ''));
            if ($operation === 'refund' && ($reference === '' || mb_strlen($reference) > 255)) {
                throw new DomainException(__('sales_return_correction.refund_recovery_required'));
            }
            $replacement = $operation === 'return' ? $this->replacementPayload($return, $data['lines'] ?? []) : [];
            $fields = ['company_id' => $return->company_id, 'branch_id' => $return->branch_id, 'sales_return_id' => $return->id,
                'source_financial_period_id' => $subject->financial_period_id, 'posting_financial_period_id' => $target->id,
                'operation' => $operation, 'allocation_id' => $operation === 'allocation' ? $subject->id : null,
                'refund_id' => $operation === 'refund' ? $subject->id : null, 'posting_date' => $data['posting_date'],
                'reason' => trim($data['reason']), 'recovery_reference' => $reference === '' ? null : $reference,
                'replacement_payload' => $replacement, 'source_snapshot' => $snapshot,
                'source_fingerprint' => $this->digest($snapshot), 'prepared_by' => auth()->id(), 'status' => 'prepared'];
            $proposal = new SalesReturnCorrection($fields);
            $proposal->proposal_fingerprint = $this->proposalDigest($proposal);
            $existing = SalesReturnCorrection::query()->where('sales_return_id', $return->id)->where('operation', $operation)
                ->where('allocation_id', $fields['allocation_id'])->where('refund_id', $fields['refund_id'])->where('status', 'prepared')->lockForUpdate()->first();
            if ($existing !== null) {
                if (hash_equals($existing->proposal_fingerprint, $proposal->proposal_fingerprint)) {
                    return $existing;
                }
                throw new DomainException(__('sales_return_plan.reject_existing'));
            }
            $proposal->save();
            $this->audit->record($proposal, 'sales_return.correction_prepared', ['return' => $return->doc_num, 'operation' => $operation]);

            return $proposal;
        }, 3);
    }

    public function approve(SalesReturn $return, int $proposalId, string $reason): SalesReturnCorrection
    {
        Gate::authorize('sales_returns.correct_approve');

        return DB::transaction(function () use ($return, $proposalId, $reason): SalesReturnCorrection {
            $return = $this->scopedReturn($return);
            $proposal = SalesReturnCorrection::query()->where('company_id', $return->company_id)->where('sales_return_id', $return->id)
                ->lockForUpdate()->findOrFail($proposalId);
            Gate::authorize($this->permission($proposal->operation, $return, $proposal));
            if ((int) $proposal->prepared_by === (int) auth()->id() || trim($reason) === '') {
                throw new DomainException(__('sales_return_plan.independent'));
            }
            $this->assertSeal($proposal);
            if ($proposal->status === 'approved') {
                return $proposal;
            }
            if ($proposal->status !== 'prepared' || ! hash_equals($proposal->source_fingerprint, $this->digest($this->snapshot($return)))) {
                throw new DomainException(__('sales_return_plan.stale'));
            }
            $subject = $this->subject($return, $proposal->operation, (int) ($proposal->allocation_id ?? $proposal->refund_id ?? 0));
            $this->target($return, $proposal->posting_date->toDateString(), $subject, (int) $proposal->posting_financial_period_id);
            $proposal->forceFill(['status' => 'applying', 'approved_by' => auth()->id(), 'approved_at' => now()->startOfSecond(),
                'approval_reason' => trim($reason)]);
            $proposal->approval_fingerprint = $this->approvalDigest($proposal);
            $proposal->save();
            $replacement = null;
            if ($proposal->operation === 'allocation') {
                app(CustomerCreditService::class)->reverseAllocation($subject, $proposal->reason, $proposal->id);
            } elseif ($proposal->operation === 'refund') {
                app(CustomerCreditService::class)->reverseRefund($subject, $proposal->reason, $proposal->recovery_reference, $proposal->id);
            } else {
                app(SalesReturnService::class)->correctForApprovedProposal($return, $proposal->id);
                $return = $return->fresh();
                $replacement = $return->customer_invoice_id !== null
                    ? app(SalesReturnService::class)->create($return->invoice, $return->reason_code, $proposal->reason,
                        $this->invoiceReplacementLines($return, $proposal->replacement_payload), $return->branch_store_id, $proposal->id)
                    : app(SalesReturnService::class)->createFromDelivery($return->delivery, $return->reason_code, $proposal->reason,
                        $this->deliveryReplacementLines($return, $proposal->replacement_payload), $proposal->id);
            }
            $result = $proposal->operation === 'return' ? ['return' => $return->fresh()->getAttributes(),
                'replacement' => $replacement?->getAttributes(), 'replacement_lines' => $replacement?->lines()->orderBy('id')->get()->map->getAttributes()->all(),
                'post_state' => $this->snapshot($return->fresh())]
                : ['subject' => $subject->fresh()->getAttributes(), 'effect' => $subject->fresh()->reversal_effect_snapshot,
                    'journals' => $proposal->operation === 'refund' ? JournalEntry::query()->whereIn('id', [$subject->journal_entry_id, $subject->fresh()->reversal_journal_entry_id])
                        ->orderBy('id')->get()->map(fn ($entry): array => ['header' => $entry->getAttributes(), 'lines' => $entry->lines()->orderBy('line_no')->get()->map->getAttributes()->all()])->all() : []];
            $proposal->forceFill(['status' => 'approved', 'replacement_return_id' => $replacement?->id,
                'execution_snapshot' => $result, 'execution_fingerprint' => $this->digest($result)]);
            $proposal->approval_fingerprint = $this->approvalDigest($proposal);
            $proposal->save();
            $this->audit->record($proposal, 'sales_return.correction_approved', ['return' => $return->doc_num,
                'operation' => $proposal->operation, 'replacement' => $replacement?->doc_num]);

            return $proposal->refresh();
        }, 3);
    }

    public function reject(SalesReturn $return, int $proposalId): void
    {
        Gate::authorize('sales_returns.correct_approve');
        DB::transaction(function () use ($return, $proposalId): void {
            $return = $this->scopedReturn($return);
            $proposal = SalesReturnCorrection::query()->where('sales_return_id', $return->id)->lockForUpdate()->findOrFail($proposalId);
            Gate::authorize($this->permission($proposal->operation, $return, $proposal));
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('sales_return_plan.stale'));
            }
            $this->assertSeal($proposal);
            $proposal->forceFill(['status' => 'rejected', 'rejected_at' => now()])->save();
            $this->audit->record($proposal, 'sales_return.correction_rejected');
        });
    }

    public function execution(int $proposalId, string $operation, int $sourceId): SalesReturnCorrection
    {
        Gate::authorize('sales_returns.correct_approve');
        if (DB::transactionLevel() < 1) {
            throw new DomainException(__('sales_return_plan.stale'));
        }
        $proposal = SalesReturnCorrection::query()->where('status', 'applying')->lockForUpdate()->findOrFail($proposalId);
        $return = $this->scopedReturn(SalesReturn::query()->findOrFail($proposal->sales_return_id));
        $this->assertSeal($proposal);
        $expected = match ($operation) {
            'allocation' => $proposal->allocation_id, 'refund' => $proposal->refund_id,
            'return', 'replacement' => $proposal->sales_return_id, default => null,
        };
        if (($operation === 'replacement' ? 'return' : $operation) !== $proposal->operation
            || (int) $expected !== $sourceId || (int) $proposal->approved_by !== (int) auth()->id()) {
            throw new DomainException(__('sales_return_plan.stale'));
        }
        $subject = match ($operation) {
            'allocation' => CustomerCreditAllocation::query()->findOrFail($sourceId),
            'refund' => CustomerCreditRefund::query()->findOrFail($sourceId), default => $return,
        };
        Gate::authorize($this->permission($proposal->operation, $return, $proposal));
        $this->target($return, $proposal->posting_date->toDateString(), $subject, (int) $proposal->posting_financial_period_id);

        return $proposal;
    }

    /** @param array<string, mixed> $effect */
    public function approvedRecovery(array $effect, string $operation, int $sourceId): bool
    {
        $proposal = SalesReturnCorrection::query()->find($effect['sales_return_correction_id'] ?? 0);
        if ($proposal === null || $proposal->status !== 'approved' || $proposal->operation !== $operation
            || (int) ($operation === 'allocation' ? $proposal->allocation_id : $proposal->refund_id) !== $sourceId
            || (int) $proposal->posting_financial_period_id !== (int) ($effect['reversal_financial_period_id'] ?? 0)
            || (int) $proposal->source_financial_period_id !== (int) ($effect[$operation.'_financial_period_id'] ?? 0)
            || $proposal->posting_date->toDateString() !== ($effect['reversal_date'] ?? null)
            || $proposal->reason !== ($effect['reversal_reason'] ?? null)
            || (int) $proposal->approved_by !== (int) ($effect['reversed_by'] ?? 0)
            || ($operation === 'refund' && $proposal->recovery_reference !== ($effect['recovery_reference'] ?? null))) {
            return false;
        }
        try {
            $this->assertSeal($proposal);
            $original = collect($proposal->source_snapshot[$operation === 'allocation' ? 'allocations' : 'refunds'])->firstWhere('id', $sourceId);
            if ($original === null || (int) $original['credit_note_id'] !== (int) ($effect['credit_note_id'] ?? 0)
                || bccomp((string) $original['amount'], (string) ($effect['amount'] ?? '-1'), 4) !== 0
                || ! hash_equals($this->digest($proposal->execution_snapshot['effect'] ?? []), $this->digest($effect))) {
                return false;
            }
            foreach ($proposal->execution_snapshot['journals'] ?? [] as $record) {
                $entry = JournalEntry::query()->find($record['header']['id']);
                if ($entry === null || ! hash_equals($this->digest($record), $this->digest(['header' => $entry->getAttributes(),
                    'lines' => $entry->lines()->orderBy('line_no')->get()->map->getAttributes()->all()]))) {
                    return false;
                }
            }
        } catch (DomainException) {
            return false;
        }

        return true;
    }

    public function assertInverse(JournalEntry $source, JournalEntry $inverse, int $periodId, string $date): void
    {
        $source->refresh()->load('lines');
        $inverse->refresh()->load('lines');
        if ($source->trashed() || $inverse->trashed() || ! $source->is_posted || ! $inverse->is_posted
            || $source->status !== JournalEntry::StatusPosted || $inverse->status !== JournalEntry::StatusPosted
            || (int) $source->reversed_entry_id !== (int) $inverse->id || $inverse->reversed_entry_id !== null
            || (int) $inverse->financial_period_id !== $periodId || $inverse->entry_date->toDateString() !== $date
            || (int) $source->company_id !== (int) $inverse->company_id || (int) $source->branch_id !== (int) $inverse->branch_id
            || (int) $source->currency_id !== (int) $inverse->currency_id
            || bccomp((string) $source->exchange_rate, (string) $inverse->exchange_rate, 6) !== 0
            || $source->lines->count() !== $inverse->lines->count()
            || $source->lines->pluck('line_no')->unique()->count() !== $source->lines->count()
            || $inverse->lines->pluck('line_no')->unique()->count() !== $inverse->lines->count()) {
            throw new DomainException(__('sales_return_plan.source_invalid'));
        }
        foreach ($source->lines as $line) {
            $opposite = $inverse->lines->firstWhere('line_no', $line->line_no);
            foreach (['account_id', 'customer_id', 'supplier_id', 'employee_id', 'bank_account_id', 'cost_center_id', 'department_id', 'branch_id'] as $field) {
                if ($opposite === null || $line->{$field} !== $opposite->{$field}) {
                    throw new DomainException(__('sales_return_plan.source_invalid'));
                }
            }
            if (bccomp((string) $line->debit_amount, (string) $opposite->credit_amount, 4) !== 0
                || bccomp((string) $line->credit_amount, (string) $opposite->debit_amount, 4) !== 0) {
                throw new DomainException(__('sales_return_plan.source_invalid'));
            }
        }
    }

    public function assertApproved(SalesReturnCorrection $proposal): void
    {
        if ($proposal->status !== 'approved') {
            throw new DomainException(__('sales_return_plan.stale'));
        }
        $this->assertSeal($proposal);
    }

    private function scopedReturn(SalesReturn $return): SalesReturn
    {
        Gate::authorize('sales_returns.correct_later_period');
        $company = app(OperatingCompanyContextService::class)->requireCompanyId();
        Company::query()->whereKey($company)->lockForUpdate()->firstOrFail();
        $return = SalesReturn::query()->where('company_id', $company)->lockForUpdate()->findOrFail($return->id);
        $scope = app(OperatingScopeAccessService::class);
        $doc = Company::query()->findOrFail($company)->doc_num;
        abort_unless((int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $return->branch_id
            && $scope->allowedBranchQuery(auth()->user(), [$doc])->where('branches.id', $return->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$doc])->where('financial_periods.id', $return->financial_period_id)->exists(), 404);

        return $return;
    }

    private function target(SalesReturn $return, string $date, object $subject, ?int $expected = null): FinancialPeriod
    {
        Gate::authorize('sales_returns.correct_later_period');
        $company = Company::query()->findOrFail($return->company_id);
        $scope = app(OperatingScopeAccessService::class);
        $source = $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
            ->where('financial_periods.id', $subject->financial_period_id)->lockForUpdate()->firstOrFail();
        $returnPeriod = $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
            ->where('financial_periods.id', $return->financial_period_id)->lockForUpdate()->firstOrFail();
        $target = $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
            ->where('financial_periods.id', request()->session()->get(OperatingContextService::FinancialPeriodIdKey))->lockForUpdate()->firstOrFail();
        if (! $source->is_closed || ! $returnPeriod->is_closed || ($expected !== null && (int) $target->id !== $expected)
            || $target->from_date->toDateString() <= max($source->to_date->toDateString(), $returnPeriod->to_date->toDateString())
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            throw new DomainException(__('sales_return_plan.target_invalid'));
        }

        return app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $company->id, $date,
            expectedPeriodId: (int) $target->id, lockForUpdate: true);
    }

    private function subject(SalesReturn $return, string $operation, int $sourceId): object
    {
        if (! in_array($return->status, [SalesReturn::StatusReceived, SalesReturn::StatusInspected, SalesReturn::StatusClosed], true)) {
            throw new DomainException(__('sales_return_plan.source_invalid'));
        }

        return match ($operation) {
            'return' => $return,
            'allocation' => CustomerCreditAllocation::query()->where('company_id', $return->company_id)
                ->where('credit_note_id', $return->credit_note_id)->where('status', 'applied')->lockForUpdate()->findOrFail($sourceId),
            'refund' => CustomerCreditRefund::query()->where('company_id', $return->company_id)
                ->where('credit_note_id', $return->credit_note_id)->where('status', 'posted')->lockForUpdate()->findOrFail($sourceId),
            default => throw new DomainException(__('sales_return_plan.source_invalid')),
        };
    }

    private function permission(string $operation, SalesReturn $return, ?SalesReturnCorrection $proposal = null): string
    {
        return match ($operation) {
            'allocation' => 'customer_credits.reverse_allocation', 'refund' => 'customer_credits.reverse_refund',
            default => match ($proposal?->source_snapshot['return']['status'] ?? $return->status) {
                SalesReturn::StatusReceived => 'sales_returns.correct_receipt',
                SalesReturn::StatusInspected => 'sales_returns.correct_disposition', default => 'sales_returns.correct_closed',
            },
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(SalesReturn $return): array
    {
        $credit = $return->creditNote()->lockForUpdate()->first();
        if ($credit !== null) {
            app(CustomerCreditApplicationEvidenceService::class)->assertApprovedApplication($credit);
        }
        $allocations = $credit?->creditAllocations()->orderBy('id')->lockForUpdate()->get() ?? collect();
        $refunds = $credit?->creditRefunds()->orderBy('id')->lockForUpdate()->get() ?? collect();
        $invoices = CustomerInvoice::query()->whereIn('id', collect([$return->customer_invoice_id, $credit?->id])
            ->concat($allocations->pluck('target_invoice_id'))->filter()->unique())->orderBy('id')->lockForUpdate()->get();
        $documents = InventoryDocument::query()->withTrashed()->where('source_document_type', SalesReturn::class)
            ->where('source_document_id', $return->id)->orderBy('id')->lockForUpdate()->get();
        $transactions = InventoryTransaction::query()->where('source_type', InventoryDocument::class)
            ->whereIn('source_id', $documents->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $returnLines = $return->lines()->orderBy('id')->lockForUpdate()->get();
        $deliveryIds = DB::table('inventory_document_lines')->whereIn('id', $returnLines->pluck('delivery_line_id')->filter())
            ->orderBy('id')->lockForUpdate()->pluck('inventory_document_id')->concat([$return->delivery_document_id])->filter()->unique()->values()->all();
        $deliveryDocuments = InventoryDocument::query()->withTrashed()->whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get();
        $costProposals = InventoryReceiptCostProposal::query()->whereIn('inventory_document_id', $documents->modelKeys())->orderBy('id')->lockForUpdate()->get();
        $adjustmentIds = DB::table('inventory_value_adjustment_lines')->whereIn('source_transaction_id', $transactions->modelKeys())
            ->orderBy('id')->lockForUpdate()->pluck('inventory_value_adjustment_id');
        $adjustments = InventoryValueAdjustment::query()->where(fn ($query) => $query->whereIn('id', $adjustmentIds)
            ->orWhere(fn ($query) => $query->where('source_type', InventoryReceiptCostProposal::class)->whereIn('source_id', $costProposals->modelKeys())))
            ->orderBy('id')->lockForUpdate()->get();
        $journalIds = $invoices->pluck('journal_entry_id')->concat($refunds->pluck('journal_entry_id'))
            ->concat($refunds->pluck('reversal_journal_entry_id'))->concat([$return->quarantine_journal_entry_id, $return->disposition_journal_entry_id])
            ->concat($documents->pluck('journal_entry_id'))->filter()->unique();
        $journalIds = $journalIds->concat($adjustments->pluck('journal_entry_id'))->concat($adjustments->pluck('reversal_journal_entry_id'))->filter()->unique();
        $journals = JournalEntry::query()->withTrashed()->whereIn('id', $journalIds)->orderBy('id')->lockForUpdate()->get();
        $rows = fn (string $table, string $field, array $ids): array => DB::table($table)->whereIn($field, $ids)->orderBy('id')->lockForUpdate()->get()->map(fn ($row): array => (array) $row)->all();

        $periodIds = collect([$return->financial_period_id, request()->session()->get(OperatingContextService::FinancialPeriodIdKey)])
            ->concat($allocations->pluck('financial_period_id'))->concat($refunds->pluck('financial_period_id'))->filter()->unique()->all();

        return ['return' => $return->getAttributes(), 'lines' => $returnLines->map->getAttributes()->all(),
            'periods' => $rows('financial_periods', 'id', $periodIds),
            'products' => $rows('products', 'id', $returnLines->pluck('product_id')->unique()->all()),
            'units' => $rows('item_units', 'id', $returnLines->pluck('unit_id')->filter()->unique()->all()),
            'deliveries' => $deliveryDocuments->map->getAttributes()->all(), 'delivery_lines' => $rows('inventory_document_lines', 'inventory_document_id', $deliveryIds),
            'delivery_transactions' => InventoryTransaction::query()->where('source_type', InventoryDocument::class)->whereIn('source_id', $deliveryIds)
                ->orderBy('id')->lockForUpdate()->get()->map->getAttributes()->all(),
            'cost_proposals' => $costProposals->map->getAttributes()->all(), 'adjustments' => $adjustments->map->getAttributes()->all(),
            'adjustment_lines' => $rows('inventory_value_adjustment_lines', 'inventory_value_adjustment_id', $adjustments->modelKeys()),
            'invoices' => $invoices->map->getAttributes()->all(), 'invoice_lines' => $rows('customer_invoice_lines', 'customer_invoice_id', $invoices->modelKeys()),
            'schedules' => $rows('customer_invoice_payment_schedules', 'customer_invoice_id', $invoices->modelKeys()),
            'allocations' => $allocations->map->getAttributes()->all(), 'refunds' => $refunds->map->getAttributes()->all(),
            'documents' => $documents->map->getAttributes()->all(), 'document_lines' => $rows('inventory_document_lines', 'inventory_document_id', $documents->modelKeys()),
            'transactions' => $transactions->map->getAttributes()->all(), 'journals' => $journals->map->getAttributes()->all(),
            'journal_lines' => $rows('journal_entry_lines', 'journal_entry_id', $journals->modelKeys()),
            'layers' => $rows('inventory_receipt_layers', 'receipt_transaction_id', $transactions->modelKeys()),
            'layer_allocations' => $rows('inventory_layer_allocations', 'issue_transaction_id', $transactions->modelKeys()),
            'order_lines' => $return->sales_order_id === null ? [] : $rows('sales_order_lines', 'sales_order_id', [(int) $return->sales_order_id])];
    }

    /** @param array<mixed> $rows
     * @return list<array{sales_return_line_id:int,quantity:string}>
     */
    private function replacementPayload(SalesReturn $return, array $rows): array
    {
        $lines = $return->lines()->get()->keyBy('id');
        $payload = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ctype_digit((string) ($row['sales_return_line_id'] ?? ''))
                || ! is_string($row['quantity'] ?? null) || preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $row['quantity']) !== 1
                || ! $lines->has((int) $row['sales_return_line_id']) || isset($payload[$row['sales_return_line_id']])) {
                throw new DomainException(__('sales_return_plan.replacement_invalid'));
            }
            $payload[$row['sales_return_line_id']] = ['sales_return_line_id' => (int) $row['sales_return_line_id'], 'quantity' => bcadd($row['quantity'], '0', 8)];
        }
        $payload = array_filter($payload, fn ($row): bool => bccomp($row['quantity'], '0', 8) > 0);
        ksort($payload);
        if ($payload === []) {
            throw new DomainException(__('sales_return_plan.replacement_invalid'));
        }

        return array_values($payload);
    }

    /** @param array<mixed> $payload
     * @return array<mixed>
     */
    private function invoiceReplacementLines(SalesReturn $return, array $payload): array
    {
        Gate::authorize('sales_returns.create');
        $lines = $return->lines()->get()->keyBy('id');
        $inputs = [];
        foreach ($payload as $row) {
            $line = $lines->get($row['sales_return_line_id']);
            $id = (int) $line->customer_invoice_line_id;
            $inputs[$id] ??= ['customer_invoice_line_id' => $id, 'quantity' => '0.00000000', 'delivery_line_ids' => []];
            $inputs[$id]['quantity'] = bcadd($inputs[$id]['quantity'], $row['quantity'], 8);
            if ($line->product?->tracks_serials && $line->delivery_line_id !== null) {
                $inputs[$id]['delivery_line_ids'][] = (int) $line->delivery_line_id;
            }
        }

        return array_values($inputs);
    }

    /** @param array<mixed> $payload
     * @return array<mixed>
     */
    private function deliveryReplacementLines(SalesReturn $return, array $payload): array
    {
        Gate::authorize('sales_returns.create');
        $lines = $return->lines()->get()->keyBy('id');

        return array_map(fn ($row): array => ['delivery_line_id' => (int) $lines->get($row['sales_return_line_id'])->delivery_line_id, 'quantity' => $row['quantity']], $payload);
    }

    private function assertSeal(SalesReturnCorrection $proposal): void
    {
        if (! hash_equals($proposal->source_fingerprint, $this->digest($proposal->source_snapshot))
            || ! hash_equals($proposal->proposal_fingerprint, $this->proposalDigest($proposal))
            || (in_array($proposal->status, ['applying', 'approved'], true)
                && ($proposal->approved_at === null || (int) $proposal->prepared_by === (int) $proposal->approved_by
                    || ! is_string($proposal->approval_fingerprint) || ! hash_equals($proposal->approval_fingerprint, $this->approvalDigest($proposal))))
            || ($proposal->status === 'approved' && (! is_array($proposal->execution_snapshot)
                || ! is_string($proposal->execution_fingerprint) || ! hash_equals($proposal->execution_fingerprint, $this->digest($proposal->execution_snapshot))))) {
            throw new DomainException(__('sales_return_plan.stale'));
        }
    }

    private function proposalDigest(SalesReturnCorrection $proposal): string
    {
        return $this->digest(['company' => (int) $proposal->company_id, 'branch' => (int) $proposal->branch_id, 'return' => (int) $proposal->sales_return_id,
            'source_period' => (int) $proposal->source_financial_period_id, 'target_period' => (int) $proposal->posting_financial_period_id,
            'operation' => $proposal->operation, 'allocation' => $proposal->allocation_id === null ? null : (int) $proposal->allocation_id,
            'refund' => $proposal->refund_id === null ? null : (int) $proposal->refund_id, 'date' => $proposal->posting_date->toDateString(),
            'reason' => $proposal->reason, 'reference' => $proposal->recovery_reference, 'replacement' => $proposal->replacement_payload,
            'source' => $proposal->source_fingerprint, 'preparer' => (int) $proposal->prepared_by]);
    }

    private function approvalDigest(SalesReturnCorrection $proposal): string
    {
        return $this->digest(['proposal' => $proposal->proposal_fingerprint, 'approver' => (int) $proposal->approved_by,
            'at' => $proposal->approved_at?->toISOString(), 'reason' => $proposal->approval_reason,
            'execution' => $proposal->execution_fingerprint, 'replacement' => $proposal->replacement_return_id === null ? null : (int) $proposal->replacement_return_id]);
    }

    /** @param array<mixed> $value */
    private function digest(array $value): string
    {
        return hash_hmac('sha256', 'mgypack.sales-return-correction.v1:'.json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
