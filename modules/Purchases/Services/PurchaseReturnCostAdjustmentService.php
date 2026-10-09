<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Inventory\Services\InventoryReceiptCostCompletionService;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Modules\Inventory\Services\InventoryValueAdjustmentService;
use Modules\Purchases\Models\PurchaseReturn;

class PurchaseReturnCostAdjustmentService
{
    public const ReversalSource = 'purchase_return_cost_reversal';

    public function __construct(
        private readonly PurchaseReceiptCostAllocationService $allocation,
        private readonly InventoryReceiptCostCompletionService $completion,
        private readonly InventoryValueAdjustmentService $adjustments,
    ) {}

    public function settle(PurchaseReturn $return, bool $reverse = false): ?InventoryValueAdjustment
    {
        return DB::transaction(function () use ($return, $reverse): ?InventoryValueAdjustment {
            $this->allocation->lockCompany((int) $return->company_id);
            $locked = PurchaseReturn::query()->where('company_id', $return->company_id)->lockForUpdate()->findOrFail($return->id);

            return $this->settleLocked($locked, $reverse);
        }, 3);
    }

    private function settleLocked(PurchaseReturn $return, bool $reverse): ?InventoryValueAdjustment
    {
        $return->loadMissing('lines.receiptLine');
        if ($return->lines->where('from_quarantine', false)->isEmpty()) {
            return null;
        }
        Gate::authorize($reverse ? 'purchases.purchase_returns.reverse' : 'purchases.purchase_returns.post');
        if ($return->status !== ($reverse ? PurchaseReturn::StatusReversed : PurchaseReturn::StatusPosted)) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
        }
        if (! $reverse) {
            $this->allocation->captureReturn($return);
        }
        $receiptIds = $return->lines->where('from_quarantine', false)->pluck('receipt_line_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();
        if (! collect($receiptIds)->contains(fn (int $id): bool => $this->allocation->hasHistory((int) $return->company_id, $id))) {
            return null;
        }
        $sourceType = $reverse ? self::ReversalSource : PurchaseReturn::class;
        $existing = InventoryValueAdjustment::query()->where('company_id', $return->company_id)->where('source_type', $sourceType)
            ->where('source_id', $return->id)->lockForUpdate()->first();
        if ($existing !== null) {
            if (($existing->source_snapshot['return_snapshot'] ?? null) !== self::snapshot($return)) {
                throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
            }

            return $existing;
        }
        $plan = $this->plan($return, $receiptIds, $reverse);
        if ($plan !== $this->plan($return, $receiptIds, $reverse)) {
            throw new DomainException(__('inventory.movements.messages.receipt_pricing_source_changed'));
        }
        $adjustment = $this->adjustments->postPurchaseReturn($return, $plan, request(), $reverse);
        $this->completion->persistBases($adjustment, $plan);
        app(ProcurementAuditService::class)->record($return, $reverse ? 'purchase_return.cost_reversed' : 'purchase_return.cost_adjusted',
            ['inventory_value_adjustment_id' => $adjustment->id, 'input_sha256' => $plan['input_sha256']]);

        return $adjustment;
    }

