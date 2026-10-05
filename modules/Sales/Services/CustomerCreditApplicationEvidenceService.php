<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Sales\Models\CustomerCreditAllocation;
use Modules\Sales\Models\CustomerCreditApplicationEvidence;
use Modules\Sales\Models\CustomerCreditRefund;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesReturn;

final class CustomerCreditApplicationEvidenceService
{
    public function __construct(private readonly SalesCycleAuditService $audit) {}

    /** @return array<string, mixed> */
    public function preview(CustomerInvoice $credit): array
    {
        abort_unless(Gate::any(['customer_credits.prepare_application_evidence', 'customer_credits.approve_application_evidence']), 403);

        return DB::transaction(function () use ($credit): array {
            $this->lockCompany($credit);

            $credit = CustomerInvoice::query()->lockForUpdate()->findOrFail($credit->getKey());
            if ($credit->document_type === CustomerInvoice::TypeCreditNote && $credit->posting_status === 'reversed') {
                $this->assertApprovedApplication($credit);
                if (! is_array($credit->credit_application_snapshot)) {
                    throw new DomainException(__('credit_application_evidence.source_invalid'));
                }
                $invoice = CustomerInvoice::query()->where('company_id', $credit->company_id)
                    ->where('branch_id', $credit->branch_id)->findOrFail($credit->original_invoice_id);

                return ['credit' => $credit, 'invoice' => $invoice,
                    'applied' => $credit->credit_application_snapshot['applied_to_original'],
                    'schedules' => $invoice->paymentSchedules()->orderBy('id')->get()];
            }

            return $this->source($credit);
        });
    }

    /** @param array<string, mixed> $data */
    public function prepare(CustomerInvoice $credit, array $data): CustomerCreditApplicationEvidence
    {
        Gate::authorize('customer_credits.prepare_application_evidence');

        return DB::transaction(function () use ($credit, $data): CustomerCreditApplicationEvidence {
            $this->lockCompany($credit);
            $source = $this->source($credit);
            if ($source['credit']->credit_application_snapshot !== null
                || ! hash_equals($source['fingerprint'], (string) ($data['source_fingerprint'] ?? ''))
                || trim((string) ($data['source_reference'] ?? '')) === '' || trim((string) ($data['reason'] ?? '')) === '') {
                throw new DomainException(__('credit_application_evidence.stale'));
            }
            $application = $this->application($source, $data['schedules'] ?? []);
            $evidence = new CustomerCreditApplicationEvidence([
                'company_id' => $credit->company_id, 'branch_id' => $credit->branch_id,
                'credit_note_id' => $credit->getKey(), 'original_invoice_id' => $source['invoice']->getKey(),
                'status' => CustomerCreditApplicationEvidence::StatusPending,
                'source_reference' => trim($data['source_reference']), 'reason' => trim($data['reason']),
                'source_fingerprint' => $source['fingerprint'], 'source_snapshot' => $source['snapshot'],
                'application_snapshot' => $application, 'prepared_by' => auth()->id(),
            ]);
            $evidence->proposal_fingerprint = $this->proposalFingerprint($evidence);
            $evidence->save();
            $this->audit->record($evidence, 'customer_credit.application_evidence_prepared', ['credit_note' => $credit->doc_num]);

            return $evidence;
        }, 3);
    }

