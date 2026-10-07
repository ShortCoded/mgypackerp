<?php

namespace Modules\Inventory\Services;

use App\Services\PostingAccountResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Product;
use Modules\Core\Services\NumericFormatService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Production\Services\ProductionStageOutputCostService;

class InventoryGlReconciliationService
{
    public function __construct(private readonly PostingAccountResolver $accounts) {}

    /** @return list<array{key: string, label: string, subledger: string, gl: string, difference: string, status: string}> */
    public function reconcile(int $companyId, ?int $financialPeriodId = null, ?int $branchId = null): array
    {
        $rawAccountIds = collect([
            $this->accounts->resolve($companyId, PostingAccountResolver::RawMaterialInventory, __('Inventory reconciliation'))->getKey(),
            $this->accounts->resolve($companyId, PostingAccountResolver::PackagingMaterialInventory, __('Inventory reconciliation'))->getKey(),
        ])->unique()->values()->all();
        $wasteAccountIds = [$this->accounts->resolve($companyId, PostingAccountResolver::AbnormalWasteLoss, __('Inventory reconciliation'))->getKey()];
        $wasteAccountIds = array_values(array_unique([...$wasteAccountIds, ...app(ProductionStageOutputCostService::class)->historicalLossAccountIds($companyId)]));
        $stageLoss = bcsub(app(ProductionStageOutputCostService::class)->recognizedLoss($companyId, $financialPeriodId, $branchId),
            app(ProductionStageOutputCostService::class)->lossRoundingDifference($companyId, $financialPeriodId, $branchId), 8);
        $adjustmentAccountIds = [
            $this->accounts->resolve($companyId, PostingAccountResolver::InventoryAdjustmentGain, __('Inventory reconciliation'))->getKey(),
            $this->accounts->resolve($companyId, PostingAccountResolver::InventoryAdjustmentLoss, __('Inventory reconciliation'))->getKey(),
            $this->accounts->resolve($companyId, PostingAccountResolver::WarehouseDamageLoss, __('Inventory reconciliation'))->getKey(),
        ];

        return [
            $this->row(
                'raw_materials',
                __('Raw materials / packaging inventory'),
                $this->inventoryValue($companyId, [
                    Product::ClassificationRawMaterial,
                    Product::ClassificationPackaging,
                    Product::ClassificationOther,
                ], [InventoryTransaction::StatusProductionStaging, InventoryTransaction::StatusWip,
                    InventoryTransaction::StatusQuarantine, InventoryTransaction::StatusRework, InventoryTransaction::StatusScrap], $financialPeriodId, $branchId),
                $this->accountBalance($companyId, $rawAccountIds, $financialPeriodId, $branchId),
            ),
            $this->row(
                'wip',
                __('Production work in process'),
                $this->wipValue($companyId, $financialPeriodId, $branchId),
                $this->accountBalance($companyId, $this->wipAccountIds($companyId), $financialPeriodId, $branchId),
            ),
            $this->row(
                'finished_goods',
                __('Finished goods inventory'),
                $this->inventoryValue($companyId, [Product::ClassificationFinishedProduct], [InventoryTransaction::StatusQuarantine,
                    InventoryTransaction::StatusRework, InventoryTransaction::StatusScrap], $financialPeriodId, $branchId),
                $this->accountBalance($companyId, [
                    $this->accounts->resolve($companyId, PostingAccountResolver::FinishedGoodsInventory, __('Inventory reconciliation'))->getKey(),
                ], $financialPeriodId, $branchId),
            ),
            $this->row('quarantine', __('inventory.movements.stock_statuses.quarantine'),
                $this->statusValue($companyId, InventoryTransaction::StatusQuarantine, $financialPeriodId, $branchId),
                $this->accountBalance($companyId, [$this->accounts->resolve($companyId, PostingAccountResolver::QuarantineInventory,
                    __('Inventory reconciliation'))->id], $financialPeriodId, $branchId)),
            $this->row('rework', __('inventory.movements.stock_statuses.rework'),
                $this->statusValue($companyId, InventoryTransaction::StatusRework, $financialPeriodId, $branchId),
                $this->accountBalance($companyId, [$this->accounts->resolve($companyId, PostingAccountResolver::ReworkInventory,
                    __('Inventory reconciliation'))->id], $financialPeriodId, $branchId)),
            $this->row(
                'production_waste',
                __('Production waste'),
                $this->amount(bcadd($this->eventValue($companyId, [InventoryDocument::TypeProductionWaste], $wasteAccountIds, $financialPeriodId, $branchId), $stageLoss, 8)),
                $this->amount(bcadd($this->eventGlValue($companyId, [InventoryDocument::TypeProductionWaste], $wasteAccountIds, $financialPeriodId, $branchId), $this->stageLossGl($companyId, $financialPeriodId, $branchId, $wasteAccountIds), 8)),
            ),
            $this->row(
                'inventory_adjustments',
                __('Inventory adjustments and warehouse scrap'),
                $this->eventValue($companyId, [
                    InventoryDocument::TypeAdjustmentIn,
                    InventoryDocument::TypeAdjustmentOut,
                    InventoryDocument::TypeScrap,
                ], $adjustmentAccountIds, $financialPeriodId, $branchId),
                $this->eventGlValue($companyId, [
                    InventoryDocument::TypeAdjustmentIn,
                    InventoryDocument::TypeAdjustmentOut,
                    InventoryDocument::TypeScrap,
                ], $adjustmentAccountIds, $financialPeriodId, $branchId),
            ),
        ];
    }

