<?php

namespace Modules\Purchases\Services;

use DomainException;
use Modules\Accounting\Models\JournalEntry;

class ProcurementSourceJournalService
{
    /** @param array{company_id: int, branch_id: int|null, financial_period_id: int, currency_id: int|null, exchange_rate: string, source_type: string, source_id: int, entry_date: string} $expected */
    public function requireSource(?int $journalId, array $expected, bool $lock = false): JournalEntry
    {
        $journal = JournalEntry::query()->with('lines.account')
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($journalId);
        if (! $journal || $journal->status !== JournalEntry::StatusPosted || ! $journal->is_posted
            || $journal->reversed_entry_id !== null || $journal->lines->isEmpty()) {
            throw new DomainException(__('open_documents.validation.purchase_journal_invalid'));
        }
        foreach (['company_id', 'branch_id', 'financial_period_id', 'currency_id', 'source_id'] as $field) {
            if (($expected[$field] === null && $journal->{$field} !== null)
                || ($expected[$field] !== null && (int) $journal->{$field} !== (int) $expected[$field])) {
                throw new DomainException(__('open_documents.validation.purchase_journal_invalid'));
            }
        }
        if ($journal->source_type !== $expected['source_type'] || $journal->entry_date?->toDateString() !== $expected['entry_date']
            || bccomp((string) $journal->exchange_rate, $expected['exchange_rate'], 6) !== 0) {
            throw new DomainException(__('open_documents.validation.purchase_journal_invalid'));
        }
        $balance = '0.0000';
        foreach ($journal->lines as $line) {
            if (! $line->account || (int) $line->account->company_id !== $expected['company_id']) {
                throw new DomainException(__('open_documents.validation.purchase_journal_invalid'));
            }
            $balance = bcadd($balance, bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), 4);
        }
        if (bccomp($balance, '0', 4) !== 0) {
            throw new DomainException(__('open_documents.validation.purchase_journal_invalid'));
        }

        return $journal;
    }
}
