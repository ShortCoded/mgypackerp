<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Finance\Models\Cheque;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\CustomerWithholdingSettlement;
use Modules\Sales\Services\CustomerReceiptApplicationHistoryService;
use Modules\Sales\Services\CustomerReceiptSettlementService;

final class ChequeCollectionCorrectionService
{
    /** @return array<string, mixed> */
    public function preview(Cheque $cheque): array
    {
        Gate::authorize('cheques.view');
        $cheque = $this->scoped($cheque);
        $this->schema();
        $blockers = [];
        try {
            $receipt = $this->receipt($cheque);
            $snapshot = $this->snapshot($cheque, $receipt);
        } catch (DomainException $exception) {
            $blockers[] = $exception->getMessage();
            $snapshot = [];
        }

        return ['record' => $cheque, 'blockers' => $blockers, 'fingerprint' => $this->digest($snapshot),
            'corrections' => DB::table('cheque_collection_corrections')->where('company_id', $cheque->company_id)->where('cheque_id', $cheque->id)->orderByDesc('id')->get()];
    }

    /** @param array<string, string> $data */
    public function prepare(Cheque $cheque, array $data): object
    {
        $this->authorize();
        $this->schema();

        return DB::transaction(function () use ($cheque, $data): object {
            $cheque = $this->locked($cheque);
            $receipt = $this->receipt($cheque);
            if ($cheque->status !== Cheque::StatusCollected || ! in_array($data['treatment'], ['bank_reversal', 'collection_entry_error'], true)
                || mb_strlen(trim($data['reason'])) < 5 || mb_strlen(trim($data['evidence'])) < 5 || blank($data['bank_reference'])) {
                throw new DomainException(__('cheque_collection_correction.evidence_required'));
            }
            $period = $this->period($receipt);
            $source = $this->snapshot($cheque, $receipt);
            if (! hash_equals($this->digest($source), $data['fingerprint'])) {
                throw new DomainException(__('cheque_collection_correction.stale'));
            }
            if (DB::table('cheque_collection_corrections')->where('company_id', $cheque->company_id)->where('cheque_id', $cheque->id)->whereIn('status', ['prepared', 'approved'])->exists()) {
                throw new DomainException(__('cheque_collection_correction.pending'));
            }
            $proposal = ['public_id' => (string) Str::uuid(), 'company_id' => $cheque->company_id, 'branch_id' => $receipt->branch_id,
                'cheque_id' => $cheque->id, 'customer_receipt_id' => $receipt->id, 'financial_period_id' => $receipt->financial_period_id,
                'posting_financial_period_id' => $period->id, 'original_journal_entry_id' => $receipt->journal_entry_id,
                'posting_date' => $receipt->status === CustomerReceipt::StatusCancelled
                    ? JournalEntry::query()->findOrFail($receipt->reversal_journal_entry_id)->entry_date->toDateString() : now()->toDateString(),
                'treatment' => $data['treatment'], 'bank_reference' => trim($data['bank_reference']),
                'reason' => trim($data['reason']), 'evidence' => trim($data['evidence']), 'prepared_by' => auth()->id(), 'source_snapshot' => $source];
            $id = DB::table('cheque_collection_corrections')->insertGetId([...$proposal, 'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR),
                'proposal_seal' => $this->digest($proposal), 'status' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($cheque, 'prepared', ['correction_id' => $id, 'proposal_seal' => $this->digest($proposal)]);

            return DB::table('cheque_collection_corrections')->find($id);
        }, 3);
    }

    public function approve(Cheque $cheque, int $id): object
    {
        $this->authorize();

        return DB::transaction(function () use ($cheque, $id): object {
            $cheque = $this->locked($cheque);
            $proposal = $this->proposal($cheque, $id);
            if ((int) $proposal->prepared_by === (int) auth()->id()) {
                throw new DomainException(__('cheque_collection_correction.independent'));
            }
            if ($proposal->status === 'approved') {
                return $this->assertApproved($cheque);
            }
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('cheque_collection_correction.stale'));
            }
            $receipt = $this->receipt($cheque);
            if ($receipt->status === CustomerReceipt::StatusApproved && $proposal->posting_date !== now()->toDateString()) {
                throw new DomainException(__('cheque_collection_correction.stale'));
            }
            $period = $this->period($receipt);
            if ((int) $period->id !== (int) $proposal->posting_financial_period_id
                || ! hash_equals($this->digest(json_decode($proposal->source_snapshot, true, flags: JSON_THROW_ON_ERROR)), $this->digest($this->snapshot($cheque, $receipt)))) {
                throw new DomainException(__('cheque_collection_correction.stale'));
            }
            $receipt = app(CustomerReceiptSettlementService::class)->reverse($receipt, $proposal->reason.' — '.$proposal->bank_reference.' — '.$proposal->evidence);
            $cheque->update(['status' => Cheque::StatusCollectionReversed, 'updated_by' => auth()->id()]);
            $inverse = JournalEntry::query()->with('lines')->findOrFail($receipt->reversal_journal_entry_id);
            $execution = ['receipt' => $receipt->getRawOriginal(), 'inverse' => $inverse->getRawOriginal(),
                'inverse_lines' => $inverse->lines->sortBy('id')->map->getRawOriginal()->values()->all(),
                'allocations' => $receipt->allocations()->orderBy('id')->get()->map->getRawOriginal()->all(),
                'events' => $this->events($receipt), 'collected_at' => $cheque->getRawOriginal('collected_at')];
            DB::table('cheque_collection_corrections')->where('id', $id)->update(['status' => 'approved', 'approved_by' => auth()->id(),
                'approved_at' => now(), 'reversal_journal_entry_id' => $inverse->id, 'execution_snapshot' => json_encode($execution, JSON_THROW_ON_ERROR),
                'execution_seal' => $this->digest($execution), 'updated_at' => now()]);
            $this->audit($cheque, 'approved', ['correction_id' => $id, 'proposal_seal' => $proposal->proposal_seal,
                'execution_seal' => $this->digest($execution), 'reversal_journal_entry_id' => $inverse->id]);

            return $this->assertApproved($cheque->fresh());
        }, 3);
    }