    private function statusValue(int $companyId, string $status, ?int $periodId, ?int $branchId): string
    {
        $value = InventoryTransaction::query()->where('company_id', $companyId)->where('stock_status', $status)
            ->when($periodId !== null, fn ($query) => $query->where('financial_period_id', $periodId))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->selectRaw('coalesce(sum('.InventoryTransaction::signedValueSql().'), 0) as value')->value('value');

        return $this->amount($value);
    }

    /** @param list<string> $classifications @param list<string> $excludedStatuses */
    private function inventoryValue(
        int $companyId,
        array $classifications,
        array $excludedStatuses = [],
        ?int $financialPeriodId = null,
        ?int $branchId = null,
    ): string {
        $value = InventoryTransaction::query()
            ->join('products', 'products.id', '=', 'inventory_transactions.product_id')
            ->where('inventory_transactions.company_id', $companyId)
            ->whereIn('products.item_classification', $classifications)
            ->when($financialPeriodId !== null, fn ($query) => $query->where('inventory_transactions.financial_period_id', $financialPeriodId))
            ->when($branchId !== null, fn ($query) => $query->whereExists(function ($storeQuery) use ($branchId): void {
                $storeQuery->selectRaw('1')
                    ->from('branch_stores')
                    ->whereColumn('branch_stores.id', 'inventory_transactions.branch_store_id')
                    ->where('branch_stores.branch_id', $branchId);
            }))
            ->when($excludedStatuses !== [], fn ($query) => $query->whereNotIn('inventory_transactions.stock_status', $excludedStatuses))
            ->selectRaw('coalesce(sum('.InventoryTransaction::signedValueSql('inventory_transactions.').'), 0) as value')
            ->value('value');

        return $this->amount($value);
    }

