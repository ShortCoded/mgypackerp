<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoicePaymentSchedule;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\CustomerReceiptAllocation;

final class CustomerReceiptApplicationHistoryService
{
    public function record(CustomerReceipt $receipt, JournalEntry $journal, bool $reversal = false): void
    {
        $this->assertJournal($receipt, $journal, $reversal);
        foreach ($receipt->allocations()->whereNotNull('applied_at')->orderBy('id')->lockForUpdate()->get() as $allocation) {
            if (bccomp($allocation->allocated_amount, '0', 4) === 0) {
                continue;
            }
            $this->assertManifest($allocation, $this->events($allocation->id));
            if (! CustomerInvoice::query()->whereKey($allocation->customer_invoice_id)->where('company_id', $receipt->company_id)
                ->where('branch_id', $receipt->branch_id)->where('currency_id', $receipt->currency_id)->where('customer_id', $receipt->customer_id)->exists()
                || ! CustomerInvoicePaymentSchedule::query()->whereKey($allocation->customer_invoice_payment_schedule_id)
                    ->where('customer_invoice_id', $allocation->customer_invoice_id)->exists()) {
                throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
            }
            $payload = [
                'company_id' => $receipt->company_id, 'branch_id' => $receipt->branch_id, 'currency_id' => $receipt->currency_id,
                'receipt_id' => $receipt->id, 'allocation_id' => $allocation->id, 'invoice_id' => $allocation->customer_invoice_id,
                'schedule_id' => $allocation->customer_invoice_payment_schedule_id, 'journal_entry_id' => $journal->id,
                'posting_date' => $journal->entry_date->toDateString(),
                'amount' => $reversal ? bcsub('0', $allocation->allocated_amount, 4) : bcadd($allocation->allocated_amount, '0', 4),
                'created_by' => auth()->id(),
            ];
            $existing = DB::table('customer_receipt_application_events')->where('allocation_id', $allocation->id)->where('journal_entry_id', $journal->id)->first();
            if ($existing) {
                foreach (array_diff(array_keys($payload), ['created_by']) as $key) {
                    if ($this->value($key, $payload[$key]) !== $this->value($key, $existing->{$key})) {
                        throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
                    }
                }
                $this->assertSeal((array) $existing, $allocation, $this->events($allocation->id));

                continue;
            }
            DB::table('customer_receipt_application_events')->insert([
                ...$payload, 'evidence_seal' => $this->seal($payload), 'created_at' => now(),
            ]);
            $events = $this->events($allocation->id);
            $manifest = ['count' => $events->count(), 'digest' => $this->digest($events)];
            $manifest['seal'] = $this->manifestSeal($allocation, $manifest);
            $allocation->update(['settlement_evidence' => $manifest]);
        }
    }