    public function reject(Cheque $cheque, int $id): void
    {
        $this->authorize();
        DB::transaction(function () use ($cheque, $id): void {
            $cheque = $this->locked($cheque);
            $proposal = $this->proposal($cheque, $id);
            if ($proposal->status !== 'prepared') {
                throw new DomainException(__('cheque_collection_correction.stale'));
            }
            DB::table('cheque_collection_corrections')->where('id', $id)->update(['status' => 'rejected', 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'updated_at' => now()]);
            $this->audit($cheque, 'rejected', ['correction_id' => $id, 'proposal_seal' => $proposal->proposal_seal]);
        }, 3);
    }

    public function assertApproved(Cheque $cheque): object
    {
        $this->schema();
        $owners = DB::table('cheque_collection_corrections')->where('company_id', $cheque->company_id)->where('cheque_id', $cheque->id)->where('status', 'approved')->get();
        if ($owners->count() !== 1) {
            throw new DomainException(__('cheque_collection_correction.invalid_owner'));
        }
        $proposal = $this->proposal($cheque, (int) $owners->sole()->id);
        $receipt = $this->receipt($cheque);
        $execution = json_decode($proposal->execution_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $original = JournalEntry::query()->findOrFail($proposal->original_journal_entry_id);
        $inverse = JournalEntry::query()->with('lines')->findOrFail($proposal->reversal_journal_entry_id);
        app(JournalEntryService::class)->assertPostedReversal($original, $inverse);
        app(CustomerReceiptApplicationHistoryService::class)->assertEvidence((int) $cheque->company_id, (int) $receipt->id);
        $source = json_decode($proposal->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (! in_array($cheque->status, [Cheque::StatusCollectionReversed, Cheque::StatusCancelled], true)
            || $proposal->approved_at === null || (int) $proposal->prepared_by === (int) $proposal->approved_by
            || (int) $receipt->id !== (int) $proposal->customer_receipt_id || (int) $receipt->reversal_journal_entry_id !== (int) $inverse->id
            || (int) $inverse->financial_period_id !== (int) $proposal->posting_financial_period_id || $inverse->entry_date->toDateString() !== $proposal->posting_date
            || $execution !== ['receipt' => $receipt->getRawOriginal(), 'inverse' => $inverse->getRawOriginal(),
                'inverse_lines' => $inverse->lines->sortBy('id')->map->getRawOriginal()->values()->all(),
                'allocations' => $receipt->allocations()->orderBy('id')->get()->map->getRawOriginal()->all(), 'events' => $this->events($receipt), 'collected_at' => $cheque->getRawOriginal('collected_at')]
            || ! hash_equals($proposal->execution_seal, $this->digest($execution))
            || $source['journal_lines'] !== $original->lines()->orderBy('id')->get()->map->getRawOriginal()->all()
            || $source['lines'] !== $cheque->lines()->orderBy('id')->get()->map->getRawOriginal()->all()
            || ! DB::table('activity_log')->where('company_id', $cheque->company_id)->where('subject_type', Cheque::class)->where('subject_id', $cheque->id)
                ->where('event', 'finance.cheque_collection_correction.approved')->where('causer_id', $proposal->approved_by)
                ->where('properties->correction_id', $proposal->id)->where('properties->execution_seal', $proposal->execution_seal)->exists()) {
            throw new DomainException(__('cheque_collection_correction.invalid_owner'));
        }

        return $proposal;
    }

    private function receipt(Cheque $cheque): CustomerReceipt
    {
        $receipts = CustomerReceipt::withTrashed()->where('cheque_id', $cheque->id)->orderBy('id')->lockForUpdate()->get();
        if (! $cheque->isReceived() || $cheque->trashed() || $cheque->collected_at === null || $receipts->count() !== 1) {
            throw new DomainException(__('cheque_collection_correction.native_receipt_required'));
        }
        $receipt = $receipts->sole();
        $journal = $receipt->journalEntry;
        if ($receipt->trashed() || (int) $receipt->company_id !== (int) $cheque->company_id || $cheque->party_type !== 'customer'
            || (int) $receipt->customer_id !== (int) $cheque->party_id || (int) $receipt->currency_id !== (int) $cheque->currency_id
            || bccomp((string) $receipt->amount, (string) $cheque->amount, 4) !== 0 || bccomp((string) $receipt->exchange_rate, (string) $cheque->exchange_rate, 6) !== 0
            || ! in_array($receipt->status, [CustomerReceipt::StatusApproved, CustomerReceipt::StatusCancelled], true)
            || $journal === null || $journal->deleted_at !== null || $journal->status !== JournalEntry::StatusPosted || ! $journal->is_posted || ! $journal->is_system_generated
            || ! in_array($journal->source_type, ['customer_receipt', 'customer_receipt_clearing'], true)
            || (int) $journal->source_id !== (int) $receipt->id || (int) $journal->company_id !== (int) $receipt->company_id
            || (int) $journal->branch_id !== (int) $receipt->branch_id || (int) $journal->currency_id !== (int) $receipt->currency_id
            || CustomerWithholdingSettlement::query()->where('customer_receipt_id', $receipt->id)->where('status', 'approved')->exists()) {
            throw new DomainException(__('cheque_collection_correction.native_receipt_required'));
        }
        $lines = $journal->lines()->orderBy('line_no')->get();
        if ($receipt->cashbox_id !== null || $receipt->bank_account_id === null || (int) $receipt->bank_account_id !== (int) $cheque->bank_account_id
            || $lines->count() !== 2 || (int) $lines[0]->bank_account_id !== (int) $receipt->bank_account_id
            || $lines[0]->customer_id !== null || (int) $lines[1]->customer_id !== (int) $receipt->customer_id || $lines[1]->bank_account_id !== null
            || bccomp((string) $journal->exchange_rate, (string) $receipt->exchange_rate, 6) !== 0
            || bccomp((string) $lines[0]->debit_amount, (string) $receipt->amount, 4) !== 0 || bccomp((string) $lines[0]->credit_amount, '0', 4) !== 0
            || bccomp((string) $lines[1]->credit_amount, (string) $receipt->amount, 4) !== 0 || bccomp((string) $lines[1]->debit_amount, '0', 4) !== 0
            || $lines->contains(fn ($line): bool => $line->supplier_id !== null || (int) ($line->branch_id ?? $journal->branch_id) !== (int) $receipt->branch_id
                || ($line->employee_id === null ? null : (int) $line->employee_id) !== ($receipt->received_by_employee_id === null ? null : (int) $receipt->received_by_employee_id))) {
            throw new DomainException(__('cheque_collection_correction.native_receipt_required'));
        }

        return $receipt;
    }

    /** @return array<string, mixed> */
    private function snapshot(Cheque $cheque, CustomerReceipt $receipt): array
    {
        return ['cheque' => $cheque->getRawOriginal(), 'lines' => $cheque->lines()->orderBy('id')->get()->map->getRawOriginal()->all(),
            'receipt' => $receipt->getRawOriginal(), 'allocations' => $receipt->allocations()->orderBy('id')->get()->map->getRawOriginal()->all(),
            'journal' => $receipt->journalEntry->getRawOriginal(), 'journal_lines' => $receipt->journalEntry->lines()->orderBy('id')->get()->map->getRawOriginal()->all(),
            'events' => $this->events($receipt)];
    }

    /** @return list<array<string, mixed>> */
    private function events(CustomerReceipt $receipt): array
    {
        return DB::table('customer_receipt_application_events')->where('receipt_id', $receipt->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function proposal(Cheque $cheque, int $id): object
    {
        $proposal = DB::table('cheque_collection_corrections')->where('company_id', $cheque->company_id)->where('cheque_id', $cheque->id)->lockForUpdate()->find($id);
        abort_if($proposal === null, 404);
        $data = [];
        foreach (['public_id', 'company_id', 'branch_id', 'cheque_id', 'customer_receipt_id', 'financial_period_id', 'posting_financial_period_id',
            'original_journal_entry_id', 'posting_date', 'treatment', 'bank_reference', 'reason', 'evidence', 'prepared_by', 'source_snapshot'] as $field) {
            $data[$field] = $field === 'source_snapshot' ? json_decode($proposal->{$field}, true, flags: JSON_THROW_ON_ERROR) : $proposal->{$field};
        }
        if (! hash_equals($proposal->proposal_seal, $this->digest($data))) {
            throw new DomainException(__('cheque_collection_correction.invalid_owner'));
        }

        return $proposal;
    }

    private function scoped(Cheque $cheque): Cheque
    {
        return Cheque::query()->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId())->findOrFail($cheque->id);
    }

    private function locked(Cheque $cheque): Cheque
    {
        Company::query()->whereKey(app(OperatingCompanyContextService::class)->requireCompanyId())->lockForUpdate()->firstOrFail();

        return $this->scoped($cheque)->newQuery()->whereKey($cheque->id)->lockForUpdate()->firstOrFail();
    }

    private function period(CustomerReceipt $receipt): FinancialPeriod
    {
        $company = app(OperatingCompanyContextService::class)->currentCompany();
        $scope = app(OperatingScopeAccessService::class);
        $active = (int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey);
        $existingInverse = $receipt->status === CustomerReceipt::StatusCancelled ? JournalEntry::query()->findOrFail($receipt->reversal_journal_entry_id) : null;
        $expected = $existingInverse === null ? $active : (int) $existingInverse->financial_period_id;
        abort_unless((int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $receipt->branch_id
            && $scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $receipt->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $receipt->financial_period_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $active)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $expected)->exists(), 404);
        if ($existingInverse !== null) {
            app(JournalEntryService::class)->assertPostedReversal($receipt->journalEntry, $existingInverse);

            return FinancialPeriod::query()->where('company_id', $receipt->company_id)->findOrFail($expected);
        }

        return app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $receipt->company_id, now()->toDateString(), $active, lockForUpdate: true);
    }

    private function authorize(): void
    {
        Gate::authorize('cheques.cancel');
        Gate::authorize('customer_receipts.cancel');
    }

    private function schema(): void
    {
        if (! Schema::hasTable('cheque_collection_corrections')) {
            throw new DomainException(__('cheque_collection_correction.migration_required'));
        }
    }

    /** @param array<string, mixed> $data */
    private function digest(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string, mixed> $properties */
    private function audit(Cheque $cheque, string $event, array $properties): void
    {
        app(ActivityLogger::class)->log(request(), 'finance', 'finance.cheque_collection_correction.'.$event, 'success', [
            'subject' => $cheque, 'company_id' => $cheque->company_id, 'properties_only' => true, 'properties' => $properties]);
    }
}
