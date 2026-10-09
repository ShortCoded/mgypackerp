<?php

namespace Modules\Purchases\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Services\InventoryReceiptCostCompletionService;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Modules\Inventory\Services\InventoryValueAdjustmentService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Purchases\Models\PurchaseInvoice;

class PurchaseInvoiceCostAdjustmentService
{
    public const ReversalSource = 'purchase_invoice_cost_reversal';

    public function __construct(
        private readonly PostingAccountResolver $accounts,
        private readonly InventoryReceiptCostCompletionService $completion,
        private readonly InventoryValueAdjustmentService $adjustments,
        private readonly PurchaseReceiptCostAllocationService $allocation,
    ) {}

    public function notice(int $companyId): ?string
    {
        try {
            return $this->accounts->resolveOptional($companyId, PostingAccountResolver::PurchasePriceVariance, __('Purchase Invoice')) === null
                ? __('purchase_invoices.messages.cost_fallback_warning') : null;
        } catch (DomainException $exception) {
            return $exception->getMessage();
        }
    }

    public function hasAdjustment(PurchaseInvoice $invoice): bool
    {
        return InventoryValueAdjustment::query()->where('company_id', $invoice->company_id)
            ->where('source_type', PurchaseInvoice::class)->where('source_id', $invoice->id)->exists();
    }

    public function standardSettlementNotice(PurchaseInvoice $invoice): ?string
    {
        $runIds = InventoryValueAdjustment::query()->where('company_id', $invoice->company_id)
            ->whereIn('source_type', [PurchaseInvoice::class, self::ReversalSource])->where('source_id', $invoice->id)
            ->get(['source_snapshot'])->flatMap(fn ($adjustment): array => $adjustment->source_snapshot['standard_settlement_run_ids'] ?? [])->unique();
        $runs = ProductionRun::query()->where('company_id', $invoice->company_id)->whereIn('id', $runIds)->get();
        $positions = app(ProductionCostService::class)->positions($runs);

        return $positions->contains(fn (array $position): bool => bccomp($position['wip'], '0', 8) !== 0)
            ? __('purchase_invoices.messages.cost_standard_settlement_pending') : null;
    }