    public function approve(CustomerInvoice $credit, int $evidenceId, string $reason): CustomerCreditApplicationEvidence
    {
        Gate::authorize('customer_credits.approve_application_evidence');

        return DB::transaction(function () use ($credit, $evidenceId, $reason): CustomerCreditApplicationEvidence {
            $this->lockCompany($credit);
            $locked = CustomerInvoice::query()->lockForUpdate()->findOrFail($credit->getKey());
            $evidence = CustomerCreditApplicationEvidence::query()->where('company_id', $credit->company_id)
                ->where('branch_id', $credit->branch_id)->where('credit_note_id', $credit->getKey())
                ->lockForUpdate()->findOrFail($evidenceId);
            if ((int) $evidence->prepared_by === (int) auth()->id() || trim($reason) === '') {
                throw new DomainException(__('credit_application_evidence.independent'));
            }
            if ($evidence->status === CustomerCreditApplicationEvidence::StatusApproved) {
                $this->assertApprovedApplication($locked);
                if ((int) data_get($locked->credit_application_snapshot, 'evidence.id') !== $evidenceId) {
                    throw new DomainException(__('credit_application_evidence.stale'));
                }

                return $evidence;
            }
            $source = $this->source($locked);
            if ($locked->credit_application_snapshot !== null || $evidence->status !== CustomerCreditApplicationEvidence::StatusPending
                || (int) $evidence->original_invoice_id !== (int) $source['invoice']->getKey()
                || ! hash_equals($evidence->proposal_fingerprint, $this->proposalFingerprint($evidence))
                || ! hash_equals($evidence->source_fingerprint, hash('sha256', json_encode($evidence->source_snapshot, JSON_THROW_ON_ERROR)))
                || ! hash_equals($evidence->source_fingerprint, $source['fingerprint'])
                || $this->application($source, $evidence->application_snapshot['schedules'] ?? []) !== $evidence->application_snapshot) {
                throw new DomainException(__('credit_application_evidence.stale'));
            }
            $evidence->forceFill(['status' => CustomerCreditApplicationEvidence::StatusApproved,
                'approved_by' => auth()->id(), 'approved_at' => now()->startOfSecond(), 'approval_reason' => trim($reason)]);
            $evidence->approval_fingerprint = $this->approvalFingerprint($evidence);
            $evidence->save();
            $locked->forceFill(['credit_application_snapshot' => [
                ...$evidence->application_snapshot,
                'evidence' => ['type' => CustomerCreditApplicationEvidence::class, 'id' => $evidence->getKey(),
                    'reference' => $evidence->source_reference, 'prepared_by' => (int) $evidence->prepared_by,
                    'approved_by' => (int) $evidence->approved_by, 'approved_at' => $evidence->approved_at->toISOString()],
            ]])->save();
            $this->audit->record($evidence, 'customer_credit.application_evidence_approved', ['credit_note' => $credit->doc_num]);

            return $evidence;
        }, 3);
    }

    public function assertApprovedApplication(CustomerInvoice $credit): void
    {
        $application = $credit->credit_application_snapshot;
        $proof = is_array($application) ? ($application['evidence'] ?? null) : null;
        $approved = CustomerCreditApplicationEvidence::query()->where('credit_note_id', $credit->getKey())
            ->where('status', CustomerCreditApplicationEvidence::StatusApproved)->get();
        if ($proof === null && $approved->isEmpty()) {
            return;
        }
        $evidence = is_array($proof) ? $approved->firstWhere('id', $proof['id'] ?? 0) : null;
        if (! $evidence instanceof CustomerCreditApplicationEvidence || $approved->count() !== 1
            || ($proof['type'] ?? null) !== CustomerCreditApplicationEvidence::class
            || (int) $evidence->company_id !== (int) $credit->company_id || (int) $evidence->branch_id !== (int) $credit->branch_id
            || (int) $evidence->original_invoice_id !== (int) $credit->original_invoice_id
            || $evidence->approved_at === null || $evidence->approved_by === null
            || (int) $evidence->prepared_by === (int) $evidence->approved_by
            || (int) ($proof['prepared_by'] ?? 0) !== (int) $evidence->prepared_by
            || (int) ($proof['approved_by'] ?? 0) !== (int) $evidence->approved_by
            || ($proof['reference'] ?? null) !== $evidence->source_reference
            || ! hash_equals($evidence->proposal_fingerprint, $this->proposalFingerprint($evidence))
            || ! hash_equals($evidence->source_fingerprint, hash('sha256', json_encode($evidence->source_snapshot, JSON_THROW_ON_ERROR)))
            || ! is_string($evidence->approval_fingerprint)
            || ! hash_equals($evidence->approval_fingerprint, $this->approvalFingerprint($evidence))
            || ($proof['approved_at'] ?? null) !== $evidence->approved_at->toISOString()) {
            throw new DomainException(__('credit_application_evidence.stale'));
        }
        unset($application['evidence']);
        if ($application !== $evidence->application_snapshot) {
            throw new DomainException(__('credit_application_evidence.stale'));
        }
    }

