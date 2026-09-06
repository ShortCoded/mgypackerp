<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Finance\Models\OpeningBalance;
use Modules\Finance\Services\OpeningBalanceApprovalService;
use Modules\Finance\Services\OpeningBalanceService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FixedAssetCostMovementService
{
    public function __construct(
        private readonly FixedAssetAccessService $access,
        private readonly DocumentNumberService $documents,
        private readonly FinancialPeriodService $periods,
        private readonly JournalEntryService $journals,
        private readonly CrudAuditService $audit,
    ) {}

    public function recognize(FixedAsset $asset, string $date, ?string $existingJournalDocNum = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $date, $existingJournalDocNum): FixedAsset {
            $asset = $this->lock($asset);
            if ($asset->hasPostedRecognition()) {
                throw new DomainException(__('fixed_assets.cycle.already_recognized'));
            }
            if ($asset->isDisposed() && ! $existingJournalDocNum) {
                throw new DomainException(__('fixed_assets.cycle.legacy_review'));
            }
            $date = Carbon::parse($date)->startOfDay();
            $isOpening = $asset->entry_type === FixedAsset::EntryTypeOpeningAsset;
            $period = $existingJournalDocNum
                ? FinancialPeriod::query()->where('company_id', $asset->company_id)->whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)->firstOrFail()
                : $this->periods->resolveOpenForPostingDate((int) $asset->company_id, $date, lockForUpdate: true);
            $this->access->assertPeriod($period);
            if (! $existingJournalDocNum) {
                $this->postable((int) $asset->company_id, (int) $asset->account_id);
                $this->postable((int) $asset->company_id, (int) $asset->credit_account_id);
            }
            if (! $existingJournalDocNum && (int) $asset->credit_account_id === (int) $asset->account_id) {
                throw new DomainException(__('fixed_assets.cycle.invalid_counter'));
            }
            $dimensions = app(FixedAssetDepreciationService::class)->accountingDimensionsAsOf($asset, $date);
            $this->access->assertBranch((int) $dimensions['effective_branch_id']);
            $postingDimensions = ['branch_id' => $dimensions['effective_branch_id'], 'cost_center_id' => $dimensions['effective_cost_center_id']];
            $cost = (string) $asset->purchase_value;
            $accumulated = $isOpening ? (string) ($asset->previous_depreciation ?: '0') : '0';
            $residual = (string) ($asset->salvage_value ?: '0');
            if (bccomp($cost, '0', 4) <= 0 || bccomp($residual, '0', 4) < 0 || bccomp($residual, $cost, 4) > 0 || bccomp($accumulated, '0', 4) < 0 || bccomp($accumulated, bcsub($cost, $residual, 4), 4) > 0
                || (! $isOpening && (bccomp((string) ($asset->previous_depreciation ?: '0'), '0', 4) !== 0 || $asset->previous_depreciation_until_date))) {
                throw new DomainException(__('fixed_assets.cycle.invalid_basis'));
            }
            $originalDate = $asset->acquisition_date ?: $asset->purchase_date ?: $asset->asset_date;
            if ($date->lt($originalDate) || ($isOpening && $asset->is_depreciable && (! $asset->previous_depreciation_until_date || (! $date->isSameDay($asset->previous_depreciation_until_date) && ! $date->isSameDay($asset->previous_depreciation_until_date->copy()->addDay()))))) {
                throw new DomainException(__('fixed_assets.cycle.recognition_date'));
            }
            $isPurchaseInvoiceRecognition = filled($existingJournalDocNum)
                && $asset->source_type === FixedAssetPurchaseIntegrationService::SourceType;
            if (! $isOpening && (($asset->depreciation_start_date && $date->gt($asset->depreciation_start_date))
                || (! $isPurchaseInvoiceRecognition && $asset->operation_date && $date->lt($asset->operation_date)))) {
                throw new DomainException(__('fixed_assets.cycle.recognition_before_depreciation'));
            }
            $mapping = FixedAssetCategoryMapping::resolveForAsset($asset,
                bccomp($accumulated, '0', 4) > 0 ? ['accumulated_depreciation_account_id'] : []);
            $rate = (string) ($asset->exchange_rate ?: '1');
            if (bccomp($rate, '0', 6) <= 0) {
                throw new DomainException(__('fixed_assets.cycle.invalid_basis'));
            }
            $movement = $this->movement($asset, [
                'movement_type' => $isOpening ? FixedAssetMovement::TypeOpening : FixedAssetMovement::TypeCapitalization,
                'source_branch_id' => $postingDimensions['branch_id'], 'destination_branch_id' => $postingDimensions['branch_id'],
                'source_cost_center_id' => $postingDimensions['cost_center_id'], 'destination_cost_center_id' => $postingDimensions['cost_center_id'],
                'movement_date' => $date, 'financial_period_id' => $period->getKey(),
                'counter_account_id' => $asset->credit_account_id, 'currency_id' => $asset->currency_id, 'exchange_rate' => $rate,
                'amount' => $cost, 'base_amount' => bcmul($cost, $rate, 4),
                'opening_accumulated' => $accumulated, 'base_opening_accumulated' => bcmul($accumulated, $rate, 4),
                'reason' => __('fixed_assets.cycle.recognition'),
                'snapshot' => [
                    'account_id' => $asset->account_id,
                    'accumulated_account_id' => $mapping?->accumulated_depreciation_account_id,
                    'residual_value' => $residual, 'useful_life' => $asset->useful_life,
                    'original_depreciation_start_date' => $asset->depreciation_start_date?->toDateString(),
                    'previous_depreciation_until_date' => $asset->previous_depreciation_until_date?->toDateString(),
                    'source_type' => $asset->source_type, 'source_id' => $asset->source_id, 'source_doc_num' => $asset->source_doc_num,
                    'legacy_link' => (bool) $existingJournalDocNum,
                ],
            ]);
            $journal = null;
            $opening = null;
            if ($existingJournalDocNum) {
                $journal = $this->existingRecognition($asset, $movement, $existingJournalDocNum, $isOpening);
            } else {
                $priorReversals = $asset->costMovements()->where('status', 'reversed')->get();
                $ownJournalIds = $priorReversals->pluck('journal_entry_id')->merge($priorReversals->pluck('reversal_journal_entry_id'))->filter()->all();
                $hasExistingCost = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
                    ->whereNotIn('j.id', $ownJournalIds)->where('l.account_id', $asset->account_id)->where('j.company_id', $asset->company_id)->where('j.is_posted', true)->whereNull('j.deleted_at')->exists();
                $hasOpening = DB::table('opening_balance_lines as l')->join('opening_balances as o', 'o.id', '=', 'l.opening_balance_id')
                    ->where('l.account_id', $asset->account_id)->where('o.company_id', $asset->company_id)->whereNull('o.deleted_at')->where('o.is_cancelled', false)->exists();
                if ($hasExistingCost || $hasOpening || $asset->postedDepreciations()->exists()) {
                    throw new DomainException(__('fixed_assets.cycle.legacy_review'));
                }
                if ($asset->source_type && FixedAsset::query()->where('company_id', $asset->company_id)->where('source_type', $asset->source_type)->where('source_id', $asset->source_id)->whereKeyNot($asset->getKey())->whereHas('costMovements', fn ($query) => $query->where('status', 'posted'))->exists()) {
                    throw new DomainException(__('fixed_assets.cycle.duplicate_source'));
                }
                $lines = [$this->line($asset, (int) $asset->account_id, $cost, '0', $postingDimensions)];
                if (bccomp($accumulated, '0', 4) > 0) {
                    $this->postable((int) $asset->company_id, (int) $mapping->accumulated_depreciation_account_id);
                    $lines[] = $this->line($asset, (int) $mapping->accumulated_depreciation_account_id, '0', $accumulated, $postingDimensions);
                }
                $net = bcsub($cost, $accumulated, 4);
                if (bccomp($net, '0', 4) > 0) {
                    $lines[] = $this->line($asset, (int) $asset->credit_account_id, '0', $net, $postingDimensions);
                }
                if ($isOpening) {
                    if (! $period->allows_opening_entries) {
                        throw new DomainException(__('opening_balances.messages.period_disallows_opening_entries'));
                    }
                    $opening = app(OpeningBalanceService::class)->create([
                        'document_date' => $date->toDateString(), 'currency_doc_num' => $asset->currency->doc_num,
                        'exchange_rate' => $rate, 'description' => $movement->reason.' '.$asset->doc_num,
                        'lines' => array_map(function (array $line): array {
                            return [...$line, 'account_doc_num' => Account::query()->findOrFail($line['account_id'])->doc_num,
                                'transaction_type' => bccomp($line['debit_amount'], '0', 4) > 0 ? 'debit' : 'credit',
                                'amount' => bcadd($line['debit_amount'], $line['credit_amount'], 4)];
                        }, $lines),
                    ])['record'];
                    if ((int) $opening->financial_period_id !== (int) $period->getKey()) {
                        throw new DomainException(__('fixed_assets.cycle.select_posting_period'));
                    }
                    $opening = app(OpeningBalanceApprovalService::class)->approve($opening);
                    $journal = $opening->journalEntry;
                } else {
                    $journal = $this->journals->createPostedFromSource($this->header($asset, $movement), $lines);
                }
            }
            $movement->forceFill(['journal_entry_id' => $journal->getKey(), 'opening_balance_id' => $opening?->getKey() ?? OpeningBalance::query()->where('journal_entry_id', $journal->getKey())->value('id')])->save();
            $values = ['capitalized_at' => $date, 'capitalized_by' => auth()->id(), 'locked_at' => $asset->locked_at ?: now()];
            $values['net_value'] = app(FixedAssetBookValueService::class)->position($asset)['net_book_value'];
            if (! $asset->isDisposed() && $asset->status !== FixedAsset::StatusSuspended && (! $existingJournalDocNum || $asset->status === FixedAsset::StatusDraft)) {
                $values['status'] = bccomp(bcsub($cost, $accumulated, 4), $residual, 4) <= 0 && $asset->is_depreciable ? FixedAsset::StatusFullyDepreciated : FixedAsset::StatusActive;
            }
            $this->audit->saveUpdate($asset, $values);
            $this->log($asset, $movement, 'post');

            return $asset->refresh();
        }, attempts: 3);
    }

    /** @param array{source_type?: string, source_id?: int, source_doc_num?: string} $source */
    public function addition(FixedAsset $asset, array $data, ?JournalEntry $existingJournal = null, array $source = []): FixedAssetMovement
    {
        return DB::transaction(function () use ($asset, $data, $existingJournal, $source): FixedAssetMovement {
            $asset = $this->lock($asset);
            if (! Str::isUuid($data['submission_key'] ?? '')) {
                throw new DomainException(__('fixed_assets.cycle.invalid_submission'));
            }
            ksort($data);
            $submissionHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = filled($source['source_type'] ?? null) && filled($source['source_id'] ?? null)
                ? FixedAssetMovement::query()
                    ->where('company_id', $asset->company_id)
                    ->where('source_type', $source['source_type'])
                    ->where('source_id', $source['source_id'])
                    ->lockForUpdate()
                    ->first()
                : $asset->costMovements()->where('movement_type', FixedAssetMovement::TypeAddition)
                    ->where('snapshot->submission_key', $data['submission_key'])->first();
            if ($existing) {
                if ((int) $existing->fixed_asset_id !== (int) $asset->getKey()
                    || ! hash_equals((string) data_get($existing->snapshot, 'submission_hash'), $submissionHash)) {
                    throw new DomainException(__('fixed_assets.cycle.invalid_submission'));
                }

                return $existing;
            }
            if (! $asset->hasPostedRecognition() || $asset->isDisposed()) {
                throw new DomainException(__('fixed_assets.cycle.recognition_required'));
            }
            $date = Carbon::parse($data['movement_date']);
            $this->assertChronology($asset, $date);
            $period = $this->periods->resolveOpenForPostingDate((int) $asset->company_id, $date, lockForUpdate: true);
            $this->access->assertPeriod($period);
            $counter = Account::query()->where('company_id', $asset->company_id)->where('doc_num', $data['counter_account_doc_num'])->firstOrFail();
            $this->postable((int) $asset->company_id, (int) $counter->getKey());
            $this->postable((int) $asset->company_id, (int) $asset->account_id);
            if ((int) $counter->getKey() === (int) $asset->account_id) {
                throw new DomainException(__('fixed_assets.cycle.invalid_counter'));
            }
            $position = app(FixedAssetBookValueService::class)->position($asset, $date);
            $amount = (string) $data['amount'];
            $residual = (string) ($data['revised_residual_value'] ?? $position['residual_value']);
            if (isset($data['revised_useful_life']) && ! in_array($asset->depreciation_method, [FixedAsset::DepreciationMethodStraightLine, FixedAsset::DepreciationMethodDoubleDecliningBalance, FixedAsset::DepreciationMethodSumOfYearsDigits], true)) {
                throw new DomainException(__('fixed_assets.cycle.invalid_basis'));
            }
            if (bccomp($amount, '0', 4) <= 0 || bccomp($residual, '0', 4) < 0 || bccomp($residual, bcadd($position['net_book_value'], $amount, 4), 4) > 0 || (isset($data['revised_useful_life']) && bccomp((string) $data['revised_useful_life'], '0', 2) <= 0)) {
                throw new DomainException(__('fixed_assets.cycle.invalid_basis'));
            }
            $rate = $asset->currency->is_main ? '1.000000' : (string) ($data['exchange_rate'] ?? $asset->exchange_rate ?: '1');
            if (bccomp($rate, '0', 6) <= 0) {
                throw new DomainException(__('fixed_assets.cycle.invalid_basis'));
            }
            $calculator = app(FixedAssetDepreciationCalculator::class);
            $depreciation = app(FixedAssetDepreciationService::class);
            $pendingStart = $depreciation->nextUnpostedDate($asset);
            if ($depreciation->hasHistoricalGap($asset)) {
                throw new DomainException(__('fixed_assets.cycle.historical_depreciation_gap', ['period' => $pendingStart->format('Y-m')]));
            }
            $usageBefore = null;
            if ($asset->depreciation_method === FixedAsset::DepreciationMethodUnitsOfProduction) {
                if (bccomp($position['remaining_depreciable_amount'], '0', 4) === 0) {
                    $pendingStart = $date->copy();
                }
                $usageBefore = (string) ($data['actual_usage_before_addition'] ?? '0');
                if ($pendingStart && $pendingStart->lt($date->copy()->startOfMonth())) {
                    throw new DomainException(__('fixed_assets.cycle.missing_period', ['period' => $pendingStart->format('Y-m')]));
                }
                if (bccomp($usageBefore, '0', 4) < 0 || ($pendingStart && $pendingStart->lt($date) && ! isset($data['actual_usage_before_addition']))
                    || ($pendingStart && $pendingStart->gte($date) && bccomp($usageBefore, '0', 4) !== 0)) {
                    throw new DomainException(__('fixed_assets.cycle.usage_before_required'));
                }
                $sameDay = $asset->costMovements()->where('movement_type', 'addition')->where('status', 'posted')->whereDate('movement_date', $date)->first();
                if ($sameDay && bccomp($usageBefore, (string) data_get($sameDay->snapshot, 'plan.usage_before_effective_date', '0'), 4) !== 0) {
                    throw new DomainException(__('fixed_assets.cycle.usage_split_mismatch'));
                }
            }
            $pending = $pendingStart && $pendingStart->lt($date) ? $calculator->snapshot($asset, $pendingStart, $date->copy()->subDay(), app(FixedAssetBookValueService::class)->position($asset, $pendingStart), $usageBefore) : null;
            $effectiveNBV = bcsub($position['net_book_value'], $pending['period_depreciation'] ?? '0', 4);
            $effectiveBaseNBV = bcsub($position['base_net_book_value'], $pending['base_period_depreciation'] ?? '0', 4);
            if (bccomp($residual, bcadd($effectiveNBV, $amount, 4), 4) > 0
                || bccomp(bcmul($residual, (string) ($asset->exchange_rate ?: '1'), 4), bcadd($effectiveBaseNBV, bcmul($amount, $rate, 4), 4), 4) > 0) {
                throw new DomainException(__('fixed_assets.cycle.invalid_basis'));
            }
            $elapsed = $asset->depreciation_start_date ? max(0, $asset->depreciation_start_date->diffInDays($date) / $calculator->dayBasis()) : 0;
            $latestPlan = $asset->costMovements()->where('movement_type', 'addition')->where('status', 'posted')->reorder()->orderByDesc('movement_date')->orderByDesc('id')->first()?->snapshot['plan'] ?? [];
            $unadjustedRemaining = isset($latestPlan['end_date']) ? $date->diffInDays(Carbon::parse($latestPlan['end_date'])->addDay()) / $calculator->dayBasis() : (float) $asset->useful_life - $elapsed;
            if ($unadjustedRemaining <= 0 && ! isset($data['revised_useful_life']) && in_array($asset->depreciation_method, [FixedAsset::DepreciationMethodStraightLine, FixedAsset::DepreciationMethodDoubleDecliningBalance, FixedAsset::DepreciationMethodSumOfYearsDigits], true)) {
                throw new DomainException(__('fixed_assets.cycle.revised_life_required'));
            }
            $remaining = max(1 / $calculator->dayBasis(), $unadjustedRemaining);
            $remainingLife = (float) ($data['revised_useful_life'] ?? $remaining);
            $plan = ['residual_value' => $residual, 'useful_life' => $remainingLife,
                'annual_amount' => bcdiv(bcsub(bcadd($effectiveNBV, $amount, 4), $residual, 4), number_format($remainingLife, 8, '.', ''), 8),
                'effective_date' => $date->toDateString(), 'basis' => bcsub(bcadd($effectiveNBV, $amount, 4), $residual, 4),
                'end_date' => $asset->useful_life ? $date->copy()->addDays((int) round($remainingLife * $calculator->dayBasis()))->subDay()->toDateString() : null];
            if ($asset->depreciation_method === FixedAsset::DepreciationMethodUnitsOfProduction) {
                $used = (string) $asset->postedDepreciations()->sum('usage_units');
                $originalBase = bcsub((string) $asset->purchase_value, (string) $asset->salvage_value, 4);
                $openingUnits = bccomp($originalBase, '0', 4) > 0 ? bcmul(bcdiv((string) ($asset->previous_depreciation ?: '0'), $originalBase, 8), (string) $asset->expected_usage_units, 4) : '0';
                $remainingUnits = (string) ($data['estimated_remaining_units'] ?? bcsub((string) $asset->expected_usage_units, bcadd(bcadd($used, $openingUnits, 4), $usageBefore, 4), 4));
                if (! isset($data['estimated_remaining_units']) && isset($latestPlan['estimated_remaining_units'], $latestPlan['effective_date'])) {
                    $usedSincePlan = (string) $asset->postedDepreciations()->whereDate('period_end', '>=', $latestPlan['effective_date'])->sum('usage_units');
                    $usedSincePlan = bcsub(bcadd($usedSincePlan, $usageBefore, 4), (string) ($latestPlan['usage_before_effective_date'] ?? '0'), 4);
                    $remainingUnits = bcsub((string) $latestPlan['estimated_remaining_units'], $usedSincePlan, 4);
                }
                if (bccomp($remainingUnits, '0', 4) <= 0) {
                    throw new DomainException(__('fixed_assets.cycle.invalid_basis'));
                }
                $plan['unit_rate'] = bcdiv($plan['basis'], $remainingUnits, 8);
                $plan['estimated_remaining_units'] = $remainingUnits;
                $plan['usage_before_effective_date'] = $usageBefore;
            }
            $movement = $this->movement($asset, [
                'movement_type' => FixedAssetMovement::TypeAddition,
                'source_type' => $source['source_type'] ?? null, 'source_id' => $source['source_id'] ?? null, 'source_doc_num' => $source['source_doc_num'] ?? null,
                'movement_date' => $date, 'financial_period_id' => $period->getKey(),
                'counter_account_id' => $counter->getKey(), 'currency_id' => $asset->currency_id, 'exchange_rate' => $rate,
                'amount' => $amount, 'base_amount' => bcmul($amount, $rate, 4),
                'reason' => $data['description'], 'notes' => $data['notes'] ?? null,
                'revised_useful_life' => $data['revised_useful_life'] ?? null, 'revised_residual_value' => $data['revised_residual_value'] ?? null,
                'snapshot' => ['account_id' => $asset->account_id, 'position_before' => $position, 'plan' => $plan,
                    'submission_key' => $data['submission_key'], 'submission_hash' => $submissionHash,
                    'external_journal' => $existingJournal instanceof JournalEntry],
            ]);
            $journal = $existingJournal instanceof JournalEntry
                ? $this->existingAdditionJournal($asset, $movement, $existingJournal)
                : $this->journals->createPostedFromSource($this->header($asset, $movement), [
                    $this->line($asset, (int) $asset->account_id, $amount, '0'),
                    $this->line($asset, (int) $counter->getKey(), '0', $amount),
                ]);
            $movement->forceFill(['journal_entry_id' => $journal->getKey()])->save();
            $this->audit->saveUpdate($asset, ['net_value' => bcadd($position['net_book_value'], $amount, 4), 'status' => $asset->status === FixedAsset::StatusFullyDepreciated ? FixedAsset::StatusActive : $asset->status]);
            $this->log($asset, $movement, 'post');

            return $movement->refresh();
        }, attempts: 3);
    }

    public function canReverse(FixedAssetMovement $movement): bool
    {
        if ($movement->status !== FixedAssetMovement::StatusPosted || ! in_array($movement->movement_type, FixedAssetMovement::costTypes(), true) || data_get($movement->snapshot, 'legacy_link', false) || data_get($movement->snapshot, 'external_journal', false)) {
            return false;
        }
        try {
            $this->assertChronology($movement->asset, $movement->movement_date, (int) $movement->getKey());
            $period = $this->periods->resolveOpenForPostingDate((int) $movement->company_id, $movement->movement_date, expectedPeriodId: $movement->financial_period_id);
            $this->access->assertPeriod($period);

            return (bool) $movement->journalEntry?->is_posted && $movement->journalEntry->reversed_entry_id === null;
        } catch (DomainException|HttpException $exception) {
            return false;
        }
    }

    public function reverse(FixedAssetMovement $movement, string $reason): FixedAssetMovement
    {
        return DB::transaction(function () use ($movement, $reason): FixedAssetMovement {
            $asset = $this->lock($movement->asset);
            $movement = $asset->movements()->whereKey($movement->getKey())->lockForUpdate()->firstOrFail();
            if ($movement->status !== FixedAssetMovement::StatusPosted || ! in_array($movement->movement_type, FixedAssetMovement::costTypes(), true) || data_get($movement->snapshot, 'legacy_link', false) || data_get($movement->snapshot, 'external_journal', false)) {
                throw new DomainException(__('fixed_assets.cycle.reversal_blocked'));
            }
            $this->assertChronology($asset, $movement->movement_date, (int) $movement->getKey());
            $period = $this->periods->resolveOpenForPostingDate((int) $asset->company_id, $movement->movement_date, expectedPeriodId: $movement->financial_period_id, lockForUpdate: true);
            $this->access->assertPeriod($period);
            $journal = $this->journals->createPostedReversalFromSource($movement->journalEntry, [...$this->header($asset, $movement), 'source_type' => 'fixed_asset_'.$movement->movement_type.'_reversal', 'description' => $reason]);
            $movement->forceFill(['status' => FixedAssetMovement::StatusReversed, 'reversal_journal_entry_id' => $journal->getKey(), 'reversal_date' => $movement->movement_date, 'reversed_at' => now(), 'reversed_by' => auth()->id(), 'reversal_reason' => $reason])->save();
            if ($movement->movement_type !== FixedAssetMovement::TypeAddition) {
                if ($movement->opening_balance_id) {
                    $opening = OpeningBalance::query()->where('company_id', $asset->company_id)->whereKey($movement->opening_balance_id)->lockForUpdate()->firstOrFail();
                    $opening->forceFill(['is_cancelled' => true, 'status' => OpeningBalance::StatusCancelled, 'updated_by' => auth()->id()])->save();
                }
                $this->audit->saveUpdate($asset, ['capitalized_at' => null, 'capitalized_by' => null, 'locked_at' => null, 'status' => FixedAsset::StatusDraft, 'net_value' => bcsub((string) $asset->purchase_value, (string) ($asset->previous_depreciation ?: '0'), 4)]);
                $this->log($asset, $movement, 'reverse');

                return $movement->refresh();
            }
            $position = app(FixedAssetBookValueService::class)->position($asset);
            $this->audit->saveUpdate($asset, ['net_value' => $position['net_book_value'], 'status' => $asset->status === FixedAsset::StatusSuspended ? $asset->status : (bccomp($position['remaining_depreciable_amount'], '0', 4) <= 0 ? FixedAsset::StatusFullyDepreciated : FixedAsset::StatusActive)]);
            $this->log($asset, $movement, 'reverse');

            return $movement->refresh();
        }, attempts: 3);
    }

    /** @param list<int> $batchMovementIds */
    public function assertExternalAdditionReversible(FixedAssetMovement $movement, array $batchMovementIds = []): void
    {
        $asset = $this->lock($movement->asset);
        $movement = $asset->movements()->whereKey($movement->getKey())->lockForUpdate()->firstOrFail();
        if ($movement->status !== FixedAssetMovement::StatusPosted
            || $movement->movement_type !== FixedAssetMovement::TypeAddition
            || ! data_get($movement->snapshot, 'external_journal', false)) {
            throw new DomainException(__('fixed_assets.cycle.reversal_blocked'));
        }

        $this->assertChronology($asset, $movement->movement_date, (int) $movement->getKey(), $batchMovementIds);
    }

    public function reverseExternalAddition(FixedAssetMovement $movement, JournalEntry $reversal, string $reason): FixedAssetMovement
    {
        return DB::transaction(function () use ($movement, $reversal, $reason): FixedAssetMovement {
            $this->assertExternalAdditionReversible($movement);
            $asset = $this->lock($movement->asset);
            $movement = $asset->movements()->whereKey($movement->getKey())->lockForUpdate()->firstOrFail();
            $original = JournalEntry::query()->lockForUpdate()->findOrFail($movement->journal_entry_id);
            $reversal = JournalEntry::query()->where('company_id', $asset->company_id)->whereKey($reversal->getKey())->where('is_posted', true)->firstOrFail();
            if ((int) $original->reversed_entry_id !== (int) $reversal->getKey()) {
                throw new DomainException(__('fixed_assets.cycle.reversal_blocked'));
            }

            $movement->forceFill([
                'status' => FixedAssetMovement::StatusReversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversal_date' => $reversal->entry_date,
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => $reason,
            ])->save();
            $position = app(FixedAssetBookValueService::class)->position($asset);
            $this->audit->saveUpdate($asset, [
                'net_value' => $position['net_book_value'],
                'status' => $asset->status === FixedAsset::StatusSuspended
                    ? $asset->status
                    : (bccomp($position['remaining_depreciable_amount'], '0', 4) <= 0 ? FixedAsset::StatusFullyDepreciated : FixedAsset::StatusActive),
            ]);
            $this->log($asset, $movement, 'reverse');

            return $movement->refresh();
        }, attempts: 3);
    }

    private function existingRecognition(FixedAsset $asset, FixedAssetMovement $movement, string $docNum, bool $opening): JournalEntry
    {
        $journal = JournalEntry::query()->where('company_id', $asset->company_id)->where('doc_num', $docNum)->where('is_posted', true)->whereNull('reversed_entry_id')->with('lines')->lockForUpdate()->firstOrFail();
        $costLines = $journal->lines->where('account_id', $asset->account_id);
        $cost = $costLines->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), 4), '0');
        if (($opening && $journal->source_type !== 'opening_balance') || ! $journal->entry_date->isSameDay($movement->movement_date)
            || bccomp(bcmul($cost, (string) $journal->exchange_rate, 4), (string) $movement->base_amount, 4) !== 0
            || $costLines->contains(fn ($line): bool => (int) $line->branch_id !== (int) $movement->source_branch_id || (int) $line->cost_center_id !== (int) $movement->source_cost_center_id)) {
            throw new DomainException(__('fixed_assets.cycle.legacy_mismatch'));
        }
        $disposals = $asset->disposals()->get();
        $knownJournalIds = $disposals->pluck('journal_entry_id')->merge($disposals->pluck('reversal_journal_entry_id'))->push($journal->getKey())->filter();
        $unexplainedCost = DB::table('journal_entry_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->where('j.company_id', $asset->company_id)->where('j.is_posted', true)->whereNull('j.deleted_at')
            ->where('l.account_id', $asset->account_id)->whereNotIn('j.id', $knownJournalIds)
            ->whereRaw('l.debit_amount <> l.credit_amount')->exists();
        if ($unexplainedCost) {
            throw new DomainException(__('fixed_assets.cycle.legacy_mismatch'));
        }
        $accumulatedAccount = data_get($movement->snapshot, 'accumulated_account_id');
        if (bccomp((string) $movement->opening_accumulated, '0', 4) > 0) {
            $available = $journal->lines->where('account_id', $accumulatedAccount)->where('branch_id', $movement->source_branch_id)->where('cost_center_id', $movement->source_cost_center_id)
                ->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub((string) $line->credit_amount, (string) $line->debit_amount, 4), 4), '0');
            $allocated = FixedAssetMovement::query()->where('journal_entry_id', $journal->getKey())->where('status', 'posted')->where('snapshot->accumulated_account_id', $accumulatedAccount)->where('source_branch_id', $movement->source_branch_id)->where('source_cost_center_id', $movement->source_cost_center_id)->sum('base_opening_accumulated');
            if (bccomp(bcadd((string) $allocated, (string) $movement->base_opening_accumulated, 4), bcmul($available, (string) $journal->exchange_rate, 4), 4) > 0) {
                throw new DomainException(__('fixed_assets.cycle.legacy_mismatch'));
            }
        }

        return $journal;
    }

    private function existingAdditionJournal(FixedAsset $asset, FixedAssetMovement $movement, JournalEntry $journal): JournalEntry
    {
        $journal = JournalEntry::query()
            ->where('company_id', $asset->company_id)
            ->whereKey($journal->getKey())
            ->where('source_type', 'purchase_invoice')
            ->where('is_posted', true)
            ->whereNull('reversed_entry_id')
            ->with('lines')
            ->lockForUpdate()
            ->firstOrFail();
        $sourceLine = PurchaseInvoiceLine::query()
            ->where('company_id', $asset->company_id)
            ->whereKey($movement->source_id)
            ->where('target_fixed_asset_id', $asset->getKey())
            ->first();
        if ($movement->source_type !== FixedAssetPurchaseIntegrationService::ImprovementSourceType
            || ! $sourceLine instanceof PurchaseInvoiceLine
            || (int) $sourceLine->purchase_invoice_id !== (int) $journal->source_id
            || $journal->entry_date->gt($movement->movement_date)
            || (int) $journal->currency_id !== (int) $movement->currency_id
            || bccomp((string) $journal->exchange_rate, (string) $movement->exchange_rate, 6) !== 0) {
            throw new DomainException(__('fixed_assets.purchase_source.improvement_context_mismatch'));
        }

        $available = $journal->lines
            ->where('account_id', $asset->account_id)
            ->where('branch_id', $movement->source_branch_id)
            ->where('cost_center_id', $movement->source_cost_center_id)
            ->reduce(fn (string $sum, $line): string => bcadd($sum, bcsub((string) $line->debit_amount, (string) $line->credit_amount, 4), 4), '0.0000');
        $allocated = FixedAssetMovement::query()
            ->where('journal_entry_id', $journal->getKey())
            ->whereKeyNot($movement->getKey())
            ->where('status', FixedAssetMovement::StatusPosted)
            ->where('source_branch_id', $movement->source_branch_id)
            ->where('source_cost_center_id', $movement->source_cost_center_id)
            ->where('snapshot->account_id', $asset->account_id)
            ->sum('amount');
        if (bccomp(bcadd((string) $allocated, (string) $movement->amount, 4), $available, 4) > 0) {
            throw new DomainException(__('fixed_assets.purchase_source.improvement_context_mismatch'));
        }

        return $journal;
    }

    private function lock(FixedAsset $asset): FixedAsset
    {
        $this->access->assertAsset($asset);

        $asset = FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
        $this->access->assertAsset($asset);

        return $asset;
    }

    private function postable(int $companyId, int $id): Account
    {
        $account = Account::query()->where('company_id', $companyId)->whereKey($id)->where('status', 'active')->where('is_postable', true)->where('is_group', false)->first();
        if (! $account) {
            throw new DomainException(__('fixed_assets.lifecycle.errors.account_unavailable'));
        }

        return $account;
    }

    /** @param list<int> $exceptMovementIds */
    private function assertChronology(FixedAsset $asset, Carbon $date, ?int $exceptMovement = null, array $exceptMovementIds = []): void
    {
        if ($asset->isDisposed() || $date->lt(Carbon::parse($asset->capitalized_at ?: $asset->operation_date ?: $asset->asset_date)->startOfDay())
            || $asset->postedDepreciations()->whereDate('period_end', '>=', $date)->exists()
            || $asset->movements()->where('status', 'posted')
                ->when($exceptMovement, fn ($query) => $query->whereKeyNot($exceptMovement))
                ->when($exceptMovementIds !== [], fn ($query) => $query->whereNotIn('id', $exceptMovementIds))
                ->where(function ($query) use ($date, $exceptMovement): void {
                    $query->whereDate('movement_date', '>', $date);
                    if ($exceptMovement) {
                        $query->orWhere(fn ($sameDay) => $sameDay->whereDate('movement_date', $date)->where('id', '>', $exceptMovement));
                    }
                })->exists()) {
            throw new DomainException(__('fixed_assets.cycle.later_movements'));
        }
    }

    private function movement(FixedAsset $asset, array $values): FixedAssetMovement
    {
        return $asset->movements()->create([
            ...$this->documents->nextForCompany('fixed_asset_movements', FixedAssetMovement::class, (int) $asset->company_id),
            'company_id' => $asset->company_id, 'source_branch_id' => $asset->branch_id, 'destination_branch_id' => $asset->branch_id,
            'source_cost_center_id' => $asset->cost_center_id, 'destination_cost_center_id' => $asset->cost_center_id,
            'status' => 'posted', 'requested_by' => auth()->id(), 'approved_by' => auth()->id(), 'approved_at' => now(),
            'posted_by' => auth()->id(), 'posted_at' => now(), 'created_by' => auth()->id(), ...$values,
        ]);
    }

    private function header(FixedAsset $asset, FixedAssetMovement $movement): array
    {
        return ['entry_date' => $movement->movement_date, 'company_id' => $asset->company_id, 'financial_period_id' => $movement->financial_period_id,
            'branch_id' => $movement->source_branch_id, 'currency_id' => $movement->currency_id, 'exchange_rate' => $movement->exchange_rate,
            'description' => $movement->reason.' '.$asset->doc_num, 'source_type' => 'fixed_asset_'.$movement->movement_type,
            'source_id' => $movement->getKey(), 'source_doc_num' => $movement->doc_num];
    }

    private function line(FixedAsset $asset, int $accountId, string $debit, string $credit, array $dimensions = []): array
    {
        return ['account_id' => $accountId, 'debit_amount' => $debit, 'credit_amount' => $credit, 'branch_id' => $asset->branch_id, 'cost_center_id' => $asset->cost_center_id, 'description' => $asset->doc_num, ...$dimensions];
    }

    private function log(FixedAsset $asset, FixedAssetMovement $movement, string $event): void
    {
        app(ActivityLogger::class)->log(request(), 'fixed_assets', $event, 'success', ['subject' => $asset, 'properties_only' => true,
            'properties' => ['document' => $movement->doc_num, 'movement_type' => $movement->movement_type, 'journal_entry_id' => $movement->journal_entry_id]]);
    }
}
