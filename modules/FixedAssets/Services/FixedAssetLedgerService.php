<?php

namespace Modules\FixedAssets\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Accounting\Models\JournalEntry;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetMovement;

class FixedAssetLedgerService
{
    /** @return list<string> */
    public static function types(): array
    {
        return ['creation', 'legacy_baseline', 'opening', 'capitalization', 'depreciation', 'addition', 'transfer', 'custody', 'disposal', 'reversal'];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function history(FixedAsset $asset, bool $forReport = false): Collection
    {
        $access = app(FixedAssetAccessService::class);
        if ($forReport) {
            $access->assertAssetHistory($asset);
        } else {
            $access->assertAsset($asset);
        }
        $card = route('admin.fixed-assets.lifecycle.show', $asset);
        $rows = collect([['date' => $asset->asset_date, 'type' => 'creation', 'document' => $asset->doc_num, 'amount' => null,
            'user_id' => $asset->created_by, 'journal' => null, 'url' => $card, 'reversed' => false, 'detail' => $asset->asset_name]]);
        if ($asset->hasLegacyRecognition()) {
            $rows->push(['date' => Carbon::parse($asset->legacy_recognition['captured_at']), 'type' => 'legacy_baseline', 'document' => $asset->doc_num, 'amount' => null,
                'user_id' => null, 'journal' => null, 'url' => $card, 'reversed' => false, 'detail' => __('fixed_assets.prerequisites.legacy_help')]);
        }
        foreach ($asset->movements()->reorder()->orderBy('movement_date')->orderBy('id')->with(['journalEntry', 'reversalJournalEntry', 'sourceCustodian', 'destinationCustodian', 'sourceBranch', 'destinationBranch', 'sourceCostCenter', 'destinationCostCenter'])->get() as $movement) {
            $detail = $movement->reason;
            if ($movement->revised_useful_life !== null) {
                $detail .= ' | '.__('fixed_assets.cycle.revised_life').': '.$movement->revised_useful_life;
            }
            if ($movement->revised_residual_value !== null) {
                $detail .= ' | '.__('fixed_assets.cycle.revised_residual').': '.$movement->revised_residual_value;
            }
            if (data_get($movement->snapshot, 'plan.usage_before_effective_date') !== null) {
                $detail .= ' | '.__('fixed_assets.cycle.usage_before_addition').': '.data_get($movement->snapshot, 'plan.usage_before_effective_date');
            }
            if ($movement->movement_type === 'custody') {
                $detail .= ' | '.($movement->sourceCustodian?->full_name ?: '—').' → '.($movement->destinationCustodian?->full_name ?: '—');
            } elseif ($movement->movement_type === 'transfer') {
                $detail .= ' | '.implode(' / ', array_filter([$movement->sourceBranch?->name, $movement->source_location_address, $movement->sourceCostCenter?->name])).' → '.implode(' / ', array_filter([$movement->destinationBranch?->name, $movement->destination_location_address, $movement->destinationCostCenter?->name]));
            }
            $rows->push(['date' => $movement->movement_date, 'type' => $movement->movement_type, 'document' => $movement->doc_num,
                'amount' => in_array($movement->movement_type, ['opening', 'capitalization', 'addition'], true) ? $movement->amount : null,
                'user_id' => $movement->posted_by, 'journal' => $movement->journalEntry, 'url' => $card.'?tab=movements#movement-'.$movement->doc_num,
                'reversed' => $movement->status === 'reversed', 'detail' => $detail]);
            if ($movement->status === 'reversed') {
                $rows->push(['date' => $movement->reversal_date, 'type' => $movement->movement_type.'_reversal', 'document' => $movement->doc_num,
                    'amount' => bcmul((string) $movement->amount, '-1', 4), 'user_id' => $movement->reversed_by, 'journal' => $movement->reversalJournalEntry,
                    'url' => $card.'?tab=movements#movement-'.$movement->doc_num, 'reversed' => false, 'detail' => $movement->reversal_reason]);
            }
        }
        foreach ($asset->depreciations()->reorder()->orderBy('id')->with(['run.reversalJournalEntry', 'journalEntry'])->get() as $depreciation) {
            $rows->push(['date' => $depreciation->period_end, 'type' => 'depreciation', 'document' => $depreciation->run->doc_num,
                'amount' => $depreciation->period_depreciation, 'user_id' => $depreciation->posted_by, 'journal' => $depreciation->journalEntry,
                'url' => route('admin.fixed-assets.depreciation.show', $depreciation->run), 'reversed' => $depreciation->status === 'reversed', 'detail' => $depreciation->period_start->toDateString().' → '.$depreciation->period_end->toDateString()]);
            if ($depreciation->status === 'reversed') {
                $rows->push(['date' => $depreciation->run->posting_date, 'type' => 'depreciation_reversal', 'document' => $depreciation->run->doc_num,
                    'amount' => bcmul((string) $depreciation->period_depreciation, '-1', 4), 'user_id' => $depreciation->reversed_by, 'journal' => $depreciation->run->reversalJournalEntry,
                    'url' => route('admin.fixed-assets.depreciation.show', $depreciation->run), 'reversed' => false, 'detail' => $depreciation->run->reversal_reason]);
            }
        }
        foreach ($asset->disposals()->reorder()->orderBy('id')->with(['journalEntry', 'reversalJournalEntry'])->get() as $disposal) {
            $rows->push(['date' => $disposal->disposal_date, 'type' => 'disposal', 'document' => $disposal->doc_num, 'amount' => $disposal->net_book_value,
                'user_id' => $disposal->posted_by, 'journal' => $disposal->journalEntry, 'url' => $card.'?tab=movements#disposal-'.$disposal->doc_num,
                'reversed' => $disposal->status === 'reversed', 'detail' => __('fixed_assets.lifecycle.disposition_types.'.$disposal->disposition_type).' / '.$disposal->reason]);
            if ($disposal->status === 'reversed') {
                $rows->push(['date' => $disposal->disposal_date, 'type' => 'disposal_reversal', 'document' => $disposal->doc_num,
                    'amount' => bcmul((string) $disposal->net_book_value, '-1', 4), 'user_id' => $disposal->reversed_by, 'journal' => $disposal->reversalJournalEntry,
                    'url' => $card.'?tab=movements#disposal-'.$disposal->doc_num, 'reversed' => false, 'detail' => $disposal->reversal_reason]);
            }
        }
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        $movements = $asset->movements()->get()->keyBy('doc_num');
        $depreciations = $asset->depreciations()->with('run')->get()->keyBy('run.doc_num');
        $disposals = $asset->disposals()->get()->keyBy('doc_num');

        return $rows->sortBy(fn (array $row): string => $row['date']?->format('Y-m-d H:i:s') ?: '')->values()->map(function (array $row) use ($asset, $users, $movements, $depreciations, $disposals): array {
            $document = $movements->get($row['document']) ?? $depreciations->get($row['document']) ?? $disposals->get($row['document']);
            $dimensions = app(FixedAssetDepreciationService::class)->accountingDimensionsAsOf($asset, $row['date']);
            $isMovement = $document instanceof FixedAssetMovement;

            return [...$row, 'user' => $users->get($row['user_id']),
                'branch_id' => $isMovement ? $document->source_branch_id : ($document?->branch_id ?? $dimensions['effective_branch_id']),
                'cost_center_id' => $isMovement ? $document->source_cost_center_id : ($document ? $document->cost_center_id : $dimensions['effective_cost_center_id']),
                'destination_branch_id' => $isMovement ? $document->destination_branch_id : null,
                'destination_cost_center_id' => $isMovement ? $document->destination_cost_center_id : null,
                'period_id' => $document?->financial_period_id];
        })->filter(fn (array $row): bool => ! $forReport || in_array((int) $row['branch_id'], $access->branchIds(), true) || in_array((int) $row['destination_branch_id'], $access->branchIds(), true))->values();
    }

    public function journals(FixedAsset $asset): Collection
    {
        $ids = $this->history($asset)->pluck('journal.id')->filter();
        foreach ($asset->disposals as $disposal) {
            $ids = $ids->concat([$disposal->gain_loss_journal_entry_id, $disposal->gain_loss_reversal_journal_entry_id, $disposal->expenses_journal_entry_id, $disposal->expenses_reversal_journal_entry_id, $disposal->customerInvoice?->journal_entry_id]);
        }

        return JournalEntry::query()->where('company_id', $asset->company_id)->whereIn('id', $ids->filter()->unique())->orderBy('entry_date')->get();
    }
}