    private function lockCompany(CustomerInvoice $credit): void
    {
        $company = app(OperatingCompanyContextService::class)->currentCompany();
        $scope = app(OperatingScopeAccessService::class);
        abort_unless($company !== null && (int) $company->id === (int) $credit->company_id
            && (int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $credit->branch_id
            && $scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $credit->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])
                ->where('financial_periods.id', $credit->financial_period_id)->exists(), 404);
        Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function source(CustomerInvoice $credit): array
    {
        $credit = CustomerInvoice::query()->with('journalEntry.lines')->lockForUpdate()->findOrFail($credit->getKey());
        $invoice = CustomerInvoice::query()->lockForUpdate()->find($credit->original_invoice_id);
        $return = SalesReturn::query()->lockForUpdate()->find($credit->sales_return_id);
        $journal = $credit->journalEntry;
        if ($credit->document_type !== CustomerInvoice::TypeCreditNote || $credit->status !== CustomerInvoice::StatusPosted
            || $credit->posting_status !== 'posted' || $credit->reversal_journal_entry_id !== null
            || ! $invoice instanceof CustomerInvoice || $invoice->document_type !== CustomerInvoice::TypeInvoice
            || $invoice->posting_status !== 'posted' || $invoice->status !== CustomerInvoice::StatusPosted
            || $invoice->reversal_journal_entry_id !== null
            || (int) $invoice->currency_id !== (int) $credit->currency_id
            || bccomp((string) $invoice->exchange_rate, (string) $credit->exchange_rate, 6) !== 0
            || ! $return instanceof SalesReturn || $return->status !== SalesReturn::StatusClosed
            || (int) $return->credit_note_id !== (int) $credit->id || (int) $return->customer_invoice_id !== (int) $invoice->id
            || ! $journal instanceof JournalEntry || $journal->status !== JournalEntry::StatusPosted || ! $journal->is_posted
            || $journal->reversed_entry_id !== null || $journal->source_type !== 'customer_credit_note'
            || (int) $journal->source_id !== (int) $credit->id || $journal->source_doc_num !== $credit->doc_num
            || (int) $journal->company_id !== (int) $credit->company_id || (int) $journal->branch_id !== (int) $credit->branch_id
            || (int) $journal->financial_period_id !== (int) $credit->financial_period_id
            || (int) $journal->currency_id !== (int) $credit->currency_id
            || bccomp((string) $journal->exchange_rate, (string) $credit->exchange_rate, 6) !== 0
            || $journal->entry_date?->toDateString() !== $credit->invoice_date?->toDateString()) {
            throw new DomainException(__('credit_application_evidence.source_invalid'));
        }
        foreach ([$invoice, $return] as $owner) {
            if ((int) $owner->company_id !== (int) $credit->company_id || (int) $owner->branch_id !== (int) $credit->branch_id
                || (int) $owner->customer_id !== (int) $credit->customer_id) {
                throw new DomainException(__('credit_application_evidence.source_invalid'));
            }
        }
        $schedules = $invoice->paymentSchedules()->orderBy('id')->lockForUpdate()->get();
        $allocations = $credit->creditAllocations()->orderBy('id')->lockForUpdate()->get();
        $refunds = $credit->creditRefunds()->orderBy('id')->lockForUpdate()->get();
        $allocated = $this->sum($allocations->where('status', CustomerCreditAllocation::StatusApplied)->pluck('amount')->all());
        $refunded = $this->sum($refunds->where('status', CustomerCreditRefund::StatusPosted)->pluck('amount')->all());
        $applied = bcsub(bcsub(bcsub((string) $credit->total_amount, (string) $credit->credit_available_amount, 4), $allocated, 4), $refunded, 4);
        $invoiceAllocations = CustomerCreditAllocation::query()->where('target_invoice_id', $invoice->id)
            ->where('status', CustomerCreditAllocation::StatusApplied)->orderBy('id')->lockForUpdate()->get();
        $otherCredits = CustomerInvoice::query()->where('original_invoice_id', $invoice->id)->whereKeyNot($credit->id)
            ->where('document_type', CustomerInvoice::TypeCreditNote)->where('posting_status', 'posted')->orderBy('id')->lockForUpdate()->get();
        $reserved = [];
        foreach ($invoiceAllocations as $allocation) {
            if ((int) $allocation->company_id !== (int) $invoice->company_id || (int) $allocation->customer_id !== (int) $invoice->customer_id
                || $schedules->firstWhere('id', $allocation->target_payment_schedule_id) === null || bccomp($allocation->amount, '0', 4) <= 0) {
                throw new DomainException(__('credit_application_evidence.source_invalid'));
            }
            $reserved[$allocation->target_payment_schedule_id] = bcadd($reserved[$allocation->target_payment_schedule_id] ?? '0', $allocation->amount, 4);
        }
        foreach ($otherCredits as $other) {
            if ((int) $other->company_id !== (int) $invoice->company_id || (int) $other->customer_id !== (int) $invoice->customer_id
                || (int) $other->branch_id !== (int) $invoice->branch_id || (int) $other->currency_id !== (int) $invoice->currency_id) {
                throw new DomainException(__('credit_application_evidence.source_invalid'));
            }
            $this->assertApprovedApplication($other);
            if ($other->credit_application_snapshot === null) {
                continue;
            }
            $application = $other->credit_application_snapshot;
            if (! is_array($application) || (int) ($application['original_invoice_id'] ?? 0) !== (int) $invoice->id
                || ! is_array($application['schedules'] ?? null)) {
                throw new DomainException(__('credit_application_evidence.source_invalid'));
            }
            $seen = [];
            foreach ($application['schedules'] as $row) {
                if (! is_array($row) || ! ctype_digit((string) ($row['schedule_id'] ?? ''))
                    || ! is_string($row['amount'] ?? null) || preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D', $row['amount']) !== 1
                    || isset($seen[$row['schedule_id']]) || $schedules->firstWhere('id', (int) $row['schedule_id']) === null) {
                    throw new DomainException(__('credit_application_evidence.source_invalid'));
                }
                $seen[$row['schedule_id']] = true;
                $reserved[$row['schedule_id']] = bcadd($reserved[$row['schedule_id']] ?? '0', $row['amount'], 4);
            }
            if (! is_string($application['applied_to_original'] ?? null)
                || preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D', $application['applied_to_original']) !== 1
                || bccomp($this->sum(array_column($application['schedules'], 'amount')), $application['applied_to_original'], 4) !== 0) {
                throw new DomainException(__('credit_application_evidence.source_invalid'));
            }
        }
        foreach ($schedules as $schedule) {
            if (bccomp($reserved[$schedule->id] ?? '0', (string) $schedule->credited_amount, 4) > 0) {
                throw new DomainException(__('credit_application_evidence.source_invalid'));
            }
        }
        $arLines = $journal->lines->filter(fn ($line): bool => (int) $line->customer_id === (int) $credit->customer_id);
        if ($allocations->contains(fn ($row): bool => ! in_array($row->status, [CustomerCreditAllocation::StatusApplied, CustomerCreditAllocation::StatusReversed], true))
            || $refunds->contains(fn ($row): bool => ! in_array($row->status, [CustomerCreditRefund::StatusPosted, CustomerCreditRefund::StatusReversed], true))
            || bccomp($allocated, (string) $credit->credit_allocated_amount, 4) !== 0
            || bccomp($refunded, (string) $credit->credit_refunded_amount, 4) !== 0
            || bccomp($applied, '0', 4) < 0 || bccomp($applied, (string) $invoice->credited_amount, 4) > 0
            || bccomp((string) $credit->total_amount, (string) $return->total_amount, 4) !== 0
            || bccomp((string) $invoice->remaining_amount, app(CustomerInvoiceBalanceService::class)->remaining($invoice), 4) !== 0
            || bccomp($this->sum($journal->lines->pluck('debit_amount')->all()), (string) $credit->total_amount, 4) !== 0
            || bccomp($this->sum($journal->lines->pluck('credit_amount')->all()), (string) $credit->total_amount, 4) !== 0
            || $arLines->count() !== 1 || bccomp((string) $arLines->first()?->credit_amount, (string) $credit->total_amount, 4) !== 0
            || bccomp((string) $arLines->first()?->debit_amount, '0', 4) !== 0
            || $journal->lines->contains(fn ($line): bool => bccomp((string) $line->debit_amount, '0', 4) < 0 || bccomp((string) $line->credit_amount, '0', 4) < 0)
            || bccomp($this->sum($schedules->pluck('credited_amount')->all()), (string) $invoice->credited_amount, 4) !== 0) {
            throw new DomainException(__('credit_application_evidence.source_invalid'));
        }
        foreach (['total_amount', 'credit_available_amount', 'credit_allocated_amount', 'credit_refunded_amount'] as $field) {
            if (bccomp((string) $credit->{$field}, '0', 4) < 0) {
                throw new DomainException(__('credit_application_evidence.source_invalid'));
            }
        }
        $snapshot = ['credit' => $credit->getAttributes(), 'invoice' => $invoice->getAttributes(), 'return' => $return->getAttributes(),
            'schedules' => $schedules->map->getAttributes()->all(), 'allocations' => $allocations->map->getAttributes()->all(),
            'refunds' => $refunds->map->getAttributes()->all(), 'journal' => $journal->getAttributes(),
            'journal_lines' => $journal->lines->sortBy('id')->map->getAttributes()->values()->all(),
            'invoice_allocations' => $invoiceAllocations->map->getAttributes()->all(), 'other_credits' => $otherCredits->map->getAttributes()->all()];

        return compact('credit', 'invoice', 'return', 'schedules', 'snapshot', 'applied', 'reserved') +
            ['fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    /** @param array<string, mixed> $source
     * @param  array<mixed>  $rows
     * @return array<string, mixed>
     */
    private function application(array $source, array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ctype_digit((string) ($row['schedule_id'] ?? ''))
                || ! is_string($row['amount'] ?? null) || preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D', $row['amount']) !== 1) {
                throw new DomainException(__('credit_application_evidence.amount_invalid'));
            }
            $id = (int) $row['schedule_id'];
            $schedule = $source['schedules']->firstWhere('id', $id);
            if ($schedule === null || isset($normalized[$id])
                || bccomp($row['amount'], bcsub((string) $schedule->credited_amount, $source['reserved'][$id] ?? '0', 4), 4) > 0) {
                throw new DomainException(__('credit_application_evidence.amount_invalid'));
            }
            $normalized[$id] = ['schedule_id' => $id, 'amount' => bcadd($row['amount'], '0', 4)];
        }
        ksort($normalized);
        $normalized = array_values(array_filter($normalized, fn (array $row): bool => bccomp($row['amount'], '0', 4) > 0));
        if (bccomp($this->sum(array_column($normalized, 'amount')), $source['applied'], 4) !== 0) {
            throw new DomainException(__('credit_application_evidence.amount_invalid'));
        }

        return ['original_invoice_id' => (int) $source['invoice']->id, 'applied_to_original' => $source['applied'], 'schedules' => $normalized];
    }

