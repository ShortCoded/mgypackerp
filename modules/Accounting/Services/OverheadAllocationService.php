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
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Models\OverheadAllocationRule;
use Modules\Accounting\Models\OverheadAllocationRun;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionStageTransferService;

final class OverheadAllocationService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly FinancialPeriodService $financialPeriods,
        private readonly JournalEntryService $journals,
        private readonly PostingAccountResolver $accounts,
        private readonly ProductionCostService $productionCosts,
        private readonly OperatingScopeAccessService $scope,
    ) {}

    /** @param array<string, mixed> $data */
    public function createRule(array $data, int $companyId, int $branchId): OverheadAllocationRule
    {
        return DB::transaction(function () use ($data, $companyId, $branchId): OverheadAllocationRule {
            $sourceCostCenter = CostCenter::query()->forCompany($companyId)->active()
                ->where('is_group', false)
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

            if ($basis === OverheadAllocationRule::BasisDirectPayrollHours
                && ($behavior !== OverheadAllocationRule::BehaviorVariable || $fallbackBasis !== null)) {
                throw new DomainException(__('overhead_allocations.messages.direct_payroll_policy'));
            }
            if ($basis === OverheadAllocationRule::BasisDirectPayrollHours && Account::query()->whereIn('id', $sourceAccountIds)
                ->whereHas('classification', fn ($query) => $query->where('code', 'direct_labor_cost'))->count() !== count($sourceAccountIds)) {
                throw new DomainException(__('overhead_allocations.messages.direct_payroll_accounts'));
            }

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
            Company::query()->whereKey($rule->company_id)->lockForUpdate()->firstOrFail();
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

            OverheadAllocationRun::query()
                ->forContext((int) $lockedRule->company_id, (int) $period->getKey(), $branchId)
                ->where('rule_id', $lockedRule->getKey())
                ->whereDate('from_date', $fromDate)
                ->whereDate('to_date', $toDate)
                ->where('status', OverheadAllocationRun::StatusDraft)
                ->update(['status' => OverheadAllocationRun::StatusSuperseded]);

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

    public function assertTargetAccess(OverheadAllocationRun $run): void
    {
        if ($run->basis_used !== OverheadAllocationRule::BasisDirectPayrollHours) {
            return;
        }
        $allowed = $this->scope->allowedFinancialPeriodQuery(auth()->user(), [Company::findOrFail($run->company_id)->doc_num])
            ->pluck('financial_periods.id');
        $run->loadMissing('lines.productionRun');
        abort_unless($run->lines->every(fn ($line): bool => $line->productionRun !== null
            && $allowed->contains($line->productionRun->financial_period_id)), 403);
    }

    public function approve(OverheadAllocationRun $run): OverheadAllocationRun
    {
        return DB::transaction(function () use ($run): OverheadAllocationRun {
            Company::query()->whereKey($run->company_id)->lockForUpdate()->firstOrFail();
            $locked = OverheadAllocationRun::query()
                ->with(['rule', 'sources', 'lines.productionRun'])
                ->lockForUpdate()
                ->findOrFail($run->getKey());
            $this->assertTargetAccess($locked);

            if ($locked->status === OverheadAllocationRun::StatusPosted) {
                return $locked;
            }

            if ($locked->status !== OverheadAllocationRun::StatusDraft) {
                throw new DomainException(__('overhead_allocations.messages.draft_only'));
            }
            JournalEntry::query()->whereIn('id', DB::table('cost_overhead_allocation_sources as source')
                ->join('journal_entry_lines as line', 'line.id', '=', 'source.journal_entry_line_id')
                ->where('source.allocation_run_id', $locked->id)->select('line.journal_entry_id'))
                ->orderBy('id')->lockForUpdate()->get(['id']);

            $targetIds = $locked->lines->pluck('production_run_id')->map(fn (mixed $id): int => (int) $id);
            $this->assertTargetsRemainUnreceived($targetIds, lockForUpdate: true);
            $period = FinancialPeriod::query()->forCompany((int) $locked->company_id)->findOrFail($locked->financial_period_id);
            $this->assertRuleContext(
                $locked->rule,
                $period,
                (int) $locked->branch_id,
                $locked->from_date->toDateString(),
                $locked->to_date->toDateString(),
            );
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

            $journal = bccomp((string) $locked->allocated_cost, '0', 4) > 0
                ? $this->journals->createPostedFromSource([
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
                ], $this->journalLines($locked))
                : null;

            $locked->forceFill([
                'status' => OverheadAllocationRun::StatusPosted,
                'journal_entry_id' => $journal?->getKey(),
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ])->save();

            return $locked->refresh()->load(['rule.sourceCostCenter', 'journalEntry.lines', 'lines.productionRun.product', 'lines.productionRun.order']);
        }, 3);
    }

    public function reverse(OverheadAllocationRun $run, string $reason): OverheadAllocationRun
    {
        return DB::transaction(function () use ($run, $reason): OverheadAllocationRun {
            Company::query()->whereKey($run->company_id)->lockForUpdate()->firstOrFail();
            $locked = OverheadAllocationRun::query()
                ->with(['journalEntry', 'lines.productionRun'])
                ->lockForUpdate()
                ->findOrFail($run->getKey());
            $this->assertTargetAccess($locked);

            if ($locked->status === OverheadAllocationRun::StatusReversed) {
                return $locked;
            }

            if ($locked->status !== OverheadAllocationRun::StatusPosted) {
                throw new DomainException(__('overhead_allocations.messages.posted_only'));
            }

            $this->assertTargetsRemainUnreceived(
                $locked->lines->pluck('production_run_id')->map(fn (mixed $id): int => (int) $id),
                lockForUpdate: true,
            );
            $reversal = $locked->journalEntry
                ? $this->journals->createPostedReversalFromSource($locked->journalEntry, [
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
                ])
                : null;

            $locked->forceFill([
                'status' => OverheadAllocationRun::StatusReversed,
                'reversal_journal_entry_id' => $reversal?->getKey(),
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
                'reversal_reason' => trim($reason),
            ])->save();

            return $locked->refresh();
        }, 3);
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

        $targetMetrics = $this->targetMetrics($rule, $period, $branchId, $fromDate, $toDate, $sources);
        if ($targetMetrics->isEmpty()) {
            throw new DomainException(__('overhead_allocations.messages.no_eligible_runs'));
        }
        if ($targetMetrics->contains(fn (array $target): bool => $target['cost_center_id'] === null)) {
            throw new DomainException(__('overhead_allocations.messages.target_cost_center_required'));
        }

        [$basisUsed, $fallbackReason] = $this->basisFor($rule, $targetMetrics);
        $basisTotal = $this->sum($targetMetrics, $basisUsed, 8);
        if (bccomp($basisTotal, '0', 8) <= 0 && $rule->cost_behavior !== OverheadAllocationRule::BehaviorFixed) {
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

        $directLabor = $basisUsed === OverheadAllocationRule::BasisDirectPayrollHours
            ? $this->allocateDirectPayroll($sources, $targetMetrics)
            : null;
        $allocations = $directLabor !== null ? $directLabor['amounts'] : (bccomp($allocatableCost, '0', 4) === 0
            ? array_fill(0, $targetMetrics->count(), '0.0000')
            : $this->allocate($allocatableCost, $targetMetrics, $basisUsed, $basisTotal));
        $targets = $targetMetrics->values()->map(function (array $target, int $index) use ($allocations, $basisUsed, $basisTotal, $directLabor, $eligibleCost): array {
            $basisValue = (string) $target[$basisUsed];

            return [
                'production_run_id' => $target['production_run_id'],
                'cost_center_id' => $target['cost_center_id'],
                'machine_hours' => $target['machine_hours'],
                'labor_hours' => $target['labor_hours'],
                'direct_material_cost' => $target['direct_material_cost'],
                'basis_value' => $basisValue,
                'allocation_percent' => $directLabor !== null
                    ? $this->round(bcmul(bcdiv($allocations[$index], $eligibleCost, 12), '100', 12), 8)
                    : (bccomp($basisTotal, '0', 8) === 0
                    ? '0.00000000'
                    : $this->round(bcmul(bcdiv($basisValue, $basisTotal, 12), '100', 12), 8)),
                'allocated_amount' => $allocations[$index],
            ];
        })->all();
        $allocatedCost = $this->sum(collect($targets), 'allocated_amount', 4);
        $unallocatedCost = bcsub($eligibleCost, $allocatedCost, 4);
        $unusedCapacityCost = $rule->cost_behavior === OverheadAllocationRule::BehaviorFixed
            ? $unallocatedCost
            : '0.0000';
        $unusedCapacityReason = bccomp($unusedCapacityCost, '0', 4) > 0
            ? 'below_normal_capacity'
            : null;
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
            'unused_capacity_cost' => $unusedCapacityCost,
            'unused_capacity_reason' => $unusedCapacityReason,
            'rounding_scale' => 4,
            'direct_payroll_allocations' => $directLabor['sources'] ?? [],
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
            ->leftJoin('hr_payroll_runs as payroll', fn ($join) => $join->on('payroll.id', '=', 'entry.source_id')
                ->where('entry.source_type', 'hr_payroll_run'))
            ->leftJoin('hr_payroll_periods as payroll_period', 'payroll_period.id', '=', 'payroll.payroll_period_id')
            ->whereNull('entry.deleted_at')
            ->where('entry.company_id', $rule->company_id)
            ->where('entry.financial_period_id', $period->getKey())
            ->where('entry.status', 'posted')
            ->where('entry.is_posted', true)
            ->whereNull('entry.reversed_entry_id')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('journal_entries as original')
                ->whereColumn('original.reversed_entry_id', 'entry.id'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('production_expense_requests as direct_expense')
                ->whereColumn('direct_expense.journal_entry_id', 'entry.id')->whereNotNull('direct_expense.production_run_id'))
            ->whereDate('entry.entry_date', '>=', $fromDate)->whereDate('entry.entry_date', '<=', $toDate)
            ->where('line.cost_center_id', $rule->source_cost_center_id)
            ->whereIn('line.account_id', array_map('intval', $rule->source_account_ids ?? []))
            ->when($rule->basis === OverheadAllocationRule::BasisDirectPayrollHours, fn (Builder $query) => $query
                ->where('entry.source_type', 'hr_payroll_run')->whereColumn('payroll_period.company_id', 'entry.company_id')
                ->whereExists(fn (Builder $posted) => $posted->selectRaw('1')->from('hr_payroll_postings as payroll_posting')
                    ->whereColumn('payroll_posting.payroll_run_id', 'payroll.id')->whereColumn('payroll_posting.journal_entry_id', 'entry.id')
                    ->where('payroll_posting.status', 'posted'))
                ->where('line.debit_amount', '>', 0)->where('line.credit_amount', 0))
            ->where(function (Builder $query) use ($branchId): void {
                $query->where('line.branch_id', $branchId)
                    ->orWhere(fn (Builder $entryBranch) => $entryBranch->whereNull('line.branch_id')->where('entry.branch_id', $branchId));
            })
            ->where(function (Builder $query): void {
                $query->whereNull('entry.source_type')
                    ->orWhere(function (Builder $source): void {
                        $source->where('entry.source_type', 'not like', 'overhead_allocation%')
                            ->where('entry.source_type', 'not like', 'period_closing%');
                    });
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
                'line.employee_id', 'payroll_period.period_start', 'payroll_period.period_end',
                DB::raw('(line.debit_amount - line.credit_amount) * entry.exchange_rate as source_amount'),
            ])
            ->map(fn (object $source): array => [
                'journal_entry_line_id' => (int) $source->journal_entry_line_id,
                'account_id' => (int) $source->account_id,
                'source_amount' => $this->decimal($source->source_amount, 4),
                'employee_id' => $source->employee_id === null ? null : (int) $source->employee_id,
                'period_start' => $source->period_start,
                'period_end' => $source->period_end,
            ])
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function targetMetrics(OverheadAllocationRule $rule, FinancialPeriod $period, int $branchId, string $fromDate, string $toDate, array $sources): Collection
    {
        $isDirectPayroll = $rule->basis === OverheadAllocationRule::BasisDirectPayrollHours;
        $runs = ProductionRun::query()
            ->with(['product', 'order', 'progressEntries'])
            ->where('company_id', $rule->company_id)
            ->when(! $isDirectPayroll, fn ($query) => $query->where('financial_period_id', $period->getKey()))
            ->where('branch_id', $branchId)
            ->where('status', ProductionRun::StatusRunning)
            ->where('good_base_quantity', '>', 0)
            ->where('received_base_quantity', '<=', 0)
            ->when($rule->target_cost_center_ids !== null, fn ($query) => $query->whereIn('cost_center_id', array_map('intval', $rule->target_cost_center_ids)))
            ->orderBy('id')
            ->get();
        $from = Carbon::parse($isDirectPayroll ? collect($sources)->min('period_start') : $fromDate)->startOfDay();
        $to = Carbon::parse($isDirectPayroll ? collect($sources)->max('period_end') : $toDate)->endOfDay();
        $costPositions = $this->productionCosts->positions($runs);

        $allowedPeriods = $isDirectPayroll
            ? $this->scope->allowedFinancialPeriodQuery(auth()->user(), [Company::findOrFail($rule->company_id)->doc_num])->pluck('financial_periods.id')
            : null;

        return $runs->map(function (ProductionRun $run) use ($costPositions, $from, $to, $allowedPeriods, $isDirectPayroll): ?array {
            $endAt = $run->actual_end_at ?? $run->progressEntries->last()?->recorded_at;
            if ($isDirectPayroll && $run->actual_end_at === null) {
                $lastWorkDate = collect($run->labor_details ?? [])->flatMap(fn (array $labor): array => $labor['work_segments'] ?? [])->max('work_date');
                if ($lastWorkDate !== null) {
                    $endAt = $endAt?->max(Carbon::parse($lastWorkDate)->endOfDay()) ?? Carbon::parse($lastWorkDate)->endOfDay();
                }
            }
            if ($endAt === null || $endAt->lt($from)
                || ($isDirectPayroll ? $run->actual_start_at?->gt($to) : $endAt->gt($to))) {
                return null;
            }

            if ($allowedPeriods !== null) {
                abort_unless($allowedPeriods->contains($run->financial_period_id), 403);
            }
            $machineHours = null;
            if ($run->actual_start_at !== null) {
                $seconds = (string) $run->actual_start_at->diffInSeconds($endAt, false);
                $machineHours = bccomp($seconds, '0', 8) > 0
                    ? $this->round(bcdiv($seconds, '3600', 12), 8)
                    : '0.00000000';
            }
            $laborDetails = collect($run->labor_details ?? []);
            $laborHours = $laborDetails->isNotEmpty() && $laborDetails->every(fn (mixed $line): bool => is_array($line) && array_key_exists('actual_hours', $line))
                ? $this->sum($laborDetails, 'actual_hours', 8)
                : null;
            $cost = $costPositions->get($run->getKey());

            return [
                'production_run_id' => (int) $run->getKey(),
                'cost_center_id' => $run->cost_center_id === null ? null : (int) $run->cost_center_id,
                'machine_hours' => $machineHours,
                'labor_hours' => $laborHours,
                'direct_payroll_hours' => $laborHours,
                'labor_details' => $laborDetails->all(),
                'actual_start_at' => $run->actual_start_at?->toDateString(),
                'actual_end_at' => $endAt->toDateString(),
                'direct_material_cost' => $this->round((string) $cost['direct_material_cost'], 4),
                'material_valuation_complete' => (bool) $cost['material_valuation_complete'],
            ];
        })->filter()->values();
    }

    /** @return array{string, string|null} */
    private function basisFor(OverheadAllocationRule $rule, Collection $targets): array
    {
        $basis = (string) $rule->basis;
        if ($basis === OverheadAllocationRule::BasisDirectPayrollHours) {
            if ($rule->cost_behavior !== OverheadAllocationRule::BehaviorVariable || $rule->fallback_basis !== null) {
                throw new DomainException(__('overhead_allocations.messages.direct_payroll_policy'));
            }

            return [$basis, null];
        }
        if ($basis === OverheadAllocationRule::BasisDirectMaterialCost
            && $targets->contains(fn (array $target): bool => ! $target['material_valuation_complete'])) {
            throw new DomainException(__('overhead_allocations.messages.incomplete_material_valuation'));
        }
        $hasMissing = $targets->contains(fn (array $target): bool => $target[$basis] === null);
        $total = $hasMissing ? '0' : $this->sum($targets, $basis, 8);

        if (! $hasMissing && bccomp($total, '0', 8) > 0) {
            return [$basis, null];
        }

        if ($rule->cost_behavior === OverheadAllocationRule::BehaviorFixed && ! $hasMissing) {
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

        if ($targets->contains(fn (array $target): bool => ! $target['material_valuation_complete'])) {
            throw new DomainException(__('overhead_allocations.messages.incomplete_material_valuation'));
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

    /** @param list<array<string, mixed>> $sources @return array{amounts: list<string>, sources: list<array<string, mixed>>} */
    private function allocateDirectPayroll(array $sources, Collection $targets): array
    {
        $amounts = array_fill(0, $targets->count(), '0.0000');
        $details = [];
        foreach ($sources as $source) {
            if ($source['employee_id'] === null || $source['period_start'] === null || $source['period_end'] === null) {
                throw new DomainException(__('overhead_allocations.messages.direct_payroll_employee'));
            }
            $matched = $targets->map(function (array $target, int $index) use ($source): ?array {
                if ($target['actual_start_at'] === null || $target['actual_start_at'] > $source['period_end']
                    || $target['actual_end_at'] < $source['period_start']) {
                    return null;
                }
                $hours = '0.00000000';
                $datedHours = [];
                foreach ($target['labor_details'] as $labor) {
                    if ((int) ($labor['employee_id'] ?? 0) !== $source['employee_id']) {
                        continue;
                    }
                    if (! is_numeric($labor['actual_hours'] ?? null) || bccomp((string) $labor['actual_hours'], '0', 8) <= 0) {
                        throw new DomainException(__('overhead_allocations.messages.direct_payroll_employee'));
                    }
                    $segments = $labor['work_segments'] ?? [];
                    if ($segments === []) {
                        if ($target['actual_start_at'] < $source['period_start'] || $target['actual_end_at'] > $source['period_end']) {
                            throw new DomainException(__('overhead_allocations.messages.direct_payroll_dated_hours', ['run' => $target['production_run_id']]));
                        }
                        $hours = bcadd($hours, (string) $labor['actual_hours'], 8);

                        continue;
                    }
                    $total = '0.00000000';
                    foreach ($segments as $segment) {
                        $segmentHours = (string) ($segment['actual_hours'] ?? '');
                        $workDate = (string) ($segment['work_date'] ?? '');
                        if (! is_numeric($segmentHours) || bccomp($segmentHours, '0', 8) <= 0) {
                            throw new DomainException(__('overhead_allocations.messages.direct_payroll_employee'));
                        }
                        $total = bcadd($total, $segmentHours, 8);
                        if ($workDate >= $source['period_start'] && $workDate <= $source['period_end']) {
                            $hours = bcadd($hours, $segmentHours, 8);
                            $datedHours[] = $segment;
                        }
                    }
                    if (bccomp($total, (string) $labor['actual_hours'], 8) !== 0) {
                        throw new DomainException(__('production_execution.messages.labor_days_total'));
                    }
                }

                return bccomp($hours, '0', 8) > 0 ? ['index' => $index, 'production_run_id' => $target['production_run_id'], 'hours' => $hours, 'work_segments' => $datedHours] : null;
            })->filter()->values();
            if ($matched->isEmpty()) {
                throw new DomainException(__('overhead_allocations.messages.direct_payroll_unmatched', ['employee' => $source['employee_id']]));
            }
            $totalHours = $this->sum($matched, 'hours', 8);
            $shares = $this->allocate($source['source_amount'], $matched, 'hours', $totalHours);
            foreach ($matched as $position => $match) {
                $amounts[$match['index']] = bcadd($amounts[$match['index']], $shares[$position], 4);
                $details[] = [...$source, 'production_run_id' => $match['production_run_id'], 'hours' => $match['hours'],
                    'total_employee_hours' => $totalHours, 'allocated_amount' => $shares[$position], 'work_segments' => $match['work_segments']];
            }
        }

        return ['amounts' => $amounts, 'sources' => $details];
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

        $this->assertRuleConfiguration($rule);

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
    private function assertTargetsRemainUnreceived(Collection $runIds, bool $lockForUpdate = false): void
    {
        $query = ProductionRun::query()
            ->whereIn('id', $runIds->all())
            ->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $targets = $query->get();
        foreach ($targets as $target) {
            app(ProductionStageTransferService::class)->assertCostMutationAllowed($target);
        }
        $received = $targets->contains(fn (ProductionRun $target): bool => bccomp((string) $target->received_base_quantity, '0', 8) > 0)
            || InventoryDocument::query()
                ->whereIn('production_run_id', $runIds->all())
                ->where('document_type', InventoryDocument::TypeProductionReceipt)
                ->where('status', InventoryDocument::StatusPosted)
                ->exists();

        if ($received) {
            throw new DomainException(__('overhead_allocations.messages.received_run_locked'));
        }
    }

    private function assertRuleConfiguration(OverheadAllocationRule $rule): void
    {
        $sourceCostCenter = CostCenter::query()
            ->forCompany((int) $rule->company_id)
            ->active()
            ->where('is_group', false)
            ->find($rule->source_cost_center_id);
        if (! $sourceCostCenter instanceof CostCenter) {
            throw new DomainException(__('overhead_allocations.messages.rule_configuration_changed'));
        }

        $sourceAccountIds = $this->validatedAccountIds((int) $rule->company_id, $rule->source_account_ids ?? []);
        if ($sourceCostCenter->accounts()->whereIn('accounts.id', $sourceAccountIds)->count() !== count($sourceAccountIds)) {
            throw new DomainException(__('overhead_allocations.messages.rule_configuration_changed'));
        }

        $this->validatedCostCenterIds((int) $rule->company_id, $rule->target_cost_center_ids ?? []);
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

        $found = CostCenter::query()->forCompany($companyId)->active()->where('is_group', false)->whereIn('id', $ids)->pluck('id')->map(fn (mixed $id): int => (int) $id);
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
        return $this->round((string) $value, $scale);
    }

    private function round(string $value, int $scale): string
    {
        $precision = $scale + 1;
        $half = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        $adjusted = bccomp($value, '0', $precision) < 0
            ? bcsub($value, $half, $precision)
            : bcadd($value, $half, $precision);

        return bcadd($adjusted, '0', $scale);
    }
}