    public function assertEvidence(int $companyId, ?int $receiptId = null): void
    {
        DB::table('customer_receipt_application_events')->where(fn ($query) => $query->where('company_id', $companyId)
            ->orWhereIn('allocation_id', CustomerReceiptAllocation::query()
                ->whereHas('receipt', fn ($query) => $query->withTrashed()->where('company_id', $companyId))->select('id')))
            ->when($receiptId !== null, fn ($query) => $query->where('receipt_id', $receiptId))
            ->orderBy('id')->chunkById(100, function ($events): void {
                $receipts = CustomerReceipt::withTrashed()->whereIn('id', $events->pluck('receipt_id'))->get()->keyBy('id');
                $journals = JournalEntry::withTrashed()->whereIn('id', $events->pluck('journal_entry_id'))->get()->keyBy('id');
                $allocations = CustomerReceiptAllocation::query()->whereIn('id', $events->pluck('allocation_id'))->get()->keyBy('id');
                $allocationEvents = DB::table('customer_receipt_application_events')->whereIn('allocation_id', $events->pluck('allocation_id'))
                    ->orderBy('id')->get()->groupBy('allocation_id');
                $invoices = CustomerInvoice::withTrashed()->whereIn('id', $events->pluck('invoice_id'))->get()->keyBy('id');
                $schedules = CustomerInvoicePaymentSchedule::query()->whereIn('id', $events->pluck('schedule_id'))->get()->keyBy('id');
                foreach ($events as $event) {
                    $receipt = $receipts->get($event->receipt_id);
                    $journal = $journals->get($event->journal_entry_id);
                    $allocation = $allocations->get($event->allocation_id);
                    $invoice = $invoices->get($event->invoice_id);
                    $schedule = $schedules->get($event->schedule_id);
                    if (! $receipt || ! $journal || ! $allocation || $allocation->settlement_evidence === null || (int) $allocation->customer_receipt_id !== (int) $receipt->id
                        || ! $invoice || ! $schedule || (int) $schedule->customer_invoice_id !== (int) $invoice->id
                        || (int) $invoice->company_id !== (int) $receipt->company_id || (int) $invoice->branch_id !== (int) $receipt->branch_id
                        || (int) $invoice->currency_id !== (int) $receipt->currency_id || (int) $invoice->customer_id !== (int) $receipt->customer_id
                        || (int) $allocation->customer_invoice_id !== (int) $event->invoice_id
                        || (int) $allocation->customer_invoice_payment_schedule_id !== (int) $event->schedule_id
                        || $journal->entry_date->toDateString() !== substr((string) $event->posting_date, 0, 10)) {
                        throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
                    }
                    foreach (['company_id', 'branch_id', 'currency_id'] as $key) {
                        if ((int) $event->{$key} !== (int) $receipt->{$key}) {
                            throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
                        }
                    }
                    $this->assertJournal($receipt, $journal, bccomp((string) $event->amount, '0', 4) < 0);
                    $this->assertSeal((array) $event, $allocation, $allocationEvents->get($allocation->id, collect()));
                }
            });
        CustomerReceiptAllocation::query()->whereHas('receipt', fn ($query) => $query->withTrashed()->where('company_id', $companyId))
            ->when($receiptId !== null, fn ($query) => $query->where('customer_receipt_id', $receiptId))
            ->whereNotNull('settlement_evidence')->with('receipt')->orderBy('id')->chunkById(100, function ($allocations): void {
                foreach ($allocations as $allocation) {
                    $events = $this->events($allocation->id);
                    $this->assertManifest($allocation, $events);
                    $active = '0.0000';
                    $activeJournalId = null;
                    foreach ($events as $event) {
                        if (bccomp((string) $event->amount, '0', 4) > 0) {
                            if (bccomp($active, '0', 4) !== 0) {
                                throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
                            }
                            $active = (string) $event->amount;
                            $activeJournalId = $event->journal_entry_id;
                        } elseif (bccomp(bcadd($active, (string) $event->amount, 4), '0', 4) !== 0
                            || ! JournalEntry::query()->whereKey($activeJournalId)->where('reversed_entry_id', $event->journal_entry_id)->exists()) {
                            throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
                        } else {
                            $active = '0.0000';
                            $activeJournalId = null;
                        }
                    }
                    $expected = $allocation->applied_at !== null && $allocation->receipt?->status === CustomerReceipt::StatusApproved
                        ? $allocation->allocated_amount : '0.0000';
                    if (bccomp($active, $expected, 4) !== 0) {
                        throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
                    }
                }
            });
    }

    private function assertJournal(CustomerReceipt $receipt, JournalEntry $journal, bool $reversal): void
    {
        if ($journal->status !== JournalEntry::StatusPosted || ! $journal->is_posted || $journal->deleted_at !== null
            || (int) $journal->company_id !== (int) $receipt->company_id || (int) $journal->branch_id !== (int) $receipt->branch_id
            || (int) $journal->currency_id !== (int) $receipt->currency_id || (int) $journal->source_id !== (int) $receipt->id
            || ! in_array($journal->source_type, $reversal ? ['customer_receipt_reversal'] : ['customer_receipt', 'customer_receipt_clearing'], true)) {
            throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
        }
        if ($reversal && ! JournalEntry::query()->where('reversed_entry_id', $journal->id)->where('company_id', $receipt->company_id)
            ->where('branch_id', $receipt->branch_id)->where('currency_id', $receipt->currency_id)->where('source_id', $receipt->id)
            ->whereIn('source_type', ['customer_receipt', 'customer_receipt_clearing'])->where('status', JournalEntry::StatusPosted)
            ->where('is_posted', true)->exists()) {
            throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
        }
    }