    /** @param list<int> $receiptIds @return array<string, mixed> */
    private function plan(PurchaseReturn $return, array $receiptIds, bool $reverse): array
    {
        $basis = $this->allocation->returnBasis($return);
        $date = $reverse ? now()->toDateString() : $return->return_date->toDateString();
        $periodId = $reverse ? (int) app(OperatingContextService::class)->snapshot(request())['financial_period_id'] : (int) $return->financial_period_id;
        app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $return->company_id, $date, $periodId, lockForUpdate: true);
        $events = $this->allocation->targets((int) $return->company_id, $receiptIds);
        $plan = $this->completion->planPurchaseVariance($return, $events['root_deltas'], $date, $periodId);
        app(InventoryReceiptCostProposalService::class)->assertImpactBranchAccess(request(), $plan);
        $economic = '0';
        $provisional = '0';
        foreach ($basis['lines'] as $line) {
            $ppvBase = bcmul($line['ppv_currency'], $line['exchange_rate'], 4);
            $value = bcsub($line['book_base'], $return->purchase_invoice_id !== null
                ? bcsub($line['commercial_base'], $ppvBase, 8) : $line['provisional_base'], 8);
            $economic = bcadd($economic, $value, 8);
            $provisional = bcadd($provisional, $line['provisional_base'], 4);
        }
        $grniAmount = '0';
        $stockAmount = '0';
        $stockAccounts = collect($basis['lines'])->pluck('inventory_account_id')->unique()->all();
        foreach ($basis['journal']['lines'] as $line) {
            $amount = bcmul(bcsub($line['credit_amount'], $line['debit_amount'], 4), $basis['journal']['header']['exchange_rate'], 8);
            if ((int) $line['account_id'] === (int) $plan['counterpart_account_id']) {
                $grniAmount = bcadd($grniAmount, $amount, 8);
            }
            if (in_array((int) $line['account_id'], $stockAccounts, true)) {
                $stockAmount = bcadd($stockAmount, $amount, 8);
            }
        }
        $gl = $return->purchase_invoice_id !== null ? bcmul($grniAmount, '-1', 8) : bcsub($stockAmount, $provisional, 8);
        $counterpart = collect($plan['effects'])->where('account_id', $plan['counterpart_account_id'])
            ->reduce(fn (string $sum, array $effect): string => bcadd($sum, $effect['amount'], 8), '0');
        $actual = bcsub($plan['source_total'], $counterpart, 8);
        $sign = $reverse ? '-1' : '1';
        if (bccomp(bcround($actual, 4), bcround(bcmul($economic, $sign, 8), 4), 4) !== 0) {
            throw new DomainException(__('purchase_invoices.messages.cost_lineage_unbalanced'));
        }
        $branches = [(int) $return->branch_id => bcround(bcmul($gl, $sign, 8), 4)];
        $precision = bcsub(bcmul($gl, $sign, 8), $actual, 8);
        if (bccomp($precision, '0', 8) !== 0) {
            $line = collect($basis['lines'])->sort(fn (array $first, array $second): int => bccomp($second['book_base'], $first['book_base'], 8))->first();
            $sourceLineId = array_search($line, $basis['lines'], true);
            $transaction = InventoryTransaction::query()->where('company_id', $return->company_id)
                ->where('posting_key', 'purchase-return:'.$sourceLineId)->sole();
            $account = Account::query()->forCompany((int) $return->company_id)->eligibleForDirectPosting()->findOrFail($line['inventory_account_id']);
            $base = ['source_transaction_id' => (int) $transaction->id, 'source_doc_num' => $return->doc_num,
                'branch_id' => (int) $return->branch_id, 'cost_center_id' => null, 'production_run_id' => null,
                'document_line_id' => null, 'production_cost_role' => null, 'production_cost_delta' => null,
                'effect' => InventoryValueAdjustmentLine::EffectGlPrecision, 'unvalued_quantity_delta' => '0.00000000',
                'precision_key' => 'purchase-return:'.$return->id.':'.($reverse ? 'reverse:' : '').'source-precision',
                'native_journal' => $basis['journal'], 'economic_variance' => $economic];
            $plan['effects'][] = [...$base, 'account_id' => (int) $account->id, 'amount' => $precision,
                'account_labels' => ['ar' => $account->codeNameLabel('ar'), 'en' => $account->codeNameLabel('en')]];
            $plan['effects'][] = [...$base, 'account_id' => (int) $plan['counterpart_account_id'], 'amount' => bcmul($precision, '-1', 8),
                'precision_key' => $base['precision_key'].':counterpart', 'account_labels' => $plan['counterpart_account_labels']];
        }

        return [...$plan, 'invoice_variance_by_branch' => $branches, 'return_snapshot' => self::snapshot($return),
            'return_basis' => $basis, 'cost_events' => $events['cost_events'],
            'economic_variance' => bcmul($economic, $sign, 8), 'source_gl_precision' => $precision];
    }

    /** @return array<string, mixed> */
    public static function snapshot(PurchaseReturn $return): array
    {
        return PurchaseReceiptCostAllocationService::normalize(['header' => $return->only(['id', 'company_id', 'branch_id', 'financial_period_id',
            'purchase_invoice_id', 'return_date', 'total_amount']),
            'lines' => $return->lines()->orderBy('id')->get()->map(fn ($line): array => $line->only(['id', 'receipt_line_id', 'purchase_invoice_line_id',
                'quantity', 'from_quarantine', 'unit_price', 'tax_amount', 'line_total', 'grni_reversed_value']))->all()]);
    }
}
