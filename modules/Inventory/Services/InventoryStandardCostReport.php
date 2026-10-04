<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Carbon;
use Modules\Core\Services\DateFormatService;
use Modules\Inventory\Models\InventoryCostStandard;
use Modules\Inventory\Models\InventoryStandardCostSettlement;

class InventoryStandardCostReport
{
    /** @return list<array{title: string, headings: list<string>, rows: list<list<string>>}> */
    public function sections(InventoryCostStandard|InventoryStandardCostSettlement $record): array
    {
        $dates = app(DateFormatService::class);
        $rows = [[__('Document'), $record->doc_num], [__('Status'), __('inventory_standard_cost.statuses.'.$record->status)],
            [__('inventory_standard_cost.reference'), $record->approval_reference ?? '']];
        $parts = $effects = [];
        if ($record instanceof InventoryCostStandard) {
            $record->loadMissing(['branch', 'product']);
            array_push($rows, [__('Branch'), $record->branch->name], [__('Product'), $record->product->doc_num.' — '.$record->product->name],
                [__('inventory_standard_cost.from'), $dates->formatDate($record->effective_from, '')], [__('inventory_standard_cost.to'), $dates->formatDate($record->effective_to, '')],
                [__('inventory_standard_cost.source'), $record->source_reference]);
            foreach (InventoryStandardCostService::Components as $component) {
                $account = $record->basis_snapshot['accounts'][$component];
                $parts[] = [__('inventory_standard_cost.'.$component), (string) $record->{$component.'_unit_cost'}, $account['account_code'].' — '.($account['name_en'] && app()->getLocale() !== 'ar' ? $account['name_en'] : $account['name'])];
            }
            $headings = [__('inventory_standard_cost.component'), __('Unit Cost'), __('inventory_standard_cost.variance_account')];
        } else {
            $record->loadMissing('valueAdjustment.journalEntry');
            $plan = $record->impact_snapshot;
            array_push($rows, [__('inventory_standard_cost.version'), $plan['standard_doc_num']], [__('Production Run'), $plan['run_number']],
                [__('Quantity'), $plan['good_quantity']], [__('inventory_standard_cost.posting_date'), $dates->formatDate($record->posting_date, '')],
                [__('inventory_standard_cost.reason'), $record->reason], [__('Journal Entry'), $record->valueAdjustment?->journalEntry?->doc_num ?? '']);
            foreach ($plan['components'] as $component => $part) {
                $parts[] = [__('inventory_standard_cost.'.$component), $part['unit_cost'], $part['standard_total'], $part['actual_total'], $part['variance'], $part['previous_variance']];
            }
            $headings = [__('inventory_standard_cost.component'), __('Unit Cost'), __('inventory_standard_cost.standard'), __('inventory_standard_cost.actual'), __('inventory_standard_cost.variance'), __('inventory_standard_cost.previous')];
            foreach ($plan['effects'] as $effect) {
                $effects[] = [$effect['source_doc_num'] ?? '', $effect['account_labels'][app()->getLocale() === 'ar' ? 'ar' : 'en'] ?? '',
                    ($effect['production_cost_role'] ?? null) === 'expense_capitalized'
                        ? __('inventory_standard_cost.capitalization') : __('inventory_standard_cost.effects.'.$effect['effect']), (string) $effect['amount']];
            }
        }
        $sections = [['title' => __('inventory_standard_cost.title'), 'headings' => [__('inventory_standard_cost.field'), __('Value')], 'rows' => $rows],
            ['title' => __('inventory_standard_cost.components'), 'headings' => $headings, 'rows' => $parts]];
        if ($record instanceof InventoryStandardCostSettlement) {
            $sections[] = ['title' => __('inventory_standard_cost.accounting'), 'headings' => [__('Source'), __('Account'), __('Type'), __('Amount')], 'rows' => $effects];
            $expenseRows = [];
            foreach ($plan['expense_sources'] ?? [] as $source) {
                $expense = $source['expense'];
                $rate = $source['effective_exchange_rate'] ?? $expense['exchange_rate'] ?? null;
                $expenseRows[] = [$expense['doc_num'], __('production_execution.statuses.'.$expense['status']),
                    $source['journal']['doc_num'] ?? '', (string) $expense['amount'], $source['currency_code'] ?? '',
                    (string) ($rate ?? ''), $rate === null ? '' : bcmul((string) $expense['amount'], (string) $rate, 8)];
            }
            if ($expenseRows !== []) {
                $sections[] = ['title' => __('inventory_standard_cost.expense_sources'),
                    'headings' => [__('Document'), __('Status'), __('Journal Entry'), __('Amount'), __('Currency'), __('Exchange Rate'), __('inventory_standard_cost.base_amount')], 'rows' => $expenseRows];
            }
            $allocationRows = [];
            foreach ($plan['allocation_sources'] ?? [] as $allocation) {
                foreach ($allocation['lines'] as $share) {
                    $allocationRows[] = [$allocation['journal_entry']['doc_num'] ?? '', __('inventory_standard_cost.source_statuses.'.$allocation['status']),
                        $dates->formatDate(Carbon::parse($allocation['from_date'])->timezone(config('app.timezone')), ''),
                        $dates->formatDate(Carbon::parse($allocation['to_date'])->timezone(config('app.timezone')), ''),
                        (string) $share['labor_hours'], (string) $share['allocated_amount']];
                }
            }
            if ($allocationRows !== []) {
                $sections[] = ['title' => __('inventory_standard_cost.allocation_sources'),
                    'headings' => [__('Journal Entry'), __('Status'), __('inventory_standard_cost.allocation_from'), __('inventory_standard_cost.allocation_to'), __('inventory_standard_cost.labor_hours'), __('inventory_standard_cost.run_share')], 'rows' => $allocationRows];
            }
            $wipRows = [];
            foreach ($plan['wip_sources']['balances'] ?? [] as $accountId => $balance) {
                $label = $plan['wip_sources']['accounts'][$accountId][app()->getLocale() === 'ar' ? 'ar' : 'en'] ?? '';
                $wipRows[] = [$label, $balance];
            }
            if ($wipRows !== []) {
                $sections[] = ['title' => __('inventory_standard_cost.wip_sources'), 'headings' => [__('Account'), __('inventory_standard_cost.before_settlement')], 'rows' => $wipRows];
            }
        }

        return $sections;
    }
}
