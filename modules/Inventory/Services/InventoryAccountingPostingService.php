<?php

namespace Modules\Inventory\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\JournalEntryLine;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;

class InventoryAccountingPostingService
{
    public function __construct(
        private readonly PostingAccountResolver $accounts,
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

        $event = $this->eventLabel($document);

        if ($document->lines->contains(
            fn ($line): bool => $line->unit_cost === null || $line->total_cost === null,
        )) {
            return null;
        }

        $lines = $this->journalLines($document, $event);

        if ($lines === []) {
            return null;
        }

        $journal = $this->journals->createPostedFromSource(
            $this->header($document, 'inventory_document_posting', $event),
            $lines,
        );

        $document->forceFill(['journal_entry_id' => $journal->getKey()])->save();

        if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialIssue) {
            $this->attachMaintenanceIssueJournalLineage($document, $journal);
        }

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
            && $document->production_run_id === null
            && ! $document->lines->contains(fn (InventoryDocumentLine $line): bool => $line->production_run_id !== null)) {
            return false;
        }

        return in_array($document->document_type, [
            InventoryDocument::TypeReceipt,
            InventoryDocument::TypeIssue,
            InventoryDocument::TypeReturn,
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeProductionReceipt,
            InventoryDocument::TypeAdjustmentIn,
            InventoryDocument::TypeAdjustmentOut,
            InventoryDocument::TypeMaintenanceMaterialIssue,
            InventoryDocument::TypeMaintenanceMaterialReturn,
            InventoryDocument::TypeScrap,
        ], true);
    }

    /** @return list<array<string, mixed>> */
    private function journalLines(
        InventoryDocument $document,
        string $event,
    ): array {
        if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialReturn) {
            return $this->maintenanceReturnJournalLines($document, $event);
        }

        $grouped = [];
        $usesProductionCostCenter = in_array($document->document_type, [
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
            InventoryDocument::TypeMaterialReturn,
            InventoryDocument::TypeProductionWaste,
            InventoryDocument::TypeProductionReceipt,
        ], true);
        $maintenanceCostCenterId = $this->isMaintenanceMaterialDocument($document) ? $this->maintenanceCostCenterId($document) : null;

        foreach ($document->lines as $line) {
            $costCenterId = $usesProductionCostCenter
                ? ($line->productionRun?->cost_center_id ?? $document->productionRun?->cost_center_id)
                : $maintenanceCostCenterId;
            $amount = bcadd((string) $line->total_cost, '0', 4);

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }

            $inventoryAccount = $this->accounts->inventoryForProduct(
                (int) $document->company_id,
                $line->product,
                $event,
            );
            [$debitAccountId, $creditAccountId] = match ($document->document_type) {
                InventoryDocument::TypeReceipt,
                InventoryDocument::TypeReturn,
                InventoryDocument::TypeAdjustmentIn => [
                    $inventoryAccount->getKey(),
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::InventoryAdjustmentGain, $event)->getKey(),
                ],
                InventoryDocument::TypeIssue,
                InventoryDocument::TypeAdjustmentOut => [
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::InventoryAdjustmentLoss, $event)->getKey(),
                    $inventoryAccount->getKey(),
                ],
                InventoryDocument::TypeMaintenanceMaterialIssue => [
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::FactoryMaintenanceExpense, $event)->getKey(),
                    $inventoryAccount->getKey(),
                ],
                InventoryDocument::TypeMaterialIssue,
                InventoryDocument::TypeAdditionalMaterialIssue => [
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WorkInProcessInventory, $event)->getKey(),
                    $inventoryAccount->getKey(),
                ],
                InventoryDocument::TypeMaterialReturn => [
                    $inventoryAccount->getKey(),
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WorkInProcessInventory, $event)->getKey(),
                ],
                InventoryDocument::TypeProductionWaste => [
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::AbnormalWasteLoss, $event)->getKey(),
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WorkInProcessInventory, $event)->getKey(),
                ],
                InventoryDocument::TypeProductionReceipt => [
                    $inventoryAccount->getKey(),
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WorkInProcessInventory, $event)->getKey(),
                ],
                InventoryDocument::TypeScrap => [
                    $this->accounts->resolve((int) $document->company_id, PostingAccountResolver::WarehouseDamageLoss, $event)->getKey(),
                    $inventoryAccount->getKey(),
                ],
                default => throw new DomainException(__('Unsupported inventory accounting event.')),
            };

            if ($document->document_type === InventoryDocument::TypeMaintenanceMaterialIssue) {
                $snapshot = $line->product_snapshot ?? [];
                $snapshot['maintenance_accounting'] = [
                    'inventory_account_id' => (int) $inventoryAccount->getKey(),
                    'expense_account_id' => (int) $debitAccountId,
                    'cost_center_id' => $costCenterId === null ? null : (int) $costCenterId,
                ];
                $line->forceFill(['product_snapshot' => $snapshot])->save();
            }

            $this->addGroupedLine($grouped, (int) $debitAccountId, $amount, '0.0000', $event, $costCenterId);
            $this->addGroupedLine($grouped, (int) $creditAccountId, '0.0000', $amount, $event, $costCenterId);
        }

        return array_values($grouped);
    }

    /** @return list<array<string, mixed>> */
    private function maintenanceReturnJournalLines(InventoryDocument $document, string $event): array
    {
        if ($document->source_document_type !== MaintenanceMaterialRequest::class) {
            throw new DomainException(__('Maintenance inventory accounting requires its material request source.'));
        }

        $lines = [];
        foreach ($document->lines as $line) {
            $sourceIssueId = $line->product_snapshot['source_issue_transaction_id'] ?? null;
            $sourceIssue = is_numeric($sourceIssueId)
                ? InventoryTransaction::query()->lockForUpdate()->find((int) $sourceIssueId)
                : null;
            $sourceDocument = $sourceIssue?->source_type === InventoryDocument::class
                ? InventoryDocument::query()->lockForUpdate()->find($sourceIssue->source_id)
                : null;
            $issueLine = $sourceDocument instanceof InventoryDocument
                ? InventoryDocumentLine::query()
                    ->where('inventory_document_id', $sourceDocument->getKey())
                    ->where('product_id', $line->product_id)
                    ->where('source_line_type', $line->source_line_type)
                    ->where('source_line_id', $line->source_line_id)
                    ->lockForUpdate()
                    ->first()
                : null;
            $accounting = $issueLine?->product_snapshot['maintenance_accounting'] ?? null;
            $journal = $sourceDocument?->journal_entry_id !== null
                ? JournalEntry::query()->with('lines')->lockForUpdate()->find($sourceDocument->journal_entry_id)
                : null;

            if (! $sourceIssue instanceof InventoryTransaction
                || ! $sourceDocument instanceof InventoryDocument
                || ! $issueLine instanceof InventoryDocumentLine
                || ! $journal instanceof JournalEntry
                || ! is_array($accounting)
                || $sourceDocument->document_type !== InventoryDocument::TypeMaintenanceMaterialIssue
                || $sourceDocument->status !== InventoryDocument::StatusPosted
                || $sourceDocument->source_document_type !== MaintenanceMaterialRequest::class
                || (int) $sourceDocument->source_document_id !== (int) $document->source_document_id
                || $sourceIssue->transaction_type !== InventoryDocument::TypeMaintenanceMaterialIssue
                || (int) $sourceIssue->company_id !== (int) $document->company_id
                || (int) $sourceIssue->branch_store_id !== (int) $document->branch_store_id
                || (int) $sourceIssue->product_id !== (int) $line->product_id
                || $sourceIssue->source_line_type !== $line->source_line_type
                || (int) $sourceIssue->source_line_id !== (int) $line->source_line_id
                || (int) $sourceDocument->journal_entry_id !== (int) ($accounting['journal_entry_id'] ?? 0)
                || $journal->status !== JournalEntry::StatusPosted
                || ! $journal->is_posted
                || $journal->source_type !== 'inventory_document_posting'
                || (int) $journal->source_id !== (int) $sourceDocument->getKey()
                || $sourceIssue->unit_cost === null
                || $sourceIssue->total_cost === null
                || bccomp((string) $sourceIssue->quantity_out, (string) $line->quantity, 8) < 0
                || $line->unit_cost === null
                || $line->total_cost === null
                || $issueLine->unit_cost === null
                || bccomp((string) $issueLine->unit_cost, (string) $sourceIssue->unit_cost, 8) !== 0
                || bccomp((string) $line->unit_cost, (string) $sourceIssue->unit_cost, 8) !== 0
                || bccomp((string) $line->total_cost, bcmul((string) $line->quantity, (string) $sourceIssue->unit_cost, 8), 8) !== 0) {
                throw new DomainException(__('The maintenance return cannot reverse its original issue accounting safely.'));
            }

            $inventoryLine = $journal->lines->firstWhere('id', (int) ($accounting['inventory_journal_line_id'] ?? 0));
            $expenseLine = $journal->lines->firstWhere('id', (int) ($accounting['expense_journal_line_id'] ?? 0));
            $costCenterId = $accounting['cost_center_id'] ?? null;
            $amount = bcadd((string) $line->total_cost, '0', 4);
            if (! $inventoryLine instanceof JournalEntryLine
                || ! $expenseLine instanceof JournalEntryLine
                || (int) $inventoryLine->account_id !== (int) ($accounting['inventory_account_id'] ?? 0)
                || (int) $expenseLine->account_id !== (int) ($accounting['expense_account_id'] ?? 0)
                || bccomp((string) $inventoryLine->credit_amount, '0', 4) <= 0
                || bccomp((string) $expenseLine->debit_amount, '0', 4) <= 0
                || bccomp((string) $inventoryLine->credit_amount, $amount, 4) < 0
                || bccomp((string) $expenseLine->debit_amount, $amount, 4) < 0
                || ($inventoryLine->cost_center_id === null ? null : (int) $inventoryLine->cost_center_id) !== ($costCenterId === null ? null : (int) $costCenterId)
                || ($expenseLine->cost_center_id === null ? null : (int) $expenseLine->cost_center_id) !== ($costCenterId === null ? null : (int) $costCenterId)) {
                throw new DomainException(__('The maintenance return source journal dimensions do not match its issue lineage.'));
            }

            if (bccomp($amount, '0', 4) <= 0) {
                continue;
            }
            $lines[] = $this->reverseJournalLine($inventoryLine, $amount, 'debit_amount', $event);
            $lines[] = $this->reverseJournalLine($expenseLine, $amount, 'credit_amount', $event);
        }

        return $lines;
    }

    private function attachMaintenanceIssueJournalLineage(InventoryDocument $document, JournalEntry $journal): void
    {
        $journal->loadMissing('lines');
        foreach ($document->lines as $line) {
            $snapshot = $line->product_snapshot ?? [];
            $accounting = $snapshot['maintenance_accounting'] ?? null;
            if (! is_array($accounting)) {
                throw new DomainException(__('Maintenance issue accounting lineage is missing.'));
            }

            $costCenterId = $accounting['cost_center_id'] ?? null;
            $inventoryLine = $journal->lines->first(fn (JournalEntryLine $journalLine): bool => (int) $journalLine->account_id === (int) $accounting['inventory_account_id']
                && bccomp((string) $journalLine->credit_amount, '0', 4) > 0
                && ($journalLine->cost_center_id === null ? null : (int) $journalLine->cost_center_id) === ($costCenterId === null ? null : (int) $costCenterId));
            $expenseLine = $journal->lines->first(fn (JournalEntryLine $journalLine): bool => (int) $journalLine->account_id === (int) $accounting['expense_account_id']
                && bccomp((string) $journalLine->debit_amount, '0', 4) > 0
                && ($journalLine->cost_center_id === null ? null : (int) $journalLine->cost_center_id) === ($costCenterId === null ? null : (int) $costCenterId));
            if (! $inventoryLine instanceof JournalEntryLine || ! $expenseLine instanceof JournalEntryLine) {
                throw new DomainException(__('Maintenance issue accounting lineage could not be attached to its journal.'));
            }

            $snapshot['maintenance_accounting'] = [
                ...$accounting,
                'journal_entry_id' => (int) $journal->getKey(),
                'inventory_journal_line_id' => (int) $inventoryLine->getKey(),
                'expense_journal_line_id' => (int) $expenseLine->getKey(),
            ];
            $line->forceFill(['product_snapshot' => $snapshot])->save();
        }
    }

    /** @return array<string, mixed> */
    private function reverseJournalLine(JournalEntryLine $source, string $amount, string $side, string $event): array
    {
        return [
            'account_id' => (int) $source->account_id,
            'debit_amount' => $side === 'debit_amount' ? $amount : '0.0000',
            'credit_amount' => $side === 'credit_amount' ? $amount : '0.0000',
            'description' => $event,
            'customer_id' => $source->customer_id,
            'supplier_id' => $source->supplier_id,
            'employee_id' => $source->employee_id,
            'bank_account_id' => $source->bank_account_id,
            'cost_center_id' => $source->cost_center_id,
            'department_id' => $source->department_id,
            'branch_id' => $source->branch_id,
        ];
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
            InventoryDocument::TypeReceipt => __('inventory.movements.accounting.receipt', ['document' => $document->doc_num]),
            InventoryDocument::TypeIssue => __('inventory.movements.accounting.issue', ['document' => $document->doc_num]),
            InventoryDocument::TypeReturn => __('inventory.movements.accounting.return', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaterialIssue => __('Production material issue :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdditionalMaterialIssue => __('Additional production material issue :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaterialReturn => __('Production material return :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeProductionWaste => __('Production waste :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeProductionReceipt => __('Finished goods receipt :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdjustmentIn => __('Inventory count surplus :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeAdjustmentOut => __('Inventory count shortage :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaintenanceMaterialIssue => __('Maintenance material issue :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeMaintenanceMaterialReturn => __('Maintenance material return :document', ['document' => $document->doc_num]),
            InventoryDocument::TypeScrap => __('Warehouse scrap :document', ['document' => $document->doc_num]),
            default => __('Inventory movement :document', ['document' => $document->doc_num]),
        };
    }

    private function isMaintenanceMaterialDocument(InventoryDocument $document): bool
    {
        return in_array($document->document_type, [
            InventoryDocument::TypeMaintenanceMaterialIssue,
            InventoryDocument::TypeMaintenanceMaterialReturn,
        ], true);
    }

    private function maintenanceCostCenterId(InventoryDocument $document): ?int
    {
        if ($document->source_document_type !== MaintenanceMaterialRequest::class) {
            throw new DomainException(__('Maintenance inventory accounting requires its material request source.'));
        }

        $request = MaintenanceMaterialRequest::query()
            ->with(['workOrder.productionRun', 'workOrder.asset'])
            ->lockForUpdate()
            ->findOrFail($document->source_document_id);

        if ((int) $request->company_id !== (int) $document->company_id
            || (int) $request->financial_period_id !== (int) $document->financial_period_id
            || (int) $request->branch_id !== (int) $document->branch_id
            || (int) $request->branch_store_id !== (int) $document->branch_store_id) {
            throw new DomainException(__('Maintenance inventory accounting source context does not match the inventory document.'));
        }

        $costCenterId = $request->workOrder?->productionRun?->cost_center_id
            ?? $request->workOrder?->asset?->cost_center_id;

        return $costCenterId === null ? null : (int) $costCenterId;
    }
}
