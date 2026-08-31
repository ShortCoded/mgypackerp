<?php

namespace Modules\Inventory\Services;

use DomainException;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Inventory\Models\InventoryAccountingMapping;
use Modules\Inventory\Models\InventoryDocument;

class InventoryAccountingPostingService
{
    public function __construct(
        private readonly InventoryAccountingMappingService $mappings,
        private readonly JournalEntryService $journals,
    ) {}

    public function post(InventoryDocument $document): ?JournalEntry
    {
        if (! $this->ownsAccounting($document)) {
            return null;
        }

        $document->loadMissing(['lines.product', 'journalEntry', 'productionRun']);

        if ($document->journalEntry instanceof JournalEntry) {
            return $document->journalEntry;
        }

        $mapping = $this->mappings->requireForCompany((int) $document->company_id);
        $event = $this->eventLabel($document);
        $lines = $this->journalLines($document, $mapping, $event);

        if ($lines === []) {
            throw new DomainException(__('inventory_accounting.errors.zero_cost', ['event' => $event]));
        }

        $journal = $this->journals->createPostedFromSource(
            $this->header($document, 'inventory_document_posting', $event),
            $lines,
        );

        $document->forceFill(['journal_entry_id' => $journal->getKey()])->save();

        return $journal;
    }

    public function reverse(InventoryDocument $document): ?JournalEntry
    {
        $document->loadMissing(['journalEntry', 'journalEntry.lines']);

        if (! $document->journalEntry instanceof JournalEntry) {
            return null;
        }

        if ($document->reversal_journal_entry_id !== null) {
            return JournalEntry::query()->find($document->reversal_journal_entry_id);
        }

        $event = __('Reversal of :document', ['document' => $document->doc_num]);
        $journal = $this->journals->createPostedReversalFromSource(
            $document->journalEntry,
            $this->header($document, 'inventory_document_reversal', $event),
        );

        $document->forceFill(['reversal_journal_entry_id' => $journal->getKey()])->save();

        return $journal;
    }

    private function ownsAccounting(InventoryDocument $document): bool
    {
        if ($document->document_type === InventoryDocument::TypeProductionReceipt
            && $document->production_run_id === null) {
            return false;
        }

        return in_array($document->document_type, [
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeProductionReceipt,
            InventoryDocument::TypeAdjustmentIn,
            InventoryDocument::TypeAdjustmentOut,
            InventoryDocument::TypeScrap,
        ], true);
    }

    /** @return list<array<string, mixed>> */
    private function journalLines(
        InventoryDocument $document,
        InventoryAccountingMapping $mapping,
        string $event,
    ): array {
        $grouped = [];
        $costCenterId = in_array($document->document_type, [
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeProductionReceipt,
        ], true) ? ($document->productionRun?->cost_center_id ?? $this->mappings->productionCostCenterId($mapping, $event)) : null;

        foreach ($document->lines as $line) {
            $amount = bcadd((string) $line->total_cost, '0', 4);

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $inventoryAccount = $this->mappings->inventoryAccount($mapping, $line->product, $event);
            [$debitAccountId, $creditAccountId] = match ($document->document_type) {
                InventoryDocument::TypeMaterialIssue,
                InventoryDocument::TypeAdditionalMaterialIssue => [
                    $this->mappings->requirePostableAccount($mapping, 'wipAccount', $event)->getKey(),
                    $inventoryAccount->getKey(),
                ],
                InventoryDocument::TypeMaterialReturn => [
                    $inventoryAccount->getKey(),
                    $this->mappings->requirePostableAccount($mapping, 'wipAccount', $event)->getKey(),
                ],
                InventoryDocument::TypeProductionWaste => [
                    $this->mappings->requirePostableAccount($mapping, 'productionWasteAccount', $event)->getKey(),
                    $this->mappings->requirePostableAccount($mapping, 'wipAccount', $event)->getKey(),
                ],
                InventoryDocument::TypeProductionReceipt => [
                    $inventoryAccount->getKey(),
                    $this->mappings->requirePostableAccount($mapping, 'wipAccount', $event)->getKey(),
                ],
                InventoryDocument::TypeAdjustmentIn => [
                    $inventoryAccount->getKey(),
                    $this->mappings->requirePostableAccount($mapping, 'inventoryAdjustmentGainAccount', $event)->getKey(),
                ],
                InventoryDocument::TypeAdjustmentOut => [
                    $this->mappings->requirePostableAccount($mapping, 'inventoryAdjustmentLossAccount', $event)->getKey(),
                    $inventoryAccount->getKey(),
                ],
                InventoryDocument::TypeScrap => [
                    $this->mappings->requirePostableAccount($mapping, 'warehouseDamageLossAccount', $event)->getKey(),
                    $inventoryAccount->getKey(),
                ],
                default => throw new DomainException(__('Unsupported inventory accounting event.')),
            };

            $this->addGroupedLine($grouped, (int) $debitAccountId, $amount, '0.0000', $event, $costCenterId);
            $this->addGroupedLine($grouped, (int) $creditAccountId, '0.0000', $amount, $event, $costCenterId);
        }

        return array_values($grouped);
    }

    /** @param array<string, array<string, mixed>> $grouped */
    private function addGroupedLine(
        array &$grouped,
        int $accountId,
        string $debit,
        string $credit,
        string $description,
        mixed $costCenterId,
    ): void {
        $side = bccomp($debit, '0', 4) > 0 ? 'debit' : 'credit';
        $key = $accountId.':'.$side.':'.($costCenterId ?? 'none');
        $grouped[$key] ??= [
            'account_id' => $accountId,
            'debit_amount' => '0.0000',
            'credit_amount' => '0.0000',
            'description' => $description,
            'cost_center_id' => $costCenterId,
        ];
        $grouped[$key]['debit_amount'] = bcadd((string) $grouped[$key]['debit_amount'], $debit, 4);
        $grouped[$key]['credit_amount'] = bcadd((string) $grouped[$key]['credit_amount'], $credit, 4);
    }

    /** @return array<string, mixed> */
    private function header(InventoryDocument $document, string $sourceType, string $description): array
    {
        $currencyId = Currency::query()
            ->forCompany((int) $document->company_id)
            ->active()
            ->where('is_main', true)
            ->value('id');

        if ($currencyId === null) {
            throw new DomainException(__('inventory_accounting.errors.main_currency_required'));
        }

        return [
            'entry_date' => $document->document_date,
            'company_id' => (int) $document->company_id,
            'financial_period_id' => (int) $document->financial_period_id,
            'branch_id' => (int) $document->branch_id,
            'currency_id' => (int) $currencyId,
            'exchange_rate' => 1,
            'description' => $description,
            'notes' => $document->notes,
            'source_type' => $sourceType,
            'source_id' => (int) $document->getKey(),
            'source_doc_num' => (string) $document->doc_num,
        ];
    }

    private function eventLabel(InventoryDocument $document): string
    {
        return match ($document->document_type) {
            InventoryDocument::TypeMaterialIssue => __('Production material issue :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdditionalMaterialIssue => __('Additional production material issue :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaterialReturn => __('Production material return :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeProductionWaste => __('Production waste :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeProductionReceipt => __('Finished goods receipt :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdjustmentIn => __('Inventory count surplus :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdjustmentOut => __('Inventory count shortage :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeScrap => __('Warehouse scrap :document', ['document' => $document->doc_num]),
            default => __('Inventory movement :document', ['document' => $document->doc_num]),
        };
    }
}
