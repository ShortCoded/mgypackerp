<?php

namespace Modules\Purchases\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
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
        private readonly PurchaseInvoiceCalculationService $calculator,
        private readonly InventoryReceiptCostCompletionService $completion,
        private readonly InventoryValueAdjustmentService $adjustments,
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
        $netAmounts = $this->calculator->netAmountsByLine($invoice);
        $variances = [];
        foreach ($invoice->lines as $line) {
            if ($line->receipt_line_id === null || $line->product?->isService() || ! $line->product?->cost_as_inventory) {
                continue;
            }
            $receipt = UnpricedInventoryReceiptLine::query()->where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($line->receipt_line_id);
            $provisional = bcmul((string) $receipt->provisional_unit_value, (string) $line->quantity, 4);
            $variance = bcsub((string) $netAmounts[$line->id], bcdiv($provisional, (string) $invoice->exchange_rate, 4), 4);
            if (bccomp($variance, '0', 4) !== 0) {
                $variances[] = [$receipt, bcmul($variance, (string) $invoice->exchange_rate, 4)];
            }
        }
        if ($variances === [] || $this->accounts->resolveOptional((int) $invoice->company_id, PostingAccountResolver::PurchasePriceVariance, __('Purchase Invoice')) !== null) {
            return null;
        }
        $deltas = [];
        $branchVariances = [];
        foreach ($variances as [$receiptLine, $variance]) {
            $root = InventoryTransaction::query()->where('company_id', $invoice->company_id)
                ->where('posting_key', 'purchase-receipt:'.$receiptLine->id)->lockForUpdate()->first();
            $receiptLine->loadMissing('receipt');
            $factor = $receiptLine->product_snapshot['stock_conversion_factor'] ?? null;
            $eligible = bcsub((string) $receiptLine->accepted_quantity, (string) $receiptLine->grni_returned_quantity, 8);
            if ($root === null || $factor === null || $root->source_type !== UnpricedInventoryReceipt::class
                || (int) $root->source_id !== (int) $receiptLine->receipt_id
                || $root->source_line_type !== UnpricedInventoryReceiptLine::class || (int) $root->source_line_id !== (int) $receiptLine->id
                || (int) $root->product_id !== (int) $receiptLine->product_id || (int) $root->branch_id !== (int) $receiptLine->branch_id
                || ! $receiptLine->receipt?->approved || $receiptLine->receipt?->posting_status !== 'posted'
                || bccomp($eligible, '0', 8) <= 0
                || bccomp((string) $root->quantity_in, bcmul((string) $receiptLine->accepted_quantity, (string) $factor, 8), 8) !== 0
                || $root->total_cost === null || bccomp((string) $root->total_cost, (string) $receiptLine->provisional_total_value, 4) !== 0
                || bccomp((string) InventoryReceiptLayer::query()->where('receipt_transaction_id', $root->id)->sum('original_quantity'), (string) $root->quantity_in, 8) !== 0) {
                throw new DomainException(__('purchase_invoices.messages.cost_lineage_missing'));
            }
            $delta = bcdiv(bcmul($variance, (string) $receiptLine->accepted_quantity, 16), $eligible, 8);
            $deltas[$root->id] = bcadd($deltas[$root->id] ?? '0', $delta, 8);
            $branchVariances[$receiptLine->branch_id] = bcadd($branchVariances[$receiptLine->branch_id] ?? '0', $variance, 4);
        }

        return $this->preparePlan($invoice, $deltas, $invoice->invoice_date->toDateString(), (int) $invoice->financial_period_id, $branchVariances);
    }

    /** @param array<string, mixed> $plan */
    public function post(PurchaseInvoice $invoice, array $plan, bool $reverse = false): InventoryValueAdjustment
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
        $fresh = $this->preparePlan($invoice, $plan['root_deltas'], $plan['posting_date'], (int) $plan['posting_period_id'], $plan['invoice_variance_by_branch']);
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
        $deltas = array_map(fn (string $delta): string => bcmul($delta, '-1', 8), $original->source_snapshot['root_deltas']);
        $branches = array_map(fn (string $delta): string => bcmul($delta, '-1', 4), $original->source_snapshot['invoice_variance_by_branch']);
        $plan = $this->preparePlan($invoice, $deltas, $postingDate, $postingPeriodId, $branches);
        $this->post($invoice, $plan, reverse: true);
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
        if (bccomp($actual, $expected, 4) !== 0) {
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