    /** @param array<string|int|float> $amounts */
    private function sum(array $amounts): string
    {
        return array_reduce($amounts, fn (string $total, mixed $amount): string => bcadd($total, (string) $amount, 4), '0.0000');
    }

    private function proposalFingerprint(CustomerCreditApplicationEvidence $evidence): string
    {
        return hash('sha256', json_encode([
            'company_id' => (int) $evidence->company_id, 'branch_id' => (int) $evidence->branch_id,
            'credit_note_id' => (int) $evidence->credit_note_id, 'original_invoice_id' => (int) $evidence->original_invoice_id,
            'source_reference' => $evidence->source_reference, 'reason' => $evidence->reason, 'prepared_by' => (int) $evidence->prepared_by,
            'source_fingerprint' => $evidence->source_fingerprint, 'source_snapshot' => $evidence->source_snapshot,
            'application_snapshot' => $evidence->application_snapshot,
        ], JSON_THROW_ON_ERROR));
    }

    private function approvalFingerprint(CustomerCreditApplicationEvidence $evidence): string
    {
        return hash('sha256', json_encode(['proposal_fingerprint' => $evidence->proposal_fingerprint,
            'status' => $evidence->status, 'approved_by' => (int) $evidence->approved_by,
            'approved_at' => $evidence->approved_at?->toISOString(), 'approval_reason' => $evidence->approval_reason], JSON_THROW_ON_ERROR));
    }
}
