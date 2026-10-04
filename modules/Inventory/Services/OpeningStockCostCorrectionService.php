<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockCostCorrection;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;

class OpeningStockCostCorrectionService
{
    public function __construct(
        private readonly InventoryReceiptCostCompletionService $completion,
        private readonly InventoryValueAdjustmentService $adjustments,
        private readonly OperatingContextService $context,
        private readonly OperatingScopeAccessService $scopeAccess,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{posting_date: string, counterpart_account_id: int, reason: string, source_reference: string}  $data
     * @param  array<int, string>  $unitCosts
     */
    public function prepare(Request $request, OpeningStock $openingStock, array $data, array $unitCosts): OpeningStockCostCorrection
    {
        Gate::forUser($request->user())->authorize('inventory.opening_stock_cost_corrections.prepare');

        return DB::transaction(function () use ($request, $openingStock, $data, $unitCosts): OpeningStockCostCorrection {
            $source = $this->lockSource($openingStock);
            $this->assertContext($request, $source);
            $this->assertEligibleSource($source);

            $reason = trim((string) ($data['reason'] ?? ''));
            $sourceReference = trim((string) ($data['source_reference'] ?? ''));
            $postingDate = (string) ($data['posting_date'] ?? '');
            if ($reason === '' || $sourceReference === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $postingDate)
                || $postingDate < $source->document_date->toDateString()) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }
            if (OpeningStockCostCorrection::query()->where('opening_stock_id', $source->id)
                ->where('status', OpeningStockCostCorrection::StatusPending)->lockForUpdate()->exists()) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_pending'));
            }

            $normalizedCosts = $this->normalizeUnitCosts($source, $unitCosts);
            $scope = $this->context->snapshot($request);
            $plan = $this->completion->planOpening($source, $normalizedCosts, $postingDate,
                (int) $scope['financial_period_id'], (int) ($data['counterpart_account_id'] ?? 0));
            $this->assertImpactBranchAccess($request, $plan);
            if (bccomp((string) $plan['source_total'], '0', 8) === 0) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }
            $sourceSnapshot = $this->captureSourceSnapshot($source, $normalizedCosts);
            $fingerprint = $this->fingerprint($sourceSnapshot, $normalizedCosts, $plan);
            $correction = OpeningStockCostCorrection::query()->create([
                'public_uuid' => (string) Str::uuid(),
                'company_id' => $source->company_id,
                'opening_stock_id' => $source->id,
                'financial_period_id' => $source->financial_period_id,
                'branch_id' => $source->branch_id,
                'posting_period_id' => $scope['financial_period_id'],
                'posting_date' => $postingDate,
                'counterpart_account_id' => $data['counterpart_account_id'],
                'status' => OpeningStockCostCorrection::StatusPending,
                'reason' => $reason,
                'source_reference' => $sourceReference,
                'unit_costs' => $normalizedCosts,
                'source_snapshot' => $sourceSnapshot,
                'plan' => $plan,
                'fingerprint' => $fingerprint,
                'prepared_by' => $request->user()->id,
            ]);
            $this->log($request, $correction, 'opening_stock_cost_correction_prepared');

