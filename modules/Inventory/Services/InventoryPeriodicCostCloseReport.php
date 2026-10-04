<?php

namespace Modules\Inventory\Services;

use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\DateFormatService;
use Modules\Inventory\Models\InventoryPeriodicCostClose;

class InventoryPeriodicCostCloseReport
{
    /** @return array<int, string> */
    public function sourceJournalNumbers(InventoryPeriodicCostClose $close): array
    {
        $ids = collect($close->impact_snapshot['effects'])->where('effect', 'gl_precision')
            ->pluck('source_journal_id')->unique()->values()->all();

        return $ids === [] ? [] : JournalEntry::query()->where('company_id', $close->company_id)
            ->whereKey($ids)->pluck('doc_num', 'id')->all();
    }

    /** @return list<array{title: string, headings: list<string>, rows: list<list<string>>}> */
    public function sections(InventoryPeriodicCostClose $close): array
    {
        $dates = app(DateFormatService::class);
        $plan = $close->impact_snapshot;
        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $inputs = $issues = $effects = $precision = [];
        $journalNumbers = $this->sourceJournalNumbers($close);
        foreach ($plan['period_inputs'] as $input) {
            $label = $input['product_code'].' — '.$input['product_label'];
            $store = $close->scope_snapshot['store_labels'][$input['store_id']];
            $inputs[] = [$store, $label, __('inventory.movements.stock_statuses.'.$input['stock_status']),
                $input['opening_quantity'], $input['opening_value'], $input['receipt_quantity'], $input['receipt_value'],
                $input['average'], $input['closing_quantity']];
            foreach ($input['outflows'] as $issue) {
                $issues[] = [$issue['document'], $store, $label, $issue['quantity'],
                    $issue['provisional_cost'] ?? '', $issue['final_cost'], $issue['difference']];
            }
        }
        foreach ($plan['effects'] as $effect) {
            if ($effect['effect'] === 'gl_precision') {
                $precision[] = [$effect['source_doc_num'], $journalNumbers[$effect['source_journal_id']] ?? (string) $effect['source_journal_id'], $effect['account_labels'][$locale] ?? '',
                    $effect['branch_label'] ?? '', $effect['cost_center_labels'][$locale] ?? '', $effect['exact_total_cost'],
                    $effect['legacy_booked_amount'], $effect['canonical_rounded_amount'], $effect['amount']];

                continue;
            }
            $effects[] = [$effect['account_labels'][$locale] ?? '', $effect['branch_label'] ?? '',
                $effect['cost_center_labels'][$locale] ?? '', $effect['source_doc_num'] ?? '', $effect['amount']];
        }

        $sections = [
            ['title' => __('inventory_periodic_cost.title'), 'headings' => [__('inventory_periodic_cost.field'), __('Value')], 'rows' => [
                [__('Document'), $close->doc_num], [__('Status'), __('inventory_periodic_cost.statuses.'.$close->status)],
                [__('inventory_cost_policy.scope'), $close->scopeStore?->name ?? $close->scopeBranch?->name ?? __('inventory_cost_policy.company_scope')],
                [__('inventory_periodic_cost.from'), $dates->formatDate($close->from_date, '')],
                [__('inventory_periodic_cost.to'), $dates->formatDate($close->to_date, '')],
                [__('inventory_periodic_cost.posting_date'), $dates->formatDate($close->posting_date, '')],
                [__('inventory_periodic_cost.reason'), $close->reason], [__('inventory_periodic_cost.reference'), $close->approval_reference ?? ''],
                [__('inventory_periodic_cost.clearing'), $plan['counterpart_account_labels'][$locale] ?? ''],
                [__('inventory_periodic_cost.rejection_reason'), $close->rejection_reason ?? ''],
                [__('Journal Entry'), $close->valueAdjustment?->journalEntry?->doc_num ?? ''],
            ]],
            ['title' => __('inventory_periodic_cost.inputs'), 'headings' => [__('inventory_cost_policy.store'), __('Product'), __('Status'),
                ...array_map(fn (string $field): string => __('inventory_periodic_cost.'.$field),
                    ['opening_quantity', 'opening_value', 'receipt_quantity', 'receipt_value', 'average', 'closing_quantity'])], 'rows' => $inputs],
            ['title' => __('inventory_periodic_cost.outflows'), 'headings' => [__('Document'), __('inventory_cost_policy.store'), __('Product'),
                __('Quantity'), __('inventory_periodic_cost.provisional'), __('inventory_periodic_cost.final'), __('inventory_periodic_cost.difference')], 'rows' => $issues],
            ['title' => __('inventory_periodic_cost.effects'), 'headings' => [__('Account'), __('Branch'), __('Cost Center'), __('Source'),
                __('inventory_periodic_cost.difference')], 'rows' => $effects],
        ];
        if ($precision !== []) {
            $sections[] = ['title' => __('inventory_periodic_cost.precision'), 'headings' => [__('Source'), __('Journal Entry'), __('Account'),
                __('Branch'), __('Cost Center'), __('Cost'), __('inventory_periodic_cost.booked'), __('inventory_periodic_cost.rounded'),
                __('inventory_periodic_cost.difference')], 'rows' => $precision];
        }

        return $sections;
    }
}
