<?php

namespace Modules\Sales\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use Modules\Core\Services\ArchiveFileUsageService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoicePaymentSchedule;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\CustomerWithholdingSettlement;

final class CustomerWithholdingSettlementService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly PostingAccountResolver $accounts,
        private readonly CustomerInvoiceBalanceService $balances,
        private readonly SalesCycleAuditService $audit,
    ) {}

    public function forView(CustomerInvoice $invoice): CustomerInvoice
    {
        Gate::authorize('customer_withholding_settlements.view');

        return DB::transaction(function () use ($invoice): CustomerInvoice {
            $invoice = $this->scoped($invoice);
            $this->assertEvidence((int) $invoice->company_id);
            $invoice->withholdingSettlements()->where('status', 'prepared')->each(fn ($row) => $this->assertProposal($row));

            return $invoice->load(['paymentSchedules', 'customer', 'currency']);
        });
    }

    /** @return array<string, mixed> */
    public function preview(CustomerInvoice $invoice, int $scheduleId, int $receiptId): array
    {
        abort_unless(Gate::any(['customer_withholding_settlements.prepare', 'customer_withholding_settlements.approve']), 403);

        return DB::transaction(function () use ($invoice, $scheduleId, $receiptId): array {
            $invoice = $this->scoped($invoice);

            return $this->source($invoice, $scheduleId, $receiptId);
        });
    }

    /** @param array<string, mixed> $data */
    public function prepare(CustomerInvoice $invoice, array $data): CustomerWithholdingSettlement
    {
        Gate::authorize('customer_withholding_settlements.prepare');
        Gate::authorize('file_manager.view');

        return DB::transaction(function () use ($invoice, $data): CustomerWithholdingSettlement {
            $invoice = $this->scoped($invoice);
            $source = $this->source($invoice, (int) $data['payment_schedule_id'], (int) $data['customer_receipt_id']);
            if (! hash_equals($source['fingerprint'], (string) ($data['source_fingerprint'] ?? ''))) {
                throw new DomainException(__('sales_ui.wht.stale'));
            }
            $amount = $this->amount($data['amount'] ?? null);
            $this->assertCapacity($invoice, $source['schedule'], $amount);
            $postingDate = $this->postingDate($invoice, (string) $data['posting_date'], $source['receipt']->receipt_date->toDateString());
            $certificateDate = $this->date((string) $data['certificate_date']);
            if ($certificateDate > $postingDate || $certificateDate < $invoice->invoice_date->toDateString()) {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
            $file = $this->file($this->certificateDocNum($data), (int) $invoice->company_id);
            $reference = mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string) $data['certificate_reference'])));
            if ($reference === '' || mb_strlen($reference) > 255 || trim((string) $data['reason']) === '' || mb_strlen((string) $data['reason']) > 3000) {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
            $settlement = new CustomerWithholdingSettlement([
                'doc_num' => (string) Str::uuid(), 'company_id' => $invoice->company_id, 'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id, 'currency_id' => $invoice->currency_id,
                'customer_invoice_id' => $invoice->id, 'payment_schedule_id' => $source['schedule']->id,
                'customer_receipt_id' => $source['receipt']->id, 'certificate_file_id' => $file->id,
                'certificate_reference' => $reference, 'certificate_date' => $certificateDate,
                'posting_date' => $postingDate, 'financial_period_id' => $this->period($invoice, $postingDate),
                'exchange_rate' => $invoice->exchange_rate, 'amount' => $amount, 'expected_amount' => $source['expected_amount'],
                'reason' => trim($data['reason']), 'status' => 'prepared', 'prepared_by' => auth()->id(),
                'source_snapshot' => [...$source['snapshot'], 'certificate' => $this->fileSnapshot($file)],
                'source_fingerprint' => $source['fingerprint'],
            ]);
            $settlement->proposal_fingerprint = $this->seal($this->proposal($settlement));
            $existing = CustomerWithholdingSettlement::query()->where('company_id', $invoice->company_id)
                ->where('customer_id', $invoice->customer_id)->where('certificate_reference', $reference)->lockForUpdate()->first();
            if ($existing) {
                $this->assertProposal($existing);
                $existingProposal = $this->proposal($existing);
                $newProposal = $this->proposal($settlement);
                unset($existingProposal['doc_num'], $newProposal['doc_num']);
                if ($existing->status === 'prepared' && $existingProposal === $newProposal) {
                    return $existing;
                }
                throw new DomainException(__('sales_ui.wht.duplicate'));
            }
            $settlement->save();
            app(ArchiveFileUsageService::class)->attachFileToRecord($file, $settlement, 'withholding_certificate');
            $this->audit->record($settlement, 'customer_withholding.prepared', ['invoice' => $invoice->doc_num, 'certificate' => $reference]);

            return $settlement;
        }, 3);
    }

    public function approve(CustomerInvoice $invoice, int $id, string $reason): CustomerWithholdingSettlement
    {
        Gate::authorize('customer_withholding_settlements.approve');

        return DB::transaction(function () use ($invoice, $id, $reason): CustomerWithholdingSettlement {
            $invoice = $this->scoped($invoice);
            $settlement = $this->owned($invoice, $id);
            $this->assertProposal($settlement);
            if ((int) $settlement->prepared_by === (int) auth()->id() || trim($reason) === '' || mb_strlen($reason) > 3000) {
                throw new DomainException(__('sales_ui.wht.independent'));
            }
            if ($settlement->status === 'approved') {
                $this->assertApproved($settlement);

                return $settlement;
            }
            if ($settlement->status !== 'prepared') {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
            $source = $this->source($invoice, (int) $settlement->payment_schedule_id, (int) $settlement->customer_receipt_id);
            if (! hash_equals($settlement->source_fingerprint, $source['fingerprint'])
                || $this->fileSnapshot(ArchiveFile::query()->findOrFail($settlement->certificate_file_id)) !== $settlement->source_snapshot['certificate']) {
                throw new DomainException(__('sales_ui.wht.stale'));
            }
            $this->assertCapacity($invoice, $source['schedule'], $settlement->amount);
            $this->postingDate($invoice, $settlement->posting_date->toDateString(), $source['receipt']->receipt_date->toDateString());
            if ($this->period($invoice, $settlement->posting_date->toDateString()) !== (int) $settlement->financial_period_id) {
                throw new DomainException(__('sales_ui.wht.stale'));
            }
            $account = $this->accounts->resolve((int) $invoice->company_id, 'withholding_tax_receivable', __('sales_ui.wht.title'));
            $customerAccount = (int) collect($source['snapshot']['invoice_journal']['lines'])->firstWhere('customer_id', (int) $invoice->customer_id)['account_id'];
            $journal = $this->journals->createPostedFromSource($this->header($settlement, $settlement->posting_date->toDateString(), (int) $settlement->financial_period_id, false), [
                ['account_id' => $account->id, 'debit_amount' => $settlement->amount, 'credit_amount' => '0', 'description' => $settlement->certificate_reference],
                ['account_id' => $customerAccount, 'debit_amount' => '0', 'credit_amount' => $settlement->amount, 'customer_id' => $invoice->customer_id, 'description' => $settlement->certificate_reference],
            ]);
            $before = $this->balanceSnapshot($invoice, $source['schedule']);
            $source['schedule']->increment('actual_withholding_amount', $settlement->amount);
            $invoice->increment('actual_withholding_amount', $settlement->amount);
            $this->balances->refresh($invoice);
            $settlement->forceFill(['status' => 'approved', 'journal_entry_id' => $journal->id,
                'approved_by' => auth()->id(), 'approved_at' => now(), 'approval_reason' => trim($reason),
                'execution_snapshot' => ['journal' => $this->journalSnapshot($journal), 'before' => $before,
                    'after' => $this->balanceSnapshot($invoice->fresh(), $source['schedule']->fresh())]]);
            $settlement->execution_fingerprint = $this->seal($this->execution($settlement));
            $settlement->save();
            $this->audit->record($settlement, 'customer_withholding.approved', ['journal_entry_id' => $journal->id, 'amount' => $settlement->amount]);

            return $settlement->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function reverse(CustomerInvoice $invoice, int $id, array $data): CustomerWithholdingSettlement
    {
        Gate::authorize('customer_withholding_settlements.reverse');
        Gate::authorize('file_manager.view');

        return DB::transaction(function () use ($invoice, $id, $data): CustomerWithholdingSettlement {
            $invoice = $this->scoped($invoice);
            $settlement = $this->owned($invoice, $id);
            $this->assertApproved($settlement);
            if ($settlement->status === 'reversed') {
                return $settlement;
            }
            $date = $this->postingDate($invoice, (string) $data['posting_date'], $settlement->posting_date->toDateString());
            $file = $this->file($this->certificateDocNum($data), (int) $invoice->company_id);
            if ((int) $file->id === (int) $settlement->certificate_file_id) {
                throw new DomainException(__('sales_ui.wht.recovery_required'));
            }
            if (trim((string) $data['reason']) === '' || trim((string) $data['recovery_reference']) === ''
                || mb_strlen((string) $data['reason']) > 3000 || mb_strlen((string) $data['recovery_reference']) > 255) {
                throw new DomainException(__('sales_ui.wht.recovery_required'));
            }
            $schedule = $invoice->paymentSchedules()->lockForUpdate()->findOrFail($settlement->payment_schedule_id);
            if (bccomp($schedule->actual_withholding_amount, $settlement->amount, 4) < 0
                || bccomp($invoice->actual_withholding_amount, $settlement->amount, 4) < 0) {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
            $original = JournalEntry::query()->findOrFail($settlement->journal_entry_id);
            $inverse = $this->journals->createPostedReversalFromSource($original, $this->header($settlement, $date, $this->period($invoice, $date), true));
            $before = $this->balanceSnapshot($invoice, $schedule);
            $schedule->decrement('actual_withholding_amount', $settlement->amount);
            $invoice->decrement('actual_withholding_amount', $settlement->amount);
            $this->balances->refresh($invoice);
            $settlement->forceFill(['status' => 'reversed', 'reversal_journal_entry_id' => $inverse->id, 'reversal_date' => $date,
                'reversed_by' => auth()->id(), 'reversed_at' => now(), 'reversal_reason' => trim($data['reason']),
                'recovery_reference' => trim($data['recovery_reference']), 'recovery_file_id' => $file->id,
                'reversal_snapshot' => ['journal' => $this->journalSnapshot($inverse), 'certificate' => $this->fileSnapshot($file),
                    'before' => $before, 'after' => $this->balanceSnapshot($invoice->fresh(), $schedule->fresh())]]);
            $settlement->reversal_fingerprint = $this->seal($this->reversal($settlement));
            $settlement->save();
            app(ArchiveFileUsageService::class)->attachFileToRecord($file, $settlement, 'withholding_recovery');
            $this->audit->record($settlement, 'customer_withholding.reversed', ['recovery_reference' => $settlement->recovery_reference, 'journal_entry_id' => $inverse->id]);

            return $settlement->refresh();
        }, 3);
    }

    private function scoped(CustomerInvoice $invoice): CustomerInvoice
    {
        Gate::authorize('customer_invoices.view');
        Gate::authorize('customer_invoices.view_prices');
        $company = app(OperatingCompanyContextService::class)->currentCompany();
        $scope = app(OperatingScopeAccessService::class);
        abort_unless($company && (int) $company->id === (int) $invoice->company_id
            && (int) request()->session()->get(OperatingContextService::BranchIdKey) === (int) $invoice->branch_id
            && $scope->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $invoice->branch_id)->exists()
            && $scope->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $invoice->financial_period_id)->exists(), 404);
        Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

        return CustomerInvoice::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($invoice->id);
    }

    private function owned(CustomerInvoice $invoice, int $id): CustomerWithholdingSettlement
    {
        return CustomerWithholdingSettlement::query()->where('company_id', $invoice->company_id)
            ->where('branch_id', $invoice->branch_id)->where('customer_invoice_id', $invoice->id)->lockForUpdate()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function source(CustomerInvoice $invoice, int $scheduleId, int $receiptId): array
    {
        $schedule = $invoice->paymentSchedules()->lockForUpdate()->findOrFail($scheduleId);
        $receipt = CustomerReceipt::query()->where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($receiptId);
        $allocations = $receipt->allocations()->orderBy('id')->lockForUpdate()->get();
        $allocation = $allocations->first();
        if ($invoice->document_type !== CustomerInvoice::TypeInvoice || $invoice->posting_status !== 'posted'
            || $invoice->status !== CustomerInvoice::StatusPosted || $invoice->reversal_journal_entry_id !== null
            || $receipt->status !== CustomerReceipt::StatusApproved || $receipt->receipt_type !== CustomerReceipt::TypeCollection
            || $receipt->reversal_journal_entry_id !== null || $receipt->journal_entry_id === null
            || (int) $receipt->customer_id !== (int) $invoice->customer_id || (int) $receipt->currency_id !== (int) $invoice->currency_id
            || (int) $receipt->branch_id !== (int) $invoice->branch_id || bccomp($receipt->exchange_rate, $invoice->exchange_rate, 6) !== 0
            || bccomp($receipt->unallocated_amount, '0', 4) !== 0 || $allocations->count() !== 1
            || (int) $allocation?->customer_invoice_id !== (int) $invoice->id
            || (int) $allocation?->customer_invoice_payment_schedule_id !== (int) $schedule->id
            || $allocation?->applied_at === null || bccomp((string) $allocation?->allocated_amount, $receipt->amount, 4) !== 0
            || bccomp($receipt->amount, '0', 4) <= 0 || $receipt->receipt_date->lt($invoice->invoice_date)
            || ($receipt->payment_method === 'cheque' && $receipt->cheque?->status !== 'collected')
            || bccomp($invoice->remaining_amount, $this->balances->remaining($invoice), 4) !== 0) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
        app(CustomerReceiptApplicationHistoryService::class)->assertEvidence((int) $invoice->company_id);
        $this->assertEvidence((int) $invoice->company_id);
        if (JournalEntry::query()->whereIn('id', [$invoice->journal_entry_id, $receipt->journal_entry_id])->whereNotNull('reversed_entry_id')->exists()) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
        $invoiceJournal = $this->journalSnapshot(JournalEntry::query()->findOrFail($invoice->journal_entry_id));
        $receiptJournal = $this->journalSnapshot(JournalEntry::query()->findOrFail($receipt->journal_entry_id));
        $this->assertSourceJournal($invoiceJournal, $invoice, (int) $invoice->id,
            (int) $invoice->posting_revision === 0 ? 'customer_invoice' : 'customer_invoice_post_'.$invoice->posting_revision,
            (string) $invoice->total_amount, false);
        $this->assertSourceJournal($receiptJournal, $invoice, (int) $receipt->id,
            $receipt->payment_method === 'cheque' ? 'customer_receipt_clearing' : 'customer_receipt', $receipt->amount, true);
        $invoiceAccount = collect($invoiceJournal['lines'])->firstWhere('customer_id', (int) $invoice->customer_id)['account_id'];
        $receiptAccount = collect($receiptJournal['lines'])->firstWhere('customer_id', (int) $invoice->customer_id)['account_id'];
        if ($invoiceAccount !== $receiptAccount) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
        $snapshot = ['invoice' => $this->invoiceSourceSnapshot($invoice),
            'schedule' => $schedule->only(['id', 'customer_invoice_id', 'amount']),
            'receipt' => $this->receiptSourceSnapshot($receipt),
            'allocation' => $allocation->only(['id', 'customer_receipt_id', 'customer_invoice_id', 'customer_invoice_payment_schedule_id', 'allocated_amount']),
            'invoice_journal' => $invoiceJournal, 'receipt_journal' => $receiptJournal,
            'balance' => $this->balanceSnapshot($invoice, $schedule)];
        $expected = app(SalesAmountService::class)->round(bcdiv(bcmul((string) $invoice->taxable_amount, (string) ($invoice->withholding_rate ?? '0'), 16), '100', 12));
        $expected = app(SalesAmountService::class)->round(bcdiv(bcmul($expected, $schedule->amount, 16), $invoice->total_amount, 12));

        return ['invoice' => $invoice, 'schedule' => $schedule, 'receipt' => $receipt, 'snapshot' => $snapshot,
            'expected_amount' => $expected, 'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    private function assertCapacity(CustomerInvoice $invoice, CustomerInvoicePaymentSchedule $schedule, string $amount): void
    {
        $pending = (string) $schedule->allocations()->whereNull('applied_at')
            ->whereHas('receipt', fn ($query) => $query->where('status', CustomerReceipt::StatusApproved))->sum('allocated_amount');
        if (bccomp($amount, $invoice->remaining_amount, 4) > 0 || bccomp($amount, bcsub($schedule->outstanding_amount, $pending, 4), 4) > 0) {
            throw new DomainException(__('sales_ui.wht.capacity'));
        }
    }

    private function amount(mixed $amount): string
    {
        if (! is_scalar($amount) || ! preg_match('/^\d{1,16}(?:\.\d{1,4})?$/D', (string) $amount) || bccomp((string) $amount, '0', 4) <= 0) {
            throw new DomainException(__('sales_ui.wht.capacity'));
        }

        return bcadd((string) $amount, '0', 4);
    }

    private function date(string $date): string
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $value || $value->format('Y-m-d') !== $date) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }

        return $date;
    }

    private function postingDate(CustomerInvoice $invoice, string $date, string $earliest): string
    {
        $date = $this->date($date);
        if ($date < $earliest || $date > now()->toDateString()) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
        $this->period($invoice, $date);

        return $date;
    }

    private function period(CustomerInvoice $invoice, string $date): int
    {
        $periodId = (int) request()->session()->get(OperatingContextService::FinancialPeriodIdKey);
        $company = Company::query()->findOrFail($invoice->company_id);
        abort_unless(app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num], true)
            ->where('financial_periods.id', $periodId)->exists(), 404);

        return (int) app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $invoice->company_id, $date, expectedPeriodId: $periodId, lockForUpdate: true)->id;
    }

    /** @param array<string, mixed> $data */
    private function certificateDocNum(array $data): string
    {
        $files = $data['attachment_doc_nums'] ?? [];
        if (! is_array($files) || count($files) !== 1 || ! is_string($files[0] ?? null) || trim($files[0]) === '') {
            throw new DomainException(__('sales_ui.wht.certificate_required'));
        }

        return $files[0];
    }

    private function file(string $docNum, int $companyId): ArchiveFile
    {
        $file = app(FilePickerService::class)->selectableFileByPublicId($docNum, $companyId, FilePickerService::AcceptDocument);
        if (! $file) {
            throw new DomainException(__('sales_ui.wht.certificate_required'));
        }

        return $file;
    }

    /** @return array<string, mixed> */
    private function fileSnapshot(ArchiveFile $file): array
    {
        $stream = Storage::disk($file->disk)->readStream($file->path);
        if (! is_resource($stream)) {
            throw new DomainException(__('sales_ui.wht.certificate_required'));
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $checksum = hash_final($hash);
        } finally {
            fclose($stream);
        }
        if (! hash_equals((string) $file->checksum, $checksum)) {
            throw new DomainException(__('sales_ui.wht.certificate_required'));
        }

        return [...$file->only(['id', 'doc_num', 'attachable_type', 'attachable_id', 'disk', 'path', 'original_name', 'checksum']), 'sha256' => $checksum];
    }

    /** @return array<string, mixed> */
    private function invoiceSourceSnapshot(CustomerInvoice $invoice): array
    {
        return [...$invoice->only(['id', 'company_id', 'branch_id', 'customer_id', 'currency_id', 'financial_period_id', 'doc_num',
            'total_amount', 'taxable_amount', 'withholding_rate', 'withholding_basis', 'exchange_rate', 'journal_entry_id', 'posting_revision']),
            'invoice_date' => $invoice->invoice_date->toDateString()];
    }

    /** @return array<string, mixed> */
    private function receiptSourceSnapshot(CustomerReceipt $receipt): array
    {
        return [...$receipt->only(['id', 'doc_num', 'company_id', 'branch_id', 'customer_id', 'currency_id', 'amount', 'exchange_rate',
            'journal_entry_id', 'receipt_type', 'payment_method', 'bank_account_id', 'cashbox_id', 'cheque_id']),
            'receipt_date' => $receipt->receipt_date->toDateString()];
    }

    /** @return array<string, mixed> */
    private function balanceSnapshot(CustomerInvoice $invoice, CustomerInvoicePaymentSchedule $schedule): array
    {
        return ['invoice' => $invoice->only(['paid_amount', 'credited_amount', 'actual_withholding_amount', 'remaining_amount']),
            'schedule' => $schedule->only(['collected_amount', 'credited_amount', 'actual_withholding_amount'])];
    }

    /** @return array<string, mixed> */
    private function journalSnapshot(JournalEntry $journal): array
    {
        $journal->load('lines');
        if ($journal->deleted_at !== null || $journal->status !== 'posted' || ! $journal->is_posted) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }

        return [...$journal->only(['id', 'company_id', 'branch_id', 'financial_period_id', 'currency_id', 'exchange_rate', 'source_type', 'source_id', 'source_doc_num']),
            'entry_date' => $journal->entry_date->toDateString(),
            'lines' => $journal->lines->sortBy('line_no')->values()->map(fn ($line): array => $line->only([
                'id', 'line_no', 'account_id', 'branch_id', 'customer_id', 'supplier_id', 'employee_id', 'bank_account_id', 'cost_center_id', 'department_id', 'debit_amount', 'credit_amount']))->all()];
    }

    /** @param array<string, mixed> $journal */
    private function assertSourceJournal(array $journal, CustomerInvoice $invoice, int $sourceId, string $sourceType, string $amount, bool $credit): void
    {
        $ar = collect($journal['lines'])->where('customer_id', (int) $invoice->customer_id)->values();
        if ((int) $journal['company_id'] !== (int) $invoice->company_id || (int) $journal['branch_id'] !== (int) $invoice->branch_id
            || (int) $journal['currency_id'] !== (int) $invoice->currency_id || (int) $journal['source_id'] !== $sourceId
            || $journal['source_type'] !== $sourceType || bccomp((string) $journal['exchange_rate'], $invoice->exchange_rate, 6) !== 0
            || $ar->count() !== 1 || bccomp((string) $ar[0][$credit ? 'credit_amount' : 'debit_amount'], $amount, 4) !== 0
            || bccomp((string) $ar[0][$credit ? 'debit_amount' : 'credit_amount'], '0', 4) !== 0
            || bccomp(app(SalesAmountService::class)->sum(array_column($journal['lines'], 'debit_amount')), $amount, 4) !== 0
            || bccomp(app(SalesAmountService::class)->sum(array_column($journal['lines'], 'credit_amount')), $amount, 4) !== 0) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
    }

    /** @return array<string, mixed> */
    private function header(CustomerWithholdingSettlement $settlement, string $date, int $periodId, bool $reverse): array
    {
        return ['company_id' => (int) $settlement->company_id, 'branch_id' => (int) $settlement->branch_id,
            'currency_id' => (int) $settlement->currency_id, 'exchange_rate' => $settlement->exchange_rate,
            'financial_period_id' => $periodId, 'entry_date' => $date, 'description' => __('sales_ui.wht.title').' '.$settlement->certificate_reference,
            'source_type' => $reverse ? 'customer_withholding_reversal' : 'customer_withholding', 'source_id' => (int) $settlement->id,
            'source_doc_num' => $settlement->doc_num, 'notes' => $settlement->reason];
    }

    /** @return array<string, mixed> */
    private function proposal(CustomerWithholdingSettlement $settlement): array
    {
        return [...$settlement->only(['doc_num', 'company_id', 'branch_id', 'customer_id', 'currency_id', 'customer_invoice_id', 'payment_schedule_id',
            'customer_receipt_id', 'certificate_file_id', 'certificate_reference', 'financial_period_id', 'amount', 'expected_amount',
            'exchange_rate', 'reason', 'prepared_by', 'source_snapshot', 'source_fingerprint']),
            'certificate_date' => $settlement->certificate_date->toDateString(), 'posting_date' => $settlement->posting_date->toDateString()];
    }

    /** @return array<string, mixed> */
    private function execution(CustomerWithholdingSettlement $settlement): array
    {
        return [...$settlement->only(['id', 'doc_num', 'proposal_fingerprint', 'approved_by', 'approval_reason', 'journal_entry_id', 'execution_snapshot']),
            'approved_at' => $settlement->approved_at?->format('Y-m-d H:i:s')];
    }

    /** @return array<string, mixed> */
    private function reversal(CustomerWithholdingSettlement $settlement): array
    {
        return [...$settlement->only(['id', 'execution_fingerprint', 'reversal_journal_entry_id', 'reversed_by', 'recovery_reference', 'recovery_file_id', 'reversal_reason', 'reversal_snapshot']),
            'reversal_date' => $settlement->reversal_date?->toDateString(), 'reversed_at' => $settlement->reversed_at?->format('Y-m-d H:i:s')];
    }

    /** @param array<string, mixed> $payload */
    private function seal(array $payload, ?string $key = null): string
    {
        $key ??= (string) config('app.key');
        if ($key === '') {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $key);
    }

    /** @param array<string, mixed> $payload */
    private function assertSeal(array $payload, ?string $seal): void
    {
        foreach ([(string) config('app.key'), ...config('app.previous_keys', [])] as $key) {
            if ($key !== '' && is_string($seal) && hash_equals($seal, $this->seal($payload, $key))) {
                return;
            }
        }
        throw new DomainException(__('sales_ui.wht.source_invalid'));
    }

    private function assertProposal(CustomerWithholdingSettlement $settlement): void
    {
        $this->assertSeal($this->proposal($settlement), $settlement->proposal_fingerprint);
        $source = $settlement->source_snapshot;
        unset($source['certificate']);
        if (! hash_equals($settlement->source_fingerprint, hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)))) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
    }

    public function assertApproved(CustomerWithholdingSettlement $settlement): void
    {
        $this->assertProposal($settlement);
        $this->assertSeal($this->execution($settlement), $settlement->execution_fingerprint);
        $journal = JournalEntry::query()->findOrFail($settlement->journal_entry_id);
        if (! in_array($settlement->status, ['approved', 'reversed'], true) || $settlement->approved_at === null
            || (int) $settlement->approved_by === (int) $settlement->prepared_by
            || $this->journalSnapshot($journal) !== ($settlement->execution_snapshot['journal'] ?? null)
            || $this->fileSnapshot(ArchiveFile::withTrashed()->findOrFail($settlement->certificate_file_id)) !== $settlement->source_snapshot['certificate']) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
        foreach (['invoice_journal', 'receipt_journal'] as $key) {
            $sourceJournal = JournalEntry::query()->findOrFail($settlement->source_snapshot[$key]['id']);
            if ($this->journalSnapshot($sourceJournal) !== $settlement->source_snapshot[$key]
                || ($settlement->status === 'approved' && $sourceJournal->reversed_entry_id !== null)) {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
        }
        if ($settlement->status === 'approved') {
            $invoice = CustomerInvoice::query()->findOrFail($settlement->customer_invoice_id);
            $receipt = CustomerReceipt::query()->findOrFail($settlement->customer_receipt_id);
            $schedule = CustomerInvoicePaymentSchedule::query()->findOrFail($settlement->payment_schedule_id);
            if ($this->invoiceSourceSnapshot($invoice) !== $settlement->source_snapshot['invoice']
                || $this->receiptSourceSnapshot($receipt) !== $settlement->source_snapshot['receipt']
                || $invoice->posting_status !== 'posted' || $receipt->status !== CustomerReceipt::StatusApproved
                || $schedule->only(array_keys($settlement->source_snapshot['schedule'])) !== $settlement->source_snapshot['schedule']
                || bccomp($invoice->remaining_amount, $this->balances->remaining($invoice), 4) !== 0) {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
        }
        if ($settlement->status === 'reversed') {
            $this->assertSeal($this->reversal($settlement), $settlement->reversal_fingerprint);
            $inverse = JournalEntry::query()->findOrFail($settlement->reversal_journal_entry_id);
            if ((int) $journal->reversed_entry_id !== (int) $inverse->id
                || $this->journalSnapshot($inverse) !== $settlement->reversal_snapshot['journal']
                || $this->fileSnapshot(ArchiveFile::withTrashed()->findOrFail($settlement->recovery_file_id)) !== $settlement->reversal_snapshot['certificate']) {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
            app(SalesReturnCorrectionService::class)->assertInverse($journal, $inverse, (int) $inverse->financial_period_id, $settlement->reversal_date->toDateString());
        } elseif ($journal->reversed_entry_id !== null) {
            throw new DomainException(__('sales_ui.wht.source_invalid'));
        }
    }

    public function assertEvidence(int $companyId): void
    {
        CustomerWithholdingSettlement::query()->where(fn ($query) => $query->where('company_id', $companyId)
            ->orWhereIn('customer_invoice_id', CustomerInvoice::withTrashed()->where('company_id', $companyId)->select('id')))
            ->where('status', '<>', 'prepared')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $this->assertApproved($row);
                }
            });
        foreach ([['customer_invoices', 'customer_invoice_id'], ['customer_invoice_payment_schedules', 'payment_schedule_id']] as [$table, $key]) {
            $query = DB::table($table.' as owner');
            if ($table === 'customer_invoices') {
                $query->where('owner.company_id', $companyId);
            } else {
                $query->join('customer_invoices as invoice', 'invoice.id', '=', 'owner.customer_invoice_id')->where('invoice.company_id', $companyId);
            }
            if ($query->whereRaw("owner.actual_withholding_amount <> (SELECT COALESCE(SUM(amount), 0) FROM customer_withholding_settlements WHERE $key = owner.id AND company_id = ? AND status = 'approved')", [$companyId])->exists()) {
                throw new DomainException(__('sales_ui.wht.source_invalid'));
            }
        }
    }
}