    public function receiptHasActiveAdjustment(int $companyId, int $receiptLineId): bool
    {
        return PurchaseInvoice::query()->where('company_id', $companyId)
            ->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])
            ->whereHas('lines', fn ($query) => $query->where('receipt_line_id', $receiptLineId))
            ->whereIn('id', InventoryValueAdjustment::query()->where('company_id', $companyId)->where('source_type', PurchaseInvoice::class)->select('source_id'))->exists();
    }

    /** @return array<string, mixed>|null */
    public function plan(PurchaseInvoice $invoice): ?array
    {
        $this->allocation->lockCompany((int) $invoice->company_id);
        $basis = $this->allocation->describeInvoice($invoice, true);
        $variances = collect($basis['lines'])->where('treatment', 'capitalized');
        if ($variances->isEmpty() || $this->accounts->resolveOptional((int) $invoice->company_id, PostingAccountResolver::PurchasePriceVariance, __('Purchase Invoice')) !== null) {
            return null;
        }
        $branchVariances = [];
        foreach ($variances as $line) {
            $branchVariances[$line['branch_id']] = bcadd($branchVariances[$line['branch_id']] ?? '0', $line['variance_base'], 4);
        }
        $events = $this->allocation->targets((int) $invoice->company_id, $variances->pluck('receipt_line_id')->unique()->values()->all(), $basis);

        return [...$this->preparePlan($invoice, $events['root_deltas'], $invoice->invoice_date->toDateString(), (int) $invoice->financial_period_id, $branchVariances),
            'cost_events' => $events['cost_events']];
    }

    /** @param array<string, mixed> $plan */
    public function post(PurchaseInvoice $invoice, array $plan, bool $reverse = false): InventoryValueAdjustment
    {
        return DB::transaction(function () use ($invoice, $plan, $reverse): InventoryValueAdjustment {
            $this->allocation->lockCompany((int) $invoice->company_id);

            return $this->postLocked($invoice, $plan, $reverse);
        }, 3);
    }

    /** @param array<string, mixed> $plan */
    private function postLocked(PurchaseInvoice $invoice, array $plan, bool $reverse): InventoryValueAdjustment
    {
        Gate::authorize($reverse ? 'purchase_invoices.reverse' : 'purchase_invoices.approve');
        $sourceType = $reverse ? self::ReversalSource : PurchaseInvoice::class;
        $existing = InventoryValueAdjustment::query()->where('company_id', $invoice->company_id)
            ->where('source_type', $sourceType)->where('source_id', $invoice->id)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->source_snapshot !== $plan) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }

            return $existing;
        }
        $fresh = $reverse ? $this->reversalPlan($invoice, $plan['posting_date'], (int) $plan['posting_period_id']) : $this->plan($invoice);
        if ($fresh !== $plan) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
        }
        $adjustment = $this->adjustments->postPurchaseVariance($invoice, $plan, request(), $reverse);
        $this->completion->persistBases($adjustment, $plan);
        app(ProcurementAuditService::class)->record($invoice, $reverse ? 'purchase_invoice.cost_reversed' : 'purchase_invoice.cost_adjusted',
            ['inventory_value_adjustment_id' => $adjustment->id, 'input_sha256' => $plan['input_sha256']]);

        return $adjustment;
    }

    public function reverse(PurchaseInvoice $invoice, string $postingDate, int $postingPeriodId): void
    {
        $original = InventoryValueAdjustment::query()->where('company_id', $invoice->company_id)
            ->where('source_type', PurchaseInvoice::class)->where('source_id', $invoice->id)->lockForUpdate()->first();
        if ($original === null) {
            return;
        }
        $plan = $this->reversalPlan($invoice, $postingDate, $postingPeriodId);
        $this->post($invoice, $plan, reverse: true);
    }

    /** @return array<string, mixed> */
    private function reversalPlan(PurchaseInvoice $invoice, string $date, int $periodId): array
    {
        $basis = $this->allocation->invoiceBasis($invoice);
        $lines = collect($basis['lines'])->where('treatment', 'capitalized');
        $branches = [];
        foreach ($lines as $line) {
            $branches[$line['branch_id']] = bcsub($branches[$line['branch_id']] ?? '0', $line['variance_base'], 4);
        }
        $events = $this->allocation->targets((int) $invoice->company_id, $lines->pluck('receipt_line_id')->unique()->values()->all(), excludeInvoiceId: (int) $invoice->id);

        return [...$this->preparePlan($invoice, $events['root_deltas'], $date, $periodId, $branches), 'cost_events' => $events['cost_events']];
    }

    /** @param array<int, string> $deltas @param array<int, string> $branches @return array<string, mixed> */
    private function preparePlan(PurchaseInvoice $invoice, array $deltas, string $date, int $periodId, array $branches): array
    {
        app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $invoice->company_id, $date, $periodId, lockForUpdate: true);
        $plan = $this->completion->planPurchaseVariance($invoice, $deltas, $date, $periodId);
        app(InventoryReceiptCostProposalService::class)->assertImpactBranchAccess(request(), $plan);
        $counterpartEffects = collect($plan['effects'])->where('account_id', $plan['counterpart_account_id'])
            ->reduce(fn (string $sum, array $effect): string => bcadd($sum, $effect['amount'], 8), '0');
        $actual = bcsub($plan['source_total'], $counterpartEffects, 8);
        $expected = array_reduce($branches, fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000');
        if (bccomp(bcround($actual, 4), $expected, 4) !== 0) {
            throw new DomainException(__('purchase_invoices.messages.cost_lineage_unbalanced'));
        }

        return [...$plan, 'invoice_variance_by_branch' => $branches, 'invoice_snapshot' => self::snapshot($invoice)];
    }

    /** @return array<string, mixed> */
    public static function snapshot(PurchaseInvoice $invoice): array
    {
        return json_decode(json_encode(['header' => $invoice->only(['id', 'company_id', 'branch_id', 'financial_period_id', 'invoice_date', 'currency_id', 'exchange_rate', 'total_amount']),
            'lines' => $invoice->lines()->orderBy('id')->get()->map(fn ($line): array => $line->only(['id', 'receipt_line_id', 'product_id', 'quantity', 'net_amount', 'unit_price', 'discount_amount']))->all()], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
