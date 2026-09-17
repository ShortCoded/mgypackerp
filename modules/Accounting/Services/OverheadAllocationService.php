<?php

namespace Modules\Accounting\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;

final class OverheadAllocationService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly FinancialPeriodService $financialPeriods,
        private readonly JournalEntryService $journals,
        private readonly PostingAccountResolver $accounts,
        private readonly ProductionCostService $productionCosts,
    ) {}

    /** @param array<string, mixed> $data */
    public function createRule(array $data, int $companyId, int $branchId): OverheadAllocationRule
    {
        return DB::transaction(function () use ($data, $companyId, $branchId): OverheadAllocationRule {
            $sourceCostCenter = CostCenter::query()->forCompany($companyId)->active()
                ->whereKey((int) $data['source_cost_center_id'])->firstOrFail();
            $sourceAccountIds = $this->validatedAccountIds($companyId, $data['source_account_ids'] ?? []);
            $linkedSourceAccountCount = $sourceCostCenter->accounts()->whereIn('accounts.id', $sourceAccountIds)->count();
            if ($linkedSourceAccountCount !== count($sourceAccountIds)) {
                throw new DomainException(__('overhead_allocations.messages.source_accounts_not_linked'));
            }
            $targetCostCenterIds = $this->validatedCostCenterIds($companyId, $data['target_cost_center_ids'] ?? []);
            $basis = (string) $data['basis'];
            $fallbackBasis = filled($data['fallback_basis'] ?? null) ? (string) $data['fallback_basis'] : null;
            $behavior = (string) $data['cost_behavior'];
            $normalCapacity = filled($data['normal_capacity_hours'] ?? null)
                ? $this->decimal($data['normal_capacity_hours'], 8)
                : null;

            if ($behavior === OverheadAllocationRule::BehaviorFixed
                && ($normalCapacity === null || bccomp($normalCapacity, '0', 8) <= 0)) {
                throw new DomainException(__('overhead_allocations.messages.normal_capacity_required'));
            }

            if ($behavior === OverheadAllocationRule::BehaviorFixed
                && ! in_array($basis, [OverheadAllocationRule::BasisMachineHours, OverheadAllocationRule::BasisLaborHours], true)) {
                throw new DomainException(__('overhead_allocations.messages.fixed_cost_requires_hours'));
            }

            return OverheadAllocationRule::query()->create([
                ...$this->documents->nextForCompany('cost_overhead_allocation_rules', OverheadAllocationRule::class, $companyId),
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'name' => trim((string) $data['name']),
                'name_en' => filled($data['name_en'] ?? null) ? trim((string) $data['name_en']) : null,
                'source_cost_center_id' => $sourceCostCenter->getKey(),
                'source_account_ids' => $sourceAccountIds,
                'target_cost_center_ids' => $targetCostCenterIds === [] ? null : $targetCostCenterIds,
                'basis' => $basis,
                'fallback_basis' => $fallbackBasis,
                'cost_behavior' => $behavior,
                'normal_capacity_hours' => $normalCapacity,
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ])->refresh();
        });
    }

    public function preview(
        OverheadAllocationRule $rule,
        FinancialPeriod $period,
        int $branchId,
        string $fromDate,
        string $toDate,
    ): OverheadAllocationRun {
        return DB::transaction(function () use ($rule, $period, $branchId, $fromDate, $toDate): OverheadAllocationRun {
            $lockedRule = OverheadAllocationRule::query()->lockForUpdate()->findOrFail($rule->getKey());
            $this->assertRuleContext($lockedRule, $period, $branchId, $fromDate, $toDate);
            $snapshot = $this->snapshot($lockedRule, $period, $branchId, $fromDate, $toDate);

            $existing = OverheadAllocationRun::query()
                ->forContext((int) $lockedRule->company_id, (int) $period->getKey(), $branchId)
                ->where('rule_id', $lockedRule->getKey())
                ->where('input_fingerprint', $snapshot['fingerprint'])
                ->whereIn('status', [OverheadAllocationRun::StatusDraft, OverheadAllocationRun::StatusPosted])
                ->latest('id')
                ->first();

            if ($existing instanceof OverheadAllocationRun) {
                return $existing->load(['rule.sourceCostCenter', 'sources.journalEntryLine.journalEntry', 'lines.productionRun.product', 'lines.productionRun.order']);
            }

            $revision = OverheadAllocationRun::query()
                ->forContext((int) $lockedRule->company_id, (int) $period->getKey(), $branchId)
                ->where('rule_id', $lockedRule->getKey())
                ->whereDate('from_date', $fromDate)
                ->whereDate('to_date', $toDate)
                ->where('status', OverheadAllocationRun::StatusReversed)
                ->count();
            $idempotencyKey = hash('sha256', implode('|', [
                $lockedRule->company_id,
                $period->getKey(),
                $branchId,
                $lockedRule->getKey(),
                $fromDate,
                $toDate,
                $snapshot['fingerprint'],
                $revision,
            ]));

            $run = OverheadAllocationRun::query()->create([
                ...$this->documents->nextForCompany('cost_overhead_allocation_runs', OverheadAllocationRun::class, (int) $lockedRule->company_id),
                'company_id' => $lockedRule->company_id,
                'financial_period_id' => $period->getKey(),
                'branch_id' => $branchId,
                'rule_id' => $lockedRule->getKey(),
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'status' => OverheadAllocationRun::StatusDraft,
                'basis_used' => $snapshot['basis_used'],
                'fallback_reason' => $snapshot['fallback_reason'],
                'eligible_cost' => $snapshot['eligible_cost'],
                'allocatable_cost' => $snapshot['allocatable_cost'],
                'allocated_cost' => $snapshot['allocated_cost'],
                'unallocated_cost' => $snapshot['unallocated_cost'],
                'actual_capacity' => $snapshot['actual_capacity'],
                'utilization_percent' => $snapshot['utilization_percent'],
                'input_fingerprint' => $snapshot['fingerprint'],
                'idempotency_key' => $idempotencyKey,
                'policy_snapshot' => $snapshot['policy'],
                'created_by' => auth()->id(),
            ]);

            foreach ($snapshot['sources'] as $source) {
                $run->sources()->create([
                    'journal_entry_line_id' => $source['journal_entry_line_id'],
                    'account_id' => $source['account_id'],
                    'source_amount' => $source['source_amount'],
                ]);
            }

            foreach ($snapshot['targets'] as $target) {
                $run->lines()->create($target);
            }

            return $run->refresh()->load(['rule.sourceCostCenter', 'sources.journalEntryLine.journalEntry', 'lines.productionRun.product', 'lines.productionRun.order']);
        });
    }

    public function approve(OverheadAllocationRun $run): OverheadAllocationRun
    {
        return DB::transaction(function () use ($run): OverheadAllocationRun {
            $locked = OverheadAllocationRun::query()
                ->with(['rule', 'sources', 'lines.productionRun'])
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            if ($locked->status === OverheadAllocationRun::StatusPosted) {
                return $locked;
            }

            if ($locked->status !== OverheadAllocationRun::StatusDraft) {
                throw new DomainException(__('overhead_allocations.messages.draft_only'));
            }

            $period = FinancialPeriod::query()->forCompany((int) $locked->company_id)->findOrFail($locked->financial_period_id);
            $snapshot = $this->snapshot(
                $locked->rule,
                $period,
                (int) $locked->branch_id,
                $locked->from_date->toDateString(),
                $locked->to_date->toDateString(),
            );

            if (! hash_equals((string) $locked->input_fingerprint, $snapshot['fingerprint'])) {
                throw new DomainException(__('overhead_allocations.messages.stale_preview'));
            }

            $this->financialPeriods->resolveOpenForPostingDate(
                (int) $locked->company_id,
                $locked->to_date,
                expectedPeriodId: (int) $locked->financial_period_id,
                lockForUpdate: true,
            );
            $this->assertTargetsRemainUnreceived($locked->lines->pluck('production_run_id')->map(fn (mixed $id): int => (int) $id));

            $journal = $this->journals->createPostedFromSource([
                'entry_date' => $locked->to_date,
                'company_id' => (int) $locked->company_id,
                'financial_period_id' => (int) $locked->financial_period_id,
                'branch_id' => (int) $locked->branch_id,
                'currency_id' => Currency::query()->forCompany((int) $locked->company_id)->active()->where('is_main', true)->firstOrFail()->getKey(),
                'exchange_rate' => 1,
                'description' => __('overhead_allocations.journal.description', ['document' => $locked->doc_num]),
                'source_type' => 'overhead_allocation',
                'source_id' => $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
            ], $this->journalLines($locked));

            $locked->forceFill([
                'status' => OverheadAllocationRun::StatusPosted,
                'journal_entry_id' => $journal->getKey(),
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ])->save();

            return $locked->refresh()->load(['rule.sourceCostCenter', 'journalEntry.lines', 'lines.productionRun.product', 'lines.productionRun.order']);
        });
    }

    public function reverse(OverheadAllocationRun $run, string $reason): OverheadAllocationRun
    {
        return DB::transaction(function () use ($run, $reason): OverheadAllocationRun {
            $locked = OverheadAllocationRun::query()
                ->with(['journalEntry', 'lines.productionRun'])
                ->lockForUpdate()
                ->findOrFail($run->getKey());

            if ($locked->status === OverheadAllocationRun::StatusReversed) {
                return $locked;
            }

            if ($locked->status !== OverheadAllocationRun::StatusPosted || $locked->journalEntry === null) {
                throw new DomainException(__('overhead_allocations.messages.posted_only'));
            }

            $this->assertTargetsRemainUnreceived($locked->lines->pluck('production_run_id')->map(fn (mixed $id): int => (int) $id));
            $reversal = $this->journals->createPostedReversalFromSource($locked->journalEntry, [
                'entry_date' => $locked->to_date,
                'company_id' => (int) $locked->company_id,
                'financial_period_id' => (int) $locked->financial_period_id,
                'branch_id' => (int) $locked->branch_id,
                'currency_id' => $locked->journalEntry->currency_id,
                'exchange_rate' => 1,
                'description' => __('overhead_allocations.journal.reversal', ['document' => $locked->doc_num]),
                'notes' => $reason,
                'source_type' => 'overhead_allocation_reversal',
                'source_id' => $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
            ]);

            $locked->forceFill([
                'status' => OverheadAllocationRun::StatusReversed,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => trim($reason),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * @return array{
     *   sources: list<array{journal_entry_line_id: int, account_id: int, source_amount: string}>,
     *   targets: list<array<string, mixed>>, eligible_cost: string, allocatable_cost: string,
     *   allocated_cost: string, unallocated_cost: string, actual_capacity: string|null,
     *   utilization_percent: string|null, basis_used: string, fallback_reason: string|null,
     *   policy: array<string, mixed>, fingerprint: string
     * }
     */
    private function snapshot(
        OverheadAllocationRule $rule,
        FinancialPeriod $period,
        int $branchId,
        string $fromDate,
        string $toDate,
    ): array {
        $sources = $this->sourceLines($rule, $period, $branchId, $fromDate, $toDate);
        $eligibleCost = $this->sum(collect($sources), 'source_amount', 4);

        if (bccomp($eligibleCost, '0', 4) <= 0) {
            throw new DomainException(__('overhead_allocations.messages.no_eligible_cost'));
        }

        $targetMetrics = $this->targetMetrics($rule, $period, $branchId, $fromDate, $toDate);
        if ($targetMetrics->isEmpty()) {
            throw new DomainException(__('overhead_allocations.messages.no_eligible_runs'));
        }
        if ($targetMetrics->contains(fn (array $target): bool => $target['cost_center_id'] === null)) {
            throw new DomainException(__('overhead_allocations.messages.target_cost_center_required'));
        }

        [$basisUsed, $fallbackReason] = $this->basisFor($rule, $targetMetrics);
        $basisTotal = $this->sum($targetMetrics, $basisUsed, 8);
        if (bccomp($basisTotal, '0', 8) <= 0) {
            throw new DomainException(__('overhead_allocations.messages.zero_basis'));
        }

        $actualCapacity = in_array($rule->basis, [OverheadAllocationRule::BasisMachineHours, OverheadAllocationRule::BasisLaborHours], true)
            ? $this->sum($targetMetrics, (string) $rule->basis, 8)
            : null;
        $allocatableCost = $eligibleCost;
        $utilizationPercent = null;

        if ($rule->cost_behavior === OverheadAllocationRule::BehaviorFixed) {
            if ($targetMetrics->contains(fn (array $target): bool => $target[$rule->basis] === null)) {
                throw new DomainException(__('overhead_allocations.messages.fixed_capacity_incomplete'));
            }

            $normalCapacity = (string) $rule->normal_capacity_hours;
            $capacityRatio = bcdiv((string) $actualCapacity, $normalCapacity, 12);
            $allocatableCost = bccomp($capacityRatio, '1', 12) >= 0
                ? $eligibleCost
                : $this->round(bcmul($eligibleCost, $capacityRatio, 12), 4);
            $utilizationPercent = $this->round(bcmul($capacityRatio, '100', 8), 4);
        }

        $allocations = $this->allocate($allocatableCost, $targetMetrics, $basisUsed, $basisTotal);
        $targets = $targetMetrics->values()->map(function (array $target, int $index) use ($allocations, $basisUsed, $basisTotal): array {
            $basisValue = (string) $target[$basisUsed];

            return [
                'production_run_id' => $target['production_run_id'],
                'cost_center_id' => $target['cost_center_id'],
                'machine_hours' => $target['machine_hours'],
                'labor_hours' => $target['labor_hours'],
                'direct_material_cost' => $target['direct_material_cost'],
                'basis_value' => $basisValue,
                'allocation_percent' => $this->round(bcmul(bcdiv($basisValue, $basisTotal, 12), '100', 12), 8),
                'allocated_amount' => $allocations[$index],
            ];
        })->all();
        $allocatedCost = $this->sum(collect($targets), 'allocated_amount', 4);
        $unallocatedCost = bcsub($eligibleCost, $allocatedCost, 4);
        $policy = [
            'rule_id' => (int) $rule->getKey(),
            'rule_updated_at' => $rule->updated_at?->toISOString(),
            'source_cost_center_id' => (int) $rule->source_cost_center_id,
            'source_account_ids' => array_values(array_map('intval', $rule->source_account_ids ?? [])),
            'target_cost_center_ids' => array_values(array_map('intval', $rule->target_cost_center_ids ?? [])),
            'basis' => $rule->basis,
            'fallback_basis' => $rule->fallback_basis,
            'basis_used' => $basisUsed,
            'cost_behavior' => $rule->cost_behavior,
            'normal_capacity_hours' => $rule->normal_capacity_hours,
            'rounding_scale' => 4,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ];
        $fingerprintPayload = [
            'policy' => $policy,
            'sources' => $sources,
            'targets' => collect($targets)->map(fn (array $target): array => collect($target)->except('allocated_amount')->all())->all(),
            'eligible_cost' => $eligibleCost,
            'allocatable_cost' => $allocatableCost,
        ];

        return [
            'sources' => $sources,
            'targets' => $targets,
            'eligible_cost' => $eligibleCost,
            'allocatable_cost' => $allocatableCost,
            'allocated_cost' => $allocatedCost,
            'unallocated_cost' => $unallocatedCost,
            'actual_capacity' => $actualCapacity,
            'utilization_percent' => $utilizationPercent,
            'basis_used' => $basisUsed,
            'fallback_reason' => $fallbackReason,
            'policy' => $policy,
            'fingerprint' => hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    /** @return list<array{journal_entry_line_id: int, account_id: int, source_amount: string}> */
    private function sourceLines(OverheadAllocationRule $rule, FinancialPeriod $period, int $branchId, string $fromDate, string $toDate): array
    {
        return DB::table('journal_entry_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->whereNull('entry.deleted_at')
            ->where('entry.company_id', $rule->company_id)
            ->where('entry.financial_period_id', $period->getKey())
            ->where('entry.status', 'posted')
            ->where('entry.is_posted', true)
            ->whereBetween('entry.entry_date', [$fromDate, $toDate])
            ->where('line.cost_center_id', $rule->source_cost_center_id)
            ->whereIn('line.account_id', array_map('intval', $rule->source_account_ids ?? []))
            ->where(function (Builder $query) use ($branchId): void {
                $query->where('line.branch_id', $branchId)
                    ->orWhere(fn (Builder $entryBranch) => $entryBranch->whereNull('line.branch_id')->where('entry.branch_id', $branchId));
            })
            ->where(function (Builder $query): void {
                $query->whereNull('entry.source_type')
                    ->orWhereNotIn('entry.source_type', ['overhead_allocation', 'overhead_allocation_reversal', 'period_closing', 'period_closing_reversal']);
            })
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('cost_overhead_allocation_sources as used_source')
                    ->join('cost_overhead_allocation_runs as used_run', 'used_run.id', '=', 'used_source.allocation_run_id')
                    ->whereColumn('used_source.journal_entry_line_id', 'line.id')
                    ->where('used_run.status', OverheadAllocationRun::StatusPosted);
            })
            ->orderBy('line.id')
            ->get([
                'line.id as journal_entry_line_id',
                'line.account_id',
                DB::raw('(line.debit_amount - line.credit_amount) * entry.exchange_rate as source_amount'),
            ])
            ->map(fn (object $source): array => [
                'journal_entry_line_id' => (int) $source->journal_entry_line_id,
                'account_id' => (int) $source->account_id,
                'source_amount' => $this->decimal($source->source_amount, 4),
            ])
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function targetMetrics(OverheadAllocationRule $rule, FinancialPeriod $period, int $branchId, string $fromDate, string $toDate): Collection
    {
        $runs = ProductionRun::query()
            ->with(['product', 'order'])
            ->where('company_id', $rule->company_id)
            ->where('financial_period_id', $period->getKey())
            ->where('branch_id', $branchId)
            ->where('status', ProductionRun::StatusCompleted)
            ->where('good_base_quantity', '>', 0)
            ->where('received_base_quantity', '<=', 0)
            ->whereDate('actual_end_at', '>=', $fromDate)
            ->whereDate('actual_end_at', '<=', $toDate)
            ->when($rule->target_cost_center_ids !== null, fn ($query) => $query->whereIn('cost_center_id', array_map('intval', $rule->target_cost_center_ids)))
            ->orderBy('id')
            ->get();

        return $runs->map(function (ProductionRun $run): array {
            $machineHours = $run->actual_start_at !== null && $run->actual_end_at !== null
                ? $this->round((string) max(0, $run->actual_start_at->diffInSeconds($run->actual_end_at, false) / 3600), 8)
                : null;
            $laborDetails = collect($run->labor_details ?? []);
            $laborHours = $laborDetails->isNotEmpty() && $laborDetails->every(fn (mixed $line): bool => is_array($line) && array_key_exists('actual_hours', $line))
                ? $this->sum($laborDetails, 'actual_hours', 8)
                : null;

            return [
                'production_run_id' => (int) $run->getKey(),
                'cost_center_id' => $run->cost_center_id === null ? null : (int) $run->cost_center_id,
                'machine_hours' => $machineHours,
                'labor_hours' => $laborHours,
                'direct_material_cost' => $this->round($this->productionCosts->directMaterialCost($run), 4),
            ];
        });
    }

    /** @return array{string, string|null} */
    private function basisFor(OverheadAllocationRule $rule, Collection $targets): array
    {
        $basis = (string) $rule->basis;
        $hasMissing = $targets->contains(fn (array $target): bool => $target[$basis] === null);
        $total = $hasMissing ? '0' : $this->sum($targets, $basis, 8);

        if (! $hasMissing && bccomp($total, '0', 8) > 0) {
            return [$basis, null];
        }

        if ($rule->cost_behavior === OverheadAllocationRule::BehaviorFixed) {
            throw new DomainException(__('overhead_allocations.messages.fixed_capacity_incomplete'));
        }

        if ($rule->fallback_basis !== OverheadAllocationRule::BasisDirectMaterialCost) {
            throw new DomainException($hasMissing
                ? __('overhead_allocations.messages.incomplete_basis')
                : __('overhead_allocations.messages.zero_basis'));
        }

        return [
            OverheadAllocationRule::BasisDirectMaterialCost,
            $hasMissing
                ? __('overhead_allocations.fallback.missing_hours')
                : __('overhead_allocations.fallback.zero_hours'),
        ];
    }

    /** @return list<string> */
    private function allocate(string $amount, Collection $targets, string $basis, string $basisTotal): array
    {
        $remaining = $amount;
        $lastIndex = $targets->count() - 1;

        return $targets->values()->map(function (array $target, int $index) use (&$remaining, $amount, $basis, $basisTotal, $lastIndex): string {
            if ($index === $lastIndex) {
                return $remaining;
            }

            $share = $this->round(bcmul($amount, bcdiv((string) $target[$basis], $basisTotal, 12), 12), 4);
            $remaining = bcsub($remaining, $share, 4);

            return $share;
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function journalLines(OverheadAllocationRun $run): array
    {
        $wip = $this->accounts->resolve((int) $run->company_id, PostingAccountResolver::WorkInProcessInventory, __('overhead_allocations.title'));
        $lines = $run->lines->filter(fn ($line): bool => bccomp((string) $line->allocated_amount, '0', 4) > 0)
            ->map(fn ($line): array => [
                'account_id' => $wip->getKey(),
                'debit_amount' => $line->allocated_amount,
                'credit_amount' => '0.0000',
                'description' => __('overhead_allocations.journal.target', ['run' => $line->productionRun->run_number]),
                'cost_center_id' => $line->cost_center_id,
                'branch_id' => $run->branch_id,
            ])->values()->all();

        $sourceByAccount = $run->sources->groupBy('account_id')->map(
            fn (Collection $sources): string => $sources->reduce(
                fn (string $sum, $source): string => bcadd($sum, (string) $source->source_amount, 4),
                '0.0000',
            ),
        )->filter(fn (string $amount): bool => bccomp($amount, '0', 4) !== 0);
        $ratio = bcdiv((string) $run->allocated_cost, (string) $run->eligible_cost, 12);
        $remainingNetCredit = (string) $run->allocated_cost;
        $lastAccountId = $sourceByAccount->keys()->last();

        foreach ($sourceByAccount as $accountId => $sourceAmount) {
            $netCredit = (int) $accountId === (int) $lastAccountId
                ? $remainingNetCredit
                : $this->round(bcmul($sourceAmount, $ratio, 12), 4);
            $remainingNetCredit = bcsub($remainingNetCredit, $netCredit, 4);
            $lines[] = [
                'account_id' => (int) $accountId,
                'debit_amount' => bccomp($netCredit, '0', 4) < 0 ? bcmul($netCredit, '-1', 4) : '0.0000',
                'credit_amount' => bccomp($netCredit, '0', 4) > 0 ? $netCredit : '0.0000',
                'description' => __('overhead_allocations.journal.source', ['document' => $run->doc_num]),
                'cost_center_id' => $run->rule->source_cost_center_id,
                'branch_id' => $run->branch_id,
            ];
        }

        return $lines;
    }

    private function assertRuleContext(OverheadAllocationRule $rule, FinancialPeriod $period, int $branchId, string $fromDate, string $toDate): void
    {
        if ((int) $rule->company_id !== (int) $period->company_id
            || ($rule->branch_id !== null && (int) $rule->branch_id !== $branchId)
            || $rule->status !== 'active') {
            throw new DomainException(__('overhead_allocations.messages.rule_outside_context'));
        }

        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();
        if ($from->greaterThan($to)
            || $from->lt($period->from_date)
            || $to->gt($period->to_date)
            || $from->lt($rule->effective_from)
            || ($rule->effective_to !== null && $to->gt($rule->effective_to))) {
            throw new DomainException(__('overhead_allocations.messages.date_outside_scope'));
        }

        $this->financialPeriods->resolveOpenForPostingDate(
            (int) $rule->company_id,
            $to,
            expectedPeriodId: (int) $period->getKey(),
            lockForUpdate: true,
        );
    }

    /** @param Collection<int, int> $runIds */
    private function assertTargetsRemainUnreceived(Collection $runIds): void
    {
        $received = ProductionRun::query()
            ->whereIn('id', $runIds->all())
            ->where(function ($query): void {
                $query->where('received_base_quantity', '>', 0)
                    ->orWhereHas('inventoryDocuments', fn ($documents) => $documents
                        ->where('document_type', InventoryDocument::TypeProductionReceipt)
                        ->where('status', InventoryDocument::StatusPosted));
            })->exists();

        if ($received) {
            throw new DomainException(__('overhead_allocations.messages.received_run_locked'));
        }
    }

    /** @return list<int> */
    private function validatedAccountIds(int $companyId, mixed $ids): array
    {
        $ids = collect(is_array($ids) ? $ids : [])->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        $found = Account::query()->forCompany($companyId)->eligibleForDirectPosting()->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): int => (int) $id);

        if ($ids->isEmpty() || $found->count() !== $ids->count()) {
            throw new DomainException(__('overhead_allocations.messages.invalid_source_accounts'));
        }

        return $found->sort()->values()->all();
    }

    /** @return list<int> */
    private function validatedCostCenterIds(int $companyId, mixed $ids): array
    {
        $ids = collect(is_array($ids) ? $ids : [])->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $found = CostCenter::query()->forCompany($companyId)->active()->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): int => (int) $id);
        if ($found->count() !== $ids->count()) {
            throw new DomainException(__('overhead_allocations.messages.invalid_target_centers'));
        }

        return $found->sort()->values()->all();
    }

    /** @param Collection<int, mixed> $rows */
    private function sum(Collection $rows, string $key, int $scale): string
    {
        return $rows->reduce(
            fn (string $sum, mixed $row): string => bcadd($sum, (string) data_get($row, $key, 0), $scale),
            $this->decimal(0, $scale),
        );
    }

    private function decimal(mixed $value, int $scale): string
    {
        return number_format((float) $value, $scale, '.', '');
    }

    private function round(string $value, int $scale): string
    {
        return number_format(round((float) $value, $scale), $scale, '.', '');
    }
}
