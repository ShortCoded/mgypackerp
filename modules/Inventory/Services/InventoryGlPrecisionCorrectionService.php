<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;

class InventoryGlPrecisionCorrectionService
{
    public function __construct(private readonly InventoryAccountingPostingService $accounting) {}

    /** @param Collection<int, InventoryTransaction> $transactions @return list<array<string, mixed>> */
    public function plan(Collection $transactions): array
    {
        $effects = [];
        $documents = InventoryDocument::query()->whereIn('id', $transactions->where('source_type', InventoryDocument::class)->pluck('source_id')->unique())
            ->with(['lines.product', 'lines.productionRun', 'productionRun', 'journalEntry.lines'])
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($documents as $document) {
            if ($document->journal_entry_id === null) {
                continue;
            }
            $pairs = $this->accounting->sourcePostingPairs($document);
            if ($pairs === []) {
                continue;
            }
            $journal = $document->journalEntry;
            if (! $journal instanceof JournalEntry) {
                throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
            }
            $this->accounting->assertPostedJournalHeader($journal, (int) $document->company_id, (int) $document->financial_period_id,
                'inventory_document_posting', (int) $document->id, (int) $document->branch_id, $document->document_date->toDateString());
            $expected = [];
            foreach ($pairs as $pair) {
                foreach (['debit', 'credit'] as $side) {
                    if (bccomp($pair['booked_amount'], '0', 4) === 0) {
                        continue;
                    }
                    $key = $this->key($pair[$side.'_account_id'], $pair[$side.'_branch_id'] ?? $pair['branch_id'], $pair['cost_center_id'], $side);
                    $expected[$key] = bcadd($expected[$key] ?? '0', $pair['booked_amount'], 4);
                }
            }
            $actual = $this->journalGroups($journal);
            ksort($expected);
            if ($expected !== $actual) {
                throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
            }
            foreach ($pairs as $pair) {
                $delta = bcsub($pair['canonical_amount'], $pair['booked_amount'], 4);
                if (bccomp($delta, '0', 4) === 0) {
                    continue;
                }
                $original = $transactions->first(fn ($row): bool => ! $row->is_reversal && (int) $row->source_id === (int) $document->id
                    && in_array($row->posting_key, ["inventory-document:{$document->id}:line:{$pair['document_line_id']}:out",
                        "inventory-document:{$document->id}:line:{$pair['document_line_id']}:in"], true));
                if ($original === null) {
                    continue;
                }
                if (bccomp((string) $original->total_cost, $pair['exact_total_cost'], 8) !== 0) {
                    throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
                }
                $originalEffects = $this->pairEffects($journal, $pair, $original, $delta, false);
                $effects = [...$effects, ...$originalEffects];
                $reversal = $transactions->firstWhere('reversal_of_id', $original->id);
                if ($reversal) {
                    $reverseJournal = JournalEntry::query()->with('lines')->where('company_id', $document->company_id)
                        ->where('source_type', 'inventory_document_reversal')->where('source_id', $document->id)->lockForUpdate()->sole();
                    $reversed = [];
                    foreach ($expected as $key => $amount) {
                        $parts = explode(':', $key);
                        $parts[3] = $parts[3] === 'debit' ? 'credit' : 'debit';
                        $reversed[implode(':', $parts)] = $amount;
                    }
                    ksort($reversed);
                    $this->accounting->assertPostedJournalHeader($reverseJournal, (int) $document->company_id, (int) $reversal->financial_period_id,
                        'inventory_document_reversal', (int) $document->id, (int) $document->branch_id, $reversal->transaction_date->toDateString());
                    if ($this->journalGroups($reverseJournal) !== $reversed) {
                        throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
                    }
                    if ($originalEffects === [] && $this->accounting->completionReversalCoversPrecision($document)) {
                        continue;
                    }
                    $effects = [...$effects, ...$this->pairEffects($reverseJournal, $pair, $reversal, $delta, true)];
                }
            }
        }

        return $effects;
    }

    /** @return array<string, string> */
    private function journalGroups(JournalEntry $journal): array
    {
        $groups = [];
        foreach ($journal->lines as $line) {
            if ($line->customer_id !== null || $line->supplier_id !== null || $line->employee_id !== null
                || $line->department_id !== null || $line->bank_account_id !== null) {
                throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
            }
            foreach (['debit', 'credit'] as $side) {
                $amount = (string) $line->{$side.'_amount'};
                if (bccomp($amount, '0', 4) === 0) {
                    continue;
                }
                $key = $this->key((int) $line->account_id, (int) $line->branch_id, $line->cost_center_id, $side);
                $groups[$key] = bcadd($groups[$key] ?? '0', $amount, 4);
            }
        }
        ksort($groups);

        return $groups;
    }

    /** @param array<string, mixed> $pair @return list<array<string, mixed>> */
    private function pairEffects(JournalEntry $journal, array $pair, InventoryTransaction $source, string $delta, bool $reversal): array
    {
        $effects = [];
        foreach (['debit', 'credit'] as $side) {
            $journalSide = $reversal ? ($side === 'debit' ? 'credit' : 'debit') : $side;
            $line = $journal->lines->first(fn (JournalEntryLine $row): bool => (int) $row->account_id === $pair[$side.'_account_id']
                && (int) $row->branch_id === ($pair[$side.'_branch_id'] ?? $pair['branch_id']) && $row->cost_center_id === $pair['cost_center_id']
                && bccomp((string) $row->{$journalSide.'_amount'}, '0', 4) > 0);
            if ($line === null) {
                throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
            }
            $key = 'inventory-gl-precision:'.$source->id.':'.$line->id;
            if (InventoryValueAdjustmentLine::query()->where('precision_key', $key)->exists()) {
                continue;
            }
            $signed = $journalSide === 'debit' ? $delta : bcmul($delta, '-1', 4);
            $effects[] = [
                'precision_key' => $key, 'source_journal_id' => (int) $journal->id, 'source_journal_line_id' => (int) $line->id,
                'source_transaction_id' => (int) $source->id, 'source_doc_num' => $source->source_doc_num,
                'branch_id' => (int) $line->branch_id, 'cost_center_id' => $line->cost_center_id,
                'production_run_id' => $source->production_run_id, 'document_line_id' => $pair['document_line_id'],
                'production_cost_role' => null, 'production_cost_delta' => null, 'effect' => InventoryValueAdjustmentLine::EffectGlPrecision,
                'account_id' => (int) $line->account_id, 'amount' => bcadd($signed, '0', 8), 'unvalued_quantity_delta' => '0.00000000',
                'exact_total_cost' => $pair['exact_total_cost'], 'legacy_booked_amount' => $pair['booked_amount'],
                'canonical_rounded_amount' => $pair['canonical_amount'], 'source_rounding_rule' => $pair['source_rule'],
                'rounding_rule' => 'half_up_line_gl_4_v1', 'source_reversal' => $reversal,
                'source_journal_snapshot' => $journal->only(['id', 'company_id', 'financial_period_id', 'entry_date', 'source_type', 'source_id', 'status', 'is_posted', 'currency_id', 'exchange_rate', 'branch_id']),
                'source_journal_lines' => $journal->lines->sortBy('id')->map(fn ($row) => $row->getAttributes())->values()->all(),
            ];
        }
        if (count($effects) === 1) {
            throw new DomainException(__('inventory_periodic_cost.errors.precision_source'));
        }

        return $effects;
    }

    private function key(int $accountId, int $branchId, ?int $centerId, string $side): string
    {
        return implode(':', [$accountId, $branchId, $centerId ?? 'none', $side]);
    }
}