            return $correction->refresh();
        });
    }

    public function approve(Request $request, OpeningStock $openingStock, OpeningStockCostCorrection $correction,
        string $approvalReference): OpeningStockCostCorrection
    {
        Gate::forUser($request->user())->authorize('inventory.opening_stock_cost_corrections.approve');

        return DB::transaction(function () use ($request, $openingStock, $correction, $approvalReference): OpeningStockCostCorrection {
            $source = $this->lockSource($openingStock);
            $candidate = OpeningStockCostCorrection::query()->lockForUpdate()->findOrFail($correction->id);
            $this->assertContext($request, $source, (int) $candidate->posting_period_id);
            if ((int) $candidate->opening_stock_id !== (int) $source->id
                || (int) $candidate->company_id !== (int) $source->company_id
                || (int) $candidate->financial_period_id !== (int) $source->financial_period_id
                || (int) $candidate->branch_id !== (int) $source->branch_id) {
                throw new DomainException(__('inventory.movements.messages.context_mismatch'));
            }
            if ($candidate->status === OpeningStockCostCorrection::StatusApproved) {
                if ($candidate->inventory_value_adjustment_id === null || ! $candidate->adjustment()->exists()) {
                    throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
                }

                return $candidate->refresh();
            }
            if ($candidate->status !== OpeningStockCostCorrection::StatusPending
                || (int) $candidate->prepared_by === (int) $request->user()->id
                || trim($approvalReference) === '') {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_approval_unavailable'));
            }
            $this->assertEligibleSource($source);
            $normalizedCosts = $this->normalizeUnitCosts($source, $candidate->unit_costs);
            $plan = $this->completion->planOpening($source, $normalizedCosts, $candidate->posting_date->toDateString(),
                (int) $candidate->posting_period_id, (int) $candidate->counterpart_account_id);
            $this->assertImpactBranchAccess($request, $plan);
            $sourceSnapshot = $this->captureSourceSnapshot($source, $normalizedCosts);
            if ($candidate->fingerprint !== $this->fingerprint($sourceSnapshot, $normalizedCosts, $plan)
                || $candidate->source_snapshot !== $sourceSnapshot || $candidate->plan !== $plan) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }

            $candidate->forceFill(['approval_reference' => trim($approvalReference)])->save();
            $adjustment = $this->adjustments->postOpeningCorrection($candidate, $plan, $request);
            $this->completion->persistBases($adjustment, $plan);
            $candidate->forceFill([
                'status' => OpeningStockCostCorrection::StatusApproved,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'inventory_value_adjustment_id' => $adjustment->id,
            ])->save();
            $this->log($request, $candidate, 'opening_stock_cost_correction_approved');

            return $candidate->refresh();
        });
    }

    public function reject(Request $request, OpeningStock $openingStock, OpeningStockCostCorrection $correction, string $reason): OpeningStockCostCorrection
    {
        Gate::forUser($request->user())->authorize('inventory.opening_stock_cost_corrections.approve');

        return DB::transaction(function () use ($request, $openingStock, $correction, $reason): OpeningStockCostCorrection {
            $source = $this->lockSource($openingStock);
            $candidate = OpeningStockCostCorrection::query()->lockForUpdate()->findOrFail($correction->id);
            $this->assertContext($request, $source, (int) $candidate->posting_period_id);
            if ((int) $candidate->opening_stock_id !== (int) $source->id
                || $candidate->status !== OpeningStockCostCorrection::StatusPending || trim($reason) === '') {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_approval_unavailable'));
            }
            $candidate->forceFill([
                'status' => OpeningStockCostCorrection::StatusRejected,
                'rejection_reason' => trim($reason),
                'rejected_by' => $request->user()->id,
                'rejected_at' => now(),
            ])->save();
            $this->log($request, $candidate, 'opening_stock_cost_correction_rejected');

            return $candidate->refresh();
        });
    }

    /** @param array<int|string, mixed> $unitCosts @return array<int, string> */
    private function normalizeUnitCosts(OpeningStock $source, array $unitCosts): array
    {
        $lines = $source->lines()->orderBy('id')->lockForUpdate()->get();
        $normalized = [];
        foreach ($unitCosts as $lineId => $unitCost) {
            $normalized[(int) $lineId] = trim((string) $unitCost);
        }
        ksort($normalized);
        if ($lines->isEmpty() || count($normalized) !== $lines->count()
            || array_diff($lines->modelKeys(), array_keys($normalized)) !== []) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
        }
        foreach ($normalized as $unitCost) {
            if (! preg_match('/^\d{1,11}(?:\.\d{1,8})?$/D', $unitCost) || bccomp($unitCost, '0', 8) <= 0) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_lines_changed'));
            }
        }

        return $normalized;
    }

    /** @param array<int, string> $unitCosts @return array<string, mixed> */
    private function captureSourceSnapshot(OpeningStock $source, array $unitCosts): array
    {
        $lines = $source->lines()->orderBy('id')->lockForUpdate()->get();
        $pricingLines = OpeningStockPricingLine::withTrashed()->whereIn('opening_stock_line_id', $lines->modelKeys())
            ->orderBy('id')->lockForUpdate()->get();
        $pricings = OpeningStockPricing::withTrashed()->whereIn('id', $pricingLines->pluck('pricing_id')->unique())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $capturedLines = [];
        foreach ($lines as $line) {
            $root = InventoryTransaction::query()->where('company_id', $source->company_id)
                ->where('source_type', OpeningStock::class)->where('source_id', $source->id)
                ->where('posting_key', "opening-stock:{$source->id}:line:{$line->id}")
                ->lockForUpdate()->sole();
            $pricingEvidence = $pricingLines->where('opening_stock_line_id', $line->id)->map(function (OpeningStockPricingLine $pricingLine) use ($pricings): array {
                $pricing = $pricings->get($pricingLine->pricing_id);

                return [
                    'pricing_id' => (int) $pricingLine->pricing_id,
                    'pricing_doc_num' => $pricing?->doc_num,
                    'pricing_status' => $pricing?->status,
                    'pricing_deleted_at' => $pricing?->deleted_at?->toISOString(),
                    'pricing_line_id' => (int) $pricingLine->id,
                    'pricing_line_public_id' => (string) $pricingLine->public_id,
                    'pricing_line_deleted_at' => $pricingLine->deleted_at?->toISOString(),
                    'quantity' => (string) $pricingLine->quantity,
                    'unit_price' => (string) $pricingLine->unit_price,
                    'line_total' => (string) $pricingLine->line_total,
                    'exchange_rate' => $pricing === null ? null : (string) $pricing->exchange_rate,
                ];
            })->values()->all();
            $capturedLines[] = [
                'line_id' => (int) $line->id,
                'line_public_id' => (string) $line->public_id,
                'product_id' => (int) $line->product_id,
                'product_code' => (string) ($line->product_snapshot['doc_num'] ?? ''),
                'product_name' => (string) ($line->product_snapshot['name'] ?? ''),
                'quantity' => (string) $line->quantity,
                'root_transaction_id' => (int) $root->id,
                'root_unit_cost' => $root->unit_cost === null ? null : (string) $root->unit_cost,
                'root_total_cost' => $root->total_cost === null ? null : (string) $root->total_cost,
                'effective_total_cost' => $root->completedTotalCost(),
                'target_unit_cost' => $unitCosts[$line->id],
                'pricing_documents' => $pricingEvidence,
            ];
        }
        $sourcePeriod = FinancialPeriod::query()->where('company_id', $source->company_id)
            ->whereKey($source->financial_period_id)->lockForUpdate()->firstOrFail();

        return [
            'opening_stock_id' => (int) $source->id,
            'opening_stock_doc_num' => (string) $source->doc_num,
            'document_date' => $source->document_date->toDateString(),
            'source_period_id' => (int) $source->financial_period_id,
            'source_period_closed' => (bool) $sourcePeriod->is_closed,
            'branch_id' => (int) $source->branch_id,
            'branch_store_id' => (int) $source->branch_store_id,
            'approved' => (bool) $source->approved,
            'status' => (string) $source->status,
            'lines' => $capturedLines,
        ];
    }

    /** @param array<string, mixed> $sourceSnapshot @param array<int, string> $unitCosts @param array<string, mixed> $plan */
    private function fingerprint(array $sourceSnapshot, array $unitCosts, array $plan): string
    {
        return hash('sha256', json_encode([
            'source_snapshot' => $sourceSnapshot,
            'unit_costs' => $unitCosts,
            'plan' => $plan,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertEligibleSource(OpeningStock $source): void
    {
        $sourcePeriod = FinancialPeriod::query()->where('company_id', $source->company_id)
            ->whereKey($source->financial_period_id)->lockForUpdate()->first();
        if ($source->trashed() || ! $source->approved || $source->status !== OpeningStock::StatusApproved
            || $source->branch_store_id === null || $sourcePeriod === null || $source->lines()->count() === 0) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_unavailable'));
        }
    }

    private function lockSource(OpeningStock $openingStock): OpeningStock
    {
        $companyId = (int) OpeningStock::query()->whereKey($openingStock->id)->value('company_id');
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

        return OpeningStock::query()->lockForUpdate()->findOrFail($openingStock->id);
    }

    private function assertContext(Request $request, OpeningStock $source, ?int $postingPeriodId = null): void
    {
        $scope = $this->context->snapshot($request);
        $postingPeriodId ??= (int) $scope['financial_period_id'];
        $companyDocNum = Company::query()->whereKey($source->company_id)->value('doc_num');
        if ((int) $source->company_id !== (int) $scope['company_id']
            || (int) $source->branch_id !== (int) $scope['branch_id']
            || $postingPeriodId !== (int) $scope['financial_period_id']
            || ! is_string($companyDocNum)
            || ! $this->scopeAccess->allowedFinancialPeriodQuery($request->user(), [$companyDocNum])
                ->where('financial_periods.id', $source->financial_period_id)->exists()
            || ! $this->context->allowedBranchQueryForCurrentCompany($request)->whereKey($source->branch_id)->exists()) {
            throw new DomainException(__('inventory.movements.messages.context_mismatch'));
        }
    }

    /** @param array<string, mixed> $plan */
    public function assertImpactBranchAccess(Request $request, array $plan): void
    {
        $branchIds = collect($plan['effects'])->pluck('branch_id')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($branchIds->isNotEmpty()
            && $this->context->allowedBranchQueryForCurrentCompany($request)->whereIn('branches.id', $branchIds)->count() !== $branchIds->count()) {
            throw new DomainException(__('inventory.movements.messages.receipt_completion_branch_access'));
        }
    }

    private function log(Request $request, OpeningStockCostCorrection $correction, string $action): void
    {
        $this->activity->log($request, 'inventory', $action, 'success', [
            'subject' => $correction,
            'company_id' => $correction->company_id,
            'properties_only' => true,
            'properties' => $correction->only([
                'public_uuid', 'opening_stock_id', 'financial_period_id', 'posting_period_id', 'posting_date',
                'status', 'source_reference', 'fingerprint', 'approval_reference', 'inventory_value_adjustment_id',
            ]),
        ]);
    }
}