    /**
     * Legacy events used the literal field name as their signing key. Their keyed
     * allocation manifest must authenticate them after the caller checks native sources.
     *
     * @param  array<string,mixed>  $event
     * @param  Collection<int,object>  $events
     */
    private function assertSeal(array $event, CustomerReceiptAllocation $allocation, Collection $events): void
    {
        if ($allocation->settlement_evidence === null || $events->isEmpty()) {
            throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
        }
        $this->assertManifest($allocation, $events);
        $seal = (string) ($event['evidence_seal'] ?? '');
        if (! array_any($this->keys(), fn (string $key): bool => hash_equals($this->seal($event, $key), $seal))
            && ! hash_equals($this->seal($event, 'created_by'), $seal)) {
            throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
        }
    }

    /** @param array<string,mixed> $event */
    private function seal(array $event, ?string $key = null): string
    {
        $payload = [];
        foreach (['company_id', 'branch_id', 'currency_id', 'receipt_id', 'allocation_id', 'invoice_id', 'schedule_id', 'journal_entry_id', 'posting_date', 'amount', 'created_by'] as $attribute) {
            $payload[$attribute] = $this->value($attribute, $event[$attribute] ?? null);
        }

        return hash_hmac('sha256', 'mgypack.receipt-application.v1|'.json_encode($payload, JSON_THROW_ON_ERROR), $key ?? (string) config('app.key'));
    }

    /** @return Collection<int,object> */
    private function events(int $allocationId): Collection
    {
        return DB::table('customer_receipt_application_events')->where('allocation_id', $allocationId)->orderBy('id')->get();
    }

    private function digest(Collection $events): string
    {
        return hash('sha256', $events->map(fn ($event): string => $event->id.'|'.$event->evidence_seal)->implode(';'));
    }

    private function assertManifest(CustomerReceiptAllocation $allocation, Collection $events): void
    {
        $manifest = $allocation->settlement_evidence;
        if ($manifest === null && $events->isEmpty()) {
            return;
        }
        if (! is_array($manifest) || (int) ($manifest['count'] ?? -1) !== $events->count() || ($manifest['digest'] ?? null) !== $this->digest($events)
            || ! array_any($this->keys(), fn (string $key): bool => hash_equals($this->manifestSeal($allocation, $manifest, $key), (string) ($manifest['seal'] ?? '')))) {
            throw new DomainException(__('sales_balance_report.invalid_receipt_evidence'));
        }
    }

    /** @param array<string,mixed> $manifest */
    private function manifestSeal(CustomerReceiptAllocation $allocation, array $manifest, ?string $key = null): string
    {
        $payload = [$allocation->id, $allocation->customer_receipt_id, $allocation->customer_invoice_id, $allocation->customer_invoice_payment_schedule_id,
            (int) $manifest['count'], $manifest['digest']];

        return hash_hmac('sha256', 'mgypack.receipt-manifest.v1|'.json_encode($payload, JSON_THROW_ON_ERROR), $key ?? (string) config('app.key'));
    }

    /** @return list<string> */
    private function keys(): array
    {
        return array_values(array_filter([(string) config('app.key'), ...config('app.previous_keys', [])], fn ($key): bool => is_string($key) && $key !== ''));
    }

    private function value(string $key, mixed $value): string
    {
        return match ($key) {
            'posting_date' => substr((string) $value, 0, 10),
            'amount' => bcadd((string) $value, '0', 4),
            default => (string) $value,
        };
    }
}