    private function wipValue(int $companyId, ?int $financialPeriodId, ?int $branchId): string
    {
        $value = '0.00000000';
        $numbers = new NumericFormatService;
        foreach ([false, true] as $reversal) {
            $query = $this->documentEventLines($companyId, [InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue,
                InventoryDocument::TypeMaterialReturn, InventoryDocument::TypeProductionWaste, InventoryDocument::TypeProductionReceipt],
                $financialPeriodId, $branchId, $reversal)
                ->where(function ($query): void {
                    $query->whereNotNull('inventory_documents.production_run_id')
                        ->orWhereNotNull('inventory_document_lines.production_run_id');
                })
                ->whereNull('inventory_document_lines.deleted_at')
                ->selectRaw(
                    'coalesce(sum(case
                    when inventory_documents.document_type in (?, ?) then inventory_document_lines.total_cost
                    when inventory_documents.document_type in (?, ?, ?) then -inventory_document_lines.total_cost
                    else 0 end), 0) as value',
                    [
                        InventoryDocument::TypeMaterialIssue,
                        InventoryDocument::TypeAdditionalMaterialIssue,
                        InventoryDocument::TypeMaterialReturn,
                        InventoryDocument::TypeProductionWaste,
                        InventoryDocument::TypeProductionReceipt,
                    ],
                )
                ->value('value');
            $amount = $numbers->normalizeScientificNotation((string) $query) ?? '0';
            $value = $reversal ? bcsub($value, $amount, 8) : bcadd($value, $amount, 8);
        }

        $completion = '0.00000000';
        foreach ([false, true] as $reversal) {
            $query = DB::table('inventory_value_adjustment_lines as correction_line')
                ->join('inventory_value_adjustments as correction', 'correction.id', '=', 'correction_line.inventory_value_adjustment_id')
                ->join('inventory_document_lines as source_line', 'source_line.id', '=', 'correction_line.inventory_document_line_id')
                ->join('inventory_documents as source_document', 'source_document.id', '=', 'source_line.inventory_document_id')
                ->where('correction.company_id', $companyId)->where('correction.status', InventoryValueAdjustment::StatusPosted)
                ->whereIn('source_document.status', [InventoryDocument::StatusPosted, InventoryDocument::StatusReversed])
                ->when($branchId !== null, fn ($query) => $query->where('correction_line.branch_id', $branchId));
            $this->scopeCompletionPeriod($query, $financialPeriodId, $reversal);
            $amount = $query
                ->selectRaw("coalesce(sum(case when correction_line.production_cost_role in ('issued', 'expense_capitalized') then correction_line.production_cost_delta
                when correction_line.production_cost_role in ('returned', 'waste', 'finished_goods', 'standard_variance') then -correction_line.production_cost_delta else 0 end), 0) as value")->value('value');

            $amount = $numbers->normalizeScientificNotation((string) $amount) ?? '0';
            $completion = $reversal ? bcsub($completion, $amount, 8) : bcadd($completion, $amount, 8);
        }

        $capitalizedCosts = $this->capitalizedProductionCosts($companyId, $financialPeriodId, $branchId);

        return $this->amount(bcsub(bcadd(bcadd($numbers->normalizeScientificNotation((string) $value) ?? '0',
            $numbers->normalizeScientificNotation((string) $completion) ?? '0', 8), $capitalizedCosts, 8),
            bcsub(app(ProductionStageOutputCostService::class)->recognizedLoss($companyId, $financialPeriodId, $branchId),
                app(ProductionStageOutputCostService::class)->lossRoundingDifference($companyId, $financialPeriodId, $branchId), 8), 8));
    }

    /** @param list<int> $accountIds */
    private function stageLossGl(int $companyId, ?int $periodId, ?int $branchId, array $accountIds): string
    {
        $value = DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->where('entry.company_id', $companyId)->whereNull('entry.deleted_at')->where('entry.status', JournalEntry::StatusPosted)->where('entry.is_posted', true)
            ->whereIn('entry.source_type', ['production_stage_output_loss', 'production_stage_output_loss_reversal'])->whereIn('line.account_id', $accountIds)
            ->when($periodId !== null, fn ($query) => $query->where('entry.financial_period_id', $periodId))
            ->when($branchId !== null, fn ($query) => $query->whereRaw('coalesce(line.branch_id, entry.branch_id) = ?', [$branchId]))
            ->selectRaw('coalesce(sum((line.debit_amount-line.credit_amount)*entry.exchange_rate), 0) as amount')->value('amount');

        return (new NumericFormatService)->normalizeScientificNotation((string) $value) ?? '0.00000000';
    }

    private function capitalizedProductionCosts(int $companyId, ?int $periodId, ?int $branchId): string
    {
        $value = DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->where('entry.company_id', $companyId)->whereNull('entry.deleted_at')->where('entry.status', JournalEntry::StatusPosted)->where('entry.is_posted', true)
            ->whereIn('line.account_id', $this->wipAccountIds($companyId))
            ->whereIn('entry.source_type', ['production_expense_payment', 'production_expense_reversal', 'overhead_allocation', 'overhead_allocation_reversal'])
            ->when($periodId !== null, fn ($query) => $query->where('entry.financial_period_id', $periodId))
            ->when($branchId !== null, fn ($query) => $query->whereRaw('coalesce(line.branch_id, entry.branch_id) = ?', [$branchId]))
            ->selectRaw('coalesce(sum((line.debit_amount-line.credit_amount)*entry.exchange_rate), 0) as amount')->value('amount');

        return (new NumericFormatService)->normalizeScientificNotation((string) $value) ?? '0.00000000';
    }

    /** @return list<int> */
    private function wipAccountIds(int $companyId): array
    {
        return app(InventoryAccountingPostingService::class)->historicalWipAccountIds($companyId);
    }

    /** @param list<int> $accountIds */
    private function accountBalance(int $companyId, array $accountIds, ?int $financialPeriodId, ?int $branchId): string
    {
        $value = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->when($financialPeriodId !== null, fn ($query) => $query->where('journal_entries.financial_period_id', $financialPeriodId))
            ->when($branchId !== null, fn ($query) => $query->whereRaw('coalesce(journal_entry_lines.branch_id, journal_entries.branch_id) = ?', [$branchId]))
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->whereIn('journal_entry_lines.account_id', array_values(array_unique($accountIds)))
            ->selectRaw('coalesce(sum((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate), 0) as value')
            ->value('value');

        return $this->amount($value);
    }

    /** @param list<string> $documentTypes @param list<int> $accountIds */
    private function eventValue(int $companyId, array $documentTypes, array $accountIds, ?int $financialPeriodId, ?int $branchId): string
    {
        $value = '0.00000000';
        $numbers = new NumericFormatService;
        foreach ([false, true] as $reversal) {
            $lines = $this->documentEventLines($companyId, $documentTypes, $financialPeriodId, $branchId, $reversal)
                ->whereNotNull('inventory_document_lines.total_cost')
                ->select('inventory_document_lines.total_cost', 'inventory_document_lines.product_snapshot')
                ->cursor();

            $amount = $lines->reduce(
                function (string $total, object $line): string {
                    $snapshot = is_string($line->product_snapshot) ? json_decode($line->product_snapshot, true) : $line->product_snapshot;
                    $booked = data_get($snapshot, 'inventory_accounting.booked_amount');

                    return bcadd($total, $booked === null ? bcadd((string) $line->total_cost, '0', 4) : (string) $booked, 4);
                },
                '0.0000',
            );
            $completion = $this->bookedCompletionEventAmount($this->completionEventLines($companyId, $documentTypes, $financialPeriodId, $branchId, $reversal)
                ->whereIn('correction_line.effect', [InventoryValueAdjustmentLine::EffectExpense, InventoryValueAdjustmentLine::EffectGlPrecision])
                ->whereIn('correction_line.account_id', $accountIds));

            $amount = bcadd($amount, $numbers->normalizeScientificNotation((string) $completion) ?? '0', 8);
            $value = $reversal ? bcsub($value, $amount, 8) : bcadd($value, $amount, 8);
        }

        return $this->amount($value);
    }

    /** @param list<string> $documentTypes @param list<int> $accountIds */
    private function eventGlValue(
        int $companyId,
        array $documentTypes,
        array $accountIds,
        ?int $financialPeriodId,
        ?int $branchId,
    ): string {
        $balances = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('inventory_documents', function ($join): void {
                $join->on('inventory_documents.id', '=', 'journal_entries.source_id')
                    ->whereIn('journal_entries.source_type', ['inventory_document_posting', 'inventory_document_reversal']);
            })
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)->whereNull('journal_entries.deleted_at')
            ->whereColumn('inventory_documents.company_id', 'journal_entries.company_id')
            ->whereIn('inventory_documents.document_type', $documentTypes)
            ->whereIn('journal_entry_lines.account_id', array_values(array_unique($accountIds)))
            ->when($financialPeriodId !== null, fn ($query) => $query->where('journal_entries.financial_period_id', $financialPeriodId))
            ->when($branchId !== null, fn ($query) => $query->whereRaw('coalesce(journal_entry_lines.branch_id, journal_entries.branch_id) = ?', [$branchId]))
            ->groupBy('journal_entries.id', 'journal_entries.source_type', 'journal_entry_lines.account_id')
            ->selectRaw('journal_entries.source_type, sum((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate) as value')
            ->get();

        $value = $balances->reduce(
            fn (string $total, object $balance): string => $balance->source_type === 'inventory_document_reversal'
                ? bcsub($total, ltrim($this->amount($balance->value), '-'), 4)
                : bcadd($total, ltrim($this->amount($balance->value), '-'), 4),
            '0.0000',
        );
        $value = bcadd($value, $this->completionEventGlAmount($companyId, $documentTypes, $accountIds, $financialPeriodId, $branchId), 4);

        return $this->amount($value);
    }

    /** @param list<string> $documentTypes @param list<int> $accountIds */
    private function completionEventGlAmount(int $companyId, array $documentTypes, array $accountIds, ?int $periodId, ?int $branchId): string
    {
        $events = $this->completionEventLines($companyId, $documentTypes, null, $branchId)
            ->whereIn('correction_line.effect', [InventoryValueAdjustmentLine::EffectExpense, InventoryValueAdjustmentLine::EffectGlPrecision])
            ->whereIn('correction_line.account_id', $accountIds);
        $allocation = $this->bookedCompletionEventGroups($events);
        $groups = (clone $events)
            ->select('correction.id as adjustment_id', 'correction.journal_entry_id', 'correction_line.account_id', 'correction_line.branch_id', 'correction_line.cost_center_id')
            ->selectRaw('case when source_document.document_type = ? then -1 else 1 end as event_sign', [InventoryDocument::TypeAdjustmentIn])->distinct();
        $original = DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->joinSub($groups, 'event_group', function ($join): void {
                $join->on('event_group.journal_entry_id', '=', 'entry.id')->on('event_group.adjustment_id', '=', 'entry.source_id')
                    ->on('event_group.account_id', '=', 'line.account_id')->on('event_group.branch_id', '=', 'line.branch_id')
                    ->whereRaw('coalesce(event_group.cost_center_id, -1) = coalesce(line.cost_center_id, -1)');
            })
            ->where('entry.company_id', $companyId)->where('entry.source_type', InventoryValueAdjustment::class)
            ->where('entry.status', JournalEntry::StatusPosted)->where('entry.is_posted', true)->whereNull('entry.deleted_at')
            ->when($periodId !== null, fn ($query) => $query->where('entry.financial_period_id', $periodId))
            ->groupBy('event_group.adjustment_id', 'event_group.account_id', 'event_group.branch_id', 'event_group.cost_center_id', 'event_group.event_sign')
            ->select('event_group.adjustment_id', 'event_group.account_id', 'event_group.branch_id', 'event_group.cost_center_id', 'event_group.event_sign')
            ->selectRaw('sum((line.debit_amount-line.credit_amount)*entry.exchange_rate) as amount')->get();
        $inverse = DB::table('journal_entry_lines as line')->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('inventory_documents as document', fn ($join) => $join->on('document.id', '=', 'entry.source_id')->on('document.company_id', '=', 'entry.company_id'))
            ->where('entry.company_id', $companyId)->where('entry.source_type', 'inventory_document_cost_completion_reversal')
            ->where('entry.status', JournalEntry::StatusPosted)->where('entry.is_posted', true)->whereNull('entry.deleted_at')
            ->whereIn('document.document_type', $documentTypes)->whereIn('line.account_id', $accountIds)
            ->when($periodId !== null, fn ($query) => $query->where('entry.financial_period_id', $periodId))
            ->when($branchId !== null, fn ($query) => $query->whereRaw('coalesce(line.branch_id, entry.branch_id) = ?', [$branchId]))
            ->select('line.debit_amount', 'line.credit_amount', 'entry.exchange_rate', 'document.document_type')->cursor();
        $value = '0.0000';
        foreach ($original as $group) {
            $key = implode(':', [$group->adjustment_id, $group->account_id, $group->branch_id, $group->cost_center_id ?? 'none']);
            $expected = $allocation[$key];
            /** Attribute the canonical unselected portion, retaining every actual whole-group variance in this event. */
            $other = bcsub($expected['whole'], $expected['selected'], 4);
            $value = bcadd($value, bcmul(bcsub($this->amount($group->amount), $other, 4), (string) $group->event_sign, 4), 4);
        }
        foreach ($inverse as $line) {
            $sign = $line->document_type === InventoryDocument::TypeAdjustmentIn ? '-1' : '1';
            $value = bcadd($value, bcmul(bcmul(bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), (string) $line->exchange_rate, 4), $sign, 4), 4);
        }

        return $value;
    }

    /** @param list<string> $documentTypes */
    private function completionEventLines(int $companyId, array $documentTypes, ?int $financialPeriodId, ?int $branchId, bool $reversal = false): Builder
    {
        $query = DB::table('inventory_value_adjustment_lines as correction_line')
            ->join('inventory_value_adjustments as correction', 'correction.id', '=', 'correction_line.inventory_value_adjustment_id')
            ->join('inventory_transactions as source_transaction', 'source_transaction.id', '=', 'correction_line.source_transaction_id')
            ->join('inventory_documents as source_document', function ($join): void {
                $join->on('source_document.id', '=', 'source_transaction.source_id')
                    ->where('source_transaction.source_type', InventoryDocument::class);
            })->where('correction.company_id', $companyId)->where('correction.status', InventoryValueAdjustment::StatusPosted)
            ->whereColumn('source_document.company_id', 'correction.company_id')->whereColumn('source_transaction.company_id', 'correction.company_id')
            ->whereIn('source_document.status', [InventoryDocument::StatusPosted, InventoryDocument::StatusReversed])->whereIn('source_document.document_type', $documentTypes)
            ->when($reversal, fn ($query) => $query->where('source_transaction.is_reversal', false))
            ->when($branchId !== null, fn ($query) => $query->where('correction_line.branch_id', $branchId));
        $this->scopeCompletionPeriod($query, $financialPeriodId, $reversal);

        return $query;
    }

    /** @param list<string> $documentTypes */
    private function documentEventLines(int $companyId, array $documentTypes, ?int $periodId, ?int $branchId, bool $reversal): Builder
    {
        $query = DB::table('inventory_document_lines')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where('inventory_documents.company_id', $companyId)
            ->whereIn('inventory_documents.status', [InventoryDocument::StatusPosted, InventoryDocument::StatusReversed])
            ->whereIn('inventory_documents.document_type', $documentTypes)
            ->when($branchId !== null, fn ($query) => $query->where('inventory_documents.branch_id', $branchId))
            ->whereNull('inventory_document_lines.deleted_at');
        if ($reversal) {
            $periods = DB::table('inventory_transactions')->where('source_type', InventoryDocument::class)->where('is_reversal', true)
                ->where('company_id', $companyId)->select('source_id', 'financial_period_id')->distinct();
            $query->joinSub($periods, 'reversal_period', fn ($join) => $join->on('reversal_period.source_id', '=', 'inventory_documents.id'))
                ->where('inventory_documents.status', InventoryDocument::StatusReversed)
                ->when($periodId !== null, fn ($query) => $query->where('reversal_period.financial_period_id', $periodId));
        } else {
            $query->when($periodId !== null, fn ($query) => $query->where('inventory_documents.financial_period_id', $periodId));
        }

        return $query;
    }

    private function scopeCompletionPeriod(Builder $query, ?int $periodId, bool $reversal): void
    {
        if ($reversal) {
            $periods = DB::table('inventory_transactions')->where('source_type', InventoryDocument::class)->where('is_reversal', true)
                ->select('source_id', 'company_id', 'financial_period_id')->distinct();
            $query->joinSub($periods, 'completion_reversal_period', fn ($join) => $join->on('completion_reversal_period.source_id', '=', 'source_document.id')
                ->on('completion_reversal_period.company_id', '=', 'source_document.company_id'))
                ->where('source_document.status', InventoryDocument::StatusReversed)
                ->when($periodId !== null, fn ($query) => $query->where('completion_reversal_period.financial_period_id', $periodId));
        } else {
            $query->when($periodId !== null, fn ($query) => $query->where('correction.financial_period_id', $periodId));
        }
    }

    /** Reproduce the cumulative per-adjustment/account booking contract before selecting this event's attributed lines. */
    private function bookedCompletionEventAmount(Builder $events): string
    {
        return array_reduce($this->bookedCompletionEventGroups($events), fn (string $total, array $group): string => bcadd($total, bcmul($group['selected'], $group['sign'], 4), 4), '0.0000');
    }

    /** @return array<string, array{whole: string, selected: string, sign: string}> */
    private function bookedCompletionEventGroups(Builder $events): array
    {
        $selected = (clone $events)->select('correction_line.id as event_line_id', 'correction_line.inventory_value_adjustment_id as event_adjustment_id')
            ->selectRaw('case when source_document.document_type = ? then -1 else 1 end as event_sign', [InventoryDocument::TypeAdjustmentIn])->distinct();
        $adjustments = (clone $events)->select('correction_line.inventory_value_adjustment_id')->distinct();
        $lines = DB::table('inventory_value_adjustment_lines as booked_line')
            ->leftJoinSub($selected, 'selected_event', fn ($join) => $join->on('selected_event.event_line_id', '=', 'booked_line.id'))
            ->whereIn('booked_line.inventory_value_adjustment_id', $adjustments)
            ->orderBy('booked_line.inventory_value_adjustment_id')->orderBy('booked_line.id')
            ->select('booked_line.*', 'selected_event.event_sign')->cursor();
        $groups = [];
        $attributed = [];
        $totals = [];
        $numbers = new NumericFormatService;
        foreach ($lines as $line) {
            $key = implode(':', [$line->inventory_value_adjustment_id, $line->account_id, $line->branch_id, $line->cost_center_id ?? 'none']);
            if ($line->effect === InventoryValueAdjustmentLine::EffectCounterpart) {
                $snapshot = is_string($line->source_snapshot) ? json_decode($line->source_snapshot, true) : $line->source_snapshot;
                $booked = bcmul((string) ($snapshot['rounded_gl_total'] ?? '0'), '-1', 4);
            } else {
                $prior = $groups[$key] ?? '0.00000000';
                $groups[$key] = bcadd($prior, $numbers->normalizeScientificNotation((string) $line->amount) ?? '0', 8);
                $booked = bcsub(bcround($groups[$key], 4), bcround($prior, 4), 4);
            }
            $totals[$key] = bcadd($totals[$key] ?? '0', $booked, 4);
            if ($line->event_sign !== null) {
                $attributed[$key] ??= ['whole' => '0.0000', 'selected' => '0.0000', 'sign' => (string) $line->event_sign];
                $attributed[$key]['selected'] = bcadd($attributed[$key]['selected'], $booked, 4);
            }
        }
        foreach ($attributed as $key => &$group) {
            $group['whole'] = $totals[$key];
        }

        return $attributed;
    }

    private function amount(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.0000';
        }

        $numbers = new NumericFormatService;
        $decimal = is_string($value) && stripos($value, 'e') !== false
            ? $numbers->normalizeScientificNotation($value)
            : $numbers->normalize($value);

        return bcround($decimal ?? '0', 4);
    }

    /** @return array{key: string, label: string, subledger: string, gl: string, difference: string, status: string} */
    private function row(string $key, string $label, string $subledger, string $gl): array
    {
        $difference = bcsub($subledger, $gl, 4);

        return [
            'key' => $key,
            'label' => $label,
            'subledger' => $subledger,
            'gl' => $gl,
            'difference' => $difference,
            'status' => bccomp($difference, '0.0000', 4) === 0 ? 'reconciled' : 'difference',
        ];
    }
}
