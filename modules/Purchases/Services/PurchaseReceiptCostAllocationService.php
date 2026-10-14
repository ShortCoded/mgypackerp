<?php

namespace Modules\Purchases\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\InventoryValueAdjustment;
use Modules\Inventory\Models\InventoryValueAdjustmentLine;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\PurchaseReturnLine;

class PurchaseReceiptCostAllocationService
{
    public const InvoiceBasisEvent = 'purchase_invoice.valuation_basis';

    public const ReturnBasisEvent = 'purchase_return.valuation_basis';

    public function __construct(
        private readonly PurchaseInvoiceCalculationService $calculator,
        private readonly PostingAccountResolver $accounts,
    ) {}

    public function lockCompany(int $companyId): void
    {
        if (app(OperatingCompanyContextService::class)->requireCompanyId() !== $companyId) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
    }

    public function hasHistory(int $companyId, int $receiptLineId): bool
    {
        return InventoryValueAdjustmentLine::query()
            ->whereHas('sourceTransaction', fn ($query) => $query->where('company_id', $companyId)
                ->where('posting_key', 'purchase-receipt:'.$receiptLineId))
            ->whereHas('adjustment', fn ($query) => $query->where('company_id', $companyId)->where('status', InventoryValueAdjustment::StatusPosted)
                ->whereIn('source_type', self::sources()))->exists();
    }

    /** @return list<string> */
    private static function sources(): array
    {
        return [PurchaseInvoice::class, PurchaseInvoiceCostAdjustmentService::ReversalSource,
            PurchaseReturn::class, PurchaseReturnCostAdjustmentService::ReversalSource];
    }

    /** @return array<string, mixed> */
    public function describeInvoice(PurchaseInvoice $invoice, bool $capitalized, bool $legacy = false): array
    {
        $invoice->loadMissing('lines.product');
        $net = $this->calculator->netAmountsByLine($invoice);
        $lines = [];
        $ppv = null;
        foreach ($invoice->lines->sortBy('id') as $line) {
            if ($line->receipt_line_id === null || $line->product?->isService() || ! $line->product?->cost_as_inventory) {
                continue;
            }
            $receipt = UnpricedInventoryReceiptLine::query()->where('company_id', $invoice->company_id)->findOrFail($line->receipt_line_id);
            $provisional = bcmul((string) $receipt->provisional_unit_value, (string) $line->quantity, 4);
            $currencyVariance = bcsub($net[$line->id], bcdiv($provisional, (string) $invoice->exchange_rate, 4), 4);
            $baseVariance = $capitalized && ! $legacy
                ? bcsub(bcmul($net[$line->id], (string) $invoice->exchange_rate, 4), $provisional, 4)
                : bcmul($currencyVariance, (string) $invoice->exchange_rate, 4);
            $treatment = bccomp($capitalized ? $baseVariance : $currencyVariance, '0', 4) === 0 ? 'none' : ($capitalized ? 'capitalized' : 'ppv');
            if ($treatment === 'ppv') {
                $ppv ??= $this->accounts->resolve((int) $invoice->company_id, PostingAccountResolver::PurchasePriceVariance, __('Purchase Invoice'));
            }
            $lines[$line->id] = ['receipt_line_id' => (int) $line->receipt_line_id, 'branch_id' => (int) $receipt->branch_id,
                'quantity' => (string) $line->quantity, 'provisional_base' => $provisional,
                'net_currency' => $net[$line->id], 'variance_currency' => $currencyVariance, 'variance_base' => $baseVariance,
                'treatment' => $treatment, 'ppv_account_id' => $treatment === 'ppv' ? (int) $ppv->id : null];
        }

        return self::normalize(['invoice_snapshot' => PurchaseInvoiceCostAdjustmentService::snapshot($invoice),
            'exchange_rate' => (string) $invoice->exchange_rate, 'lines' => $lines]);
    }

    public function captureInvoice(PurchaseInvoice $invoice, bool $capitalized): void
    {
        $this->lockCompany((int) $invoice->company_id);
        $basis = [...$this->describeInvoice($invoice, $capitalized), 'journal' => self::journalSnapshot(JournalEntry::query()->where('company_id', $invoice->company_id)->find($invoice->journal_entry_id))];
        $this->assertInvoiceJournal($invoice, $basis);
        $this->saveBasis($invoice, self::InvoiceBasisEvent, $basis);
    }

    /** @return array<string, mixed> */
    public function invoiceBasis(PurchaseInvoice $invoice): array
    {
        $basis = $this->readBasis($invoice, self::InvoiceBasisEvent);
        if ($basis !== null) {
            if ($basis['invoice_snapshot'] !== PurchaseInvoiceCostAdjustmentService::snapshot($invoice)
                || $basis['journal'] !== self::journalSnapshot(JournalEntry::query()->where('company_id', $invoice->company_id)->find($invoice->journal_entry_id))) {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }
            $this->assertInvoiceJournal($invoice, $basis);

            return $basis;
        }
        $original = InventoryValueAdjustment::query()->where('company_id', $invoice->company_id)
            ->where('source_type', PurchaseInvoice::class)->where('source_id', $invoice->id)->first();
        if ($original !== null && ($original->source_snapshot['invoice_snapshot'] ?? null) === PurchaseInvoiceCostAdjustmentService::snapshot($invoice)) {
            $basis = $this->describeInvoice($invoice, true, legacy: true);
            $branches = [];
            foreach ($basis['lines'] as $line) {
                if ($line['treatment'] !== 'capitalized') {
                    continue;
                }
                $branches[$line['branch_id']] = bcadd($branches[$line['branch_id']] ?? '0', $line['variance_base'], 4);
            }
            if ($branches !== $original->source_snapshot['invoice_variance_by_branch']) {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }

            $basis['journal'] = self::journalSnapshot(JournalEntry::query()->where('company_id', $invoice->company_id)->find($invoice->journal_entry_id));
            $this->assertInvoiceJournal($invoice, $basis);

            return $basis;
        }
        $basis = $this->describeInvoice($invoice, true);
        if (collect($basis['lines'])->contains(fn (array $line): bool => $line['treatment'] !== 'none')) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }

        $basis['journal'] = self::journalSnapshot(JournalEntry::query()->where('company_id', $invoice->company_id)->find($invoice->journal_entry_id));
        $this->assertInvoiceJournal($invoice, $basis);

        return $basis;
    }

    /** @param array<string, mixed> $basis */
    private function assertInvoiceJournal(PurchaseInvoice $invoice, array $basis): void
    {
        $header = $basis['journal']['header'];
        if ((int) $header['company_id'] !== (int) $invoice->company_id
            || $header['source_type'] !== 'purchase_invoice' || (int) $header['source_id'] !== (int) $invoice->id
            || (int) $header['financial_period_id'] !== (int) $invoice->financial_period_id
            || (int) $header['currency_id'] !== (int) $invoice->currency_id
            || bccomp((string) $header['exchange_rate'], (string) $invoice->exchange_rate, 8) !== 0) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }
        $expected = [];
        $capitalized = [];
        foreach ($basis['lines'] as $line) {
            if ($line['treatment'] === 'capitalized') {
                $capitalized[$line['branch_id']] = bcadd($capitalized[$line['branch_id']] ?? '0', $line['variance_base'], 4);
            }
            if ($line['treatment'] !== 'ppv') {
                continue;
            }
            if (! Account::withTrashed()->forCompany((int) $invoice->company_id)
                ->where('account_type', Account::TypeExpense)->whereKey($line['ppv_account_id'])->exists()) {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }
            $key = $line['ppv_account_id'].':'.$line['branch_id'];
            $expected[$key] = bcadd($expected[$key] ?? '0', $line['variance_currency'], 4);
        }
        if ($capitalized !== []) {
            $original = InventoryValueAdjustment::query()->where('company_id', $invoice->company_id)
                ->where('source_type', PurchaseInvoice::class)->where('source_id', $invoice->id)->where('status', InventoryValueAdjustment::StatusPosted)->first();
            if ($original === null || ($original->source_snapshot['invoice_snapshot'] ?? null) !== $basis['invoice_snapshot']
                || ($original->source_snapshot['invoice_variance_by_branch'] ?? null) !== $capitalized) {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }
        }
        foreach ($expected as $key => $amount) {
            $posted = '0';
            foreach ($basis['journal']['lines'] as $line) {
                if ($key === $line['account_id'].':'.$line['branch_id']) {
                    $posted = bcadd($posted, bcsub($line['debit_amount'], $line['credit_amount'], 4), 4);
                }
            }
            if (bccomp($posted, $amount, 4) !== 0) {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }
        }
    }

    /** @return array<string, mixed> */
    public function returnLineBasis(PurchaseReturn $return, PurchaseReturnLine $line): array
    {
        $movement = InventoryTransaction::query()->where('company_id', $return->company_id)
            ->where('posting_key', 'purchase-return:'.$line->id)->lockForUpdate()->sole();
        $commercial = bcsub((string) $line->line_total, (string) $line->tax_amount, 4);
        $ppvCurrency = '0.0000';
        $ppvId = null;
        $treatment = 'unbilled';
        if ($return->purchase_invoice_id !== null) {
            $invoice = $return->purchaseInvoice;
            $basis = $this->invoiceBasis($invoice);
            $source = $basis['lines'][$line->purchase_invoice_line_id] ?? null;
            if ($source === null || $source['receipt_line_id'] !== (int) $line->receipt_line_id) {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }
            $treatment = $source['treatment'];
            if ($treatment === 'ppv') {
                $previousQuantity = '0';
                $previousAmount = '0';
                $previous = PurchaseReturnLine::query()->where('company_id', $return->company_id)
                    ->where('purchase_invoice_line_id', $line->purchase_invoice_line_id)->where('from_quarantine', false)
                    ->where('purchase_return_id', '<>', $return->id)
                    ->whereHas('purchaseReturn', fn ($query) => $query->where('status', PurchaseReturn::StatusPosted))
                    ->with('purchaseReturn')->orderBy('id')->lockForUpdate()->get();
                foreach ($previous as $credited) {
                    $previousBasis = $this->readBasis($credited->purchaseReturn, self::ReturnBasisEvent);
                    if ($previousBasis === null || ! isset($previousBasis['lines'][$credited->id])) {
                        throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
                    }
                    $previousBasis = $this->returnBasis($credited->purchaseReturn);
                    $previousQuantity = bcadd($previousQuantity, (string) $credited->quantity, 8);
                    $previousAmount = bcadd($previousAmount, $previousBasis['lines'][$credited->id]['ppv_currency'], 4);
                }
                $quantity = bcadd($previousQuantity, (string) $line->quantity, 8);
                if (bccomp($quantity, $source['quantity'], 8) > 0) {
                    throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
                }
                $target = bccomp($quantity, $source['quantity'], 8) === 0 ? $source['variance_currency']
                    : bcround(bcdiv(bcmul($source['variance_currency'], $quantity, 16), $source['quantity'], 16), 4);
                $ppvCurrency = bcsub($target, $previousAmount, 4);
                $ppvId = $source['ppv_account_id'];
            }
        }
        $rate = (string) ($return->purchaseInvoice?->exchange_rate ?? $return->purchaseOrder->exchange_rate);
        $book = (string) $movement->total_cost;
        $receipt = $line->receiptLine;

        return ['receipt_line_id' => (int) $line->receipt_line_id, 'quantity' => (string) $line->quantity,
            'treatment' => $treatment, 'ppv_currency' => $ppvCurrency, 'ppv_account_id' => $ppvId,
            'commercial_base' => bcmul($commercial, $rate, 4),
            'provisional_base' => bcmul((string) $receipt->provisional_unit_value, (string) $line->quantity, 4),
            'book_base' => $book, 'inventory_currency' => bcdiv($book, $rate, 4),
            'inventory_account_id' => (int) $this->accounts->inventoryForProduct((int) $return->company_id, $line->product, __('Purchase Return'))->id,
            'commercial_currency' => $commercial, 'exchange_rate' => $rate];
    }

    /** @return array<string, mixed> */
    public function captureReturn(PurchaseReturn $return): array
    {
        $lines = [];
        foreach ($return->lines->where('from_quarantine', false)->sortBy('id') as $line) {
            $lines[$line->id] = $this->returnLineBasis($return, $line);
        }
        $basis = self::normalize(['return_snapshot' => PurchaseReturnCostAdjustmentService::snapshot($return), 'lines' => $lines,
            'journal' => $this->returnJournalSnapshot($return)]);
        $this->saveBasis($return, self::ReturnBasisEvent, $basis);

        return $basis;
    }

    /** @return array<string, mixed> */
    public function returnBasis(PurchaseReturn $return): array
    {
        $basis = $this->readBasis($return, self::ReturnBasisEvent);
        if ($basis !== null && ($basis['return_snapshot'] !== PurchaseReturnCostAdjustmentService::snapshot($return)
            || $basis['journal'] !== $this->returnJournalSnapshot($return))) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }
        if ($basis !== null) {
            return $basis;
        }
        $lines = [];
        foreach ($return->lines->where('from_quarantine', false) as $line) {
            $lineBasis = $this->returnLineBasis($return, $line);
            if ($lineBasis['treatment'] === 'ppv') {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }
            $lines[$line->id] = $lineBasis;
        }

        return self::normalize(['return_snapshot' => PurchaseReturnCostAdjustmentService::snapshot($return), 'lines' => $lines,
            'journal' => $this->returnJournalSnapshot($return)]);
    }

    /** @return array<string, mixed> */
    private function returnJournalSnapshot(PurchaseReturn $return): array
    {
        $journalId = $return->journal_entry_id ?? $return->grni_reversal_journal_entry_id;
        $snapshot = self::journalSnapshot(JournalEntry::query()->where('company_id', $return->company_id)->find($journalId));
        if ($snapshot['header']['source_type'] !== ($return->purchase_invoice_id !== null ? 'purchase_return' : 'grni_purchase_return')
            || (int) $snapshot['header']['source_id'] !== (int) $return->id) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }

        return $snapshot;
    }

    /** @param list<int> $receiptIds @param array<string, mixed>|null $additional @return array<string, mixed> */
    public function targets(int $companyId, array $receiptIds, ?array $additional = null, ?int $excludeInvoiceId = null): array
    {
        $this->lockCompany($companyId);
        sort($receiptIds);
        $deltas = [];
        $events = [];
        foreach (UnpricedInventoryReceiptLine::query()->where('company_id', $companyId)->whereIn('id', $receiptIds)->orderBy('id')->lockForUpdate()->get() as $receipt) {
            $root = $this->root($receipt);
            $budget = '0';
            $sourceEvents = [];
            $invoices = PurchaseInvoice::query()->where('company_id', $companyId)
                ->whereIn('status', [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed])
                ->when($excludeInvoiceId !== null, fn ($query) => $query->whereKeyNot($excludeInvoiceId))
                ->whereHas('lines', fn ($query) => $query->where('receipt_line_id', $receipt->id))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($invoices as $invoice) {
                $basis = $this->invoiceBasis($invoice);
                foreach ($basis['lines'] as $line) {
                    if ($line['receipt_line_id'] === (int) $receipt->id && $line['treatment'] === 'capitalized') {
                        $budget = bcadd($budget, $line['variance_base'], 8);
                    }
                }
                $sourceEvents[] = ['invoice_id' => $invoice->id, 'basis' => $basis];
            }
            foreach ($additional['lines'] ?? [] as $line) {
                if ($line['receipt_line_id'] === (int) $receipt->id && $line['treatment'] === 'capitalized') {
                    $budget = bcadd($budget, $line['variance_base'], 8);
                }
            }
            $returned = '0';
            $returns = PurchaseReturn::query()->where('company_id', $companyId)->where('status', PurchaseReturn::StatusPosted)
                ->whereHas('lines', fn ($query) => $query->where('receipt_line_id', $receipt->id)->where('from_quarantine', false))
                ->with('lines.receiptLine')->orderBy('id')->lockForUpdate()->get();
            foreach ($returns as $return) {
                $basis = $this->returnBasis($return);
                foreach ($basis['lines'] as $line) {
                    if ($line['receipt_line_id'] !== (int) $receipt->id) {
                        continue;
                    }
                    $returned = bcadd($returned, $line['quantity'], 8);
                    if ($line['treatment'] === 'capitalized') {
                        $budget = bcsub($budget, bcsub($line['commercial_base'], $line['provisional_base'], 8), 8);
                    }
                }
                $sourceEvents[] = ['return_id' => $return->id, 'basis' => $basis];
            }
            $eligible = bcsub((string) $receipt->accepted_quantity, $returned, 8);
            if (bccomp($eligible, '0', 8) < 0 || (bccomp($eligible, '0', 8) === 0 && bccomp($budget, '0', 8) !== 0)) {
                throw new DomainException(__('purchase_invoices.messages.cost_lineage_unbalanced'));
            }
            $desired = bccomp($budget, '0', 8) === 0 ? '0.00000000'
                : bcdiv(bcmul($budget, (string) $receipt->accepted_quantity, 16), $eligible, 8);
            $own = InventoryValueAdjustmentLine::query()->where('source_transaction_id', $root->id)->where('effect', InventoryValueAdjustmentLine::EffectStock)
                ->whereHas('adjustment', fn ($query) => $query->where('company_id', $companyId)->where('status', InventoryValueAdjustment::StatusPosted)
                    ->whereIn('source_type', self::sources()))->orderBy('id')->get()
                ->reduce(fn (string $sum, $line): string => bcadd($sum, (string) $line->amount, 8), '0');
            $current = $root->completedTotalCost();
            if ($current === null || bccomp(bcsub($current, (string) $root->total_cost, 8), $own, 8) !== 0) {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }
            $deltas[$root->id] = bcsub($desired, $own, 8);
            $events[$root->id] = ['receipt_line_id' => (int) $receipt->id, 'accepted' => (string) $receipt->accepted_quantity,
                'eligible' => $eligible, 'budget' => $budget, 'current_contribution' => $own, 'sources' => $sourceEvents, 'additional' => $additional];
        }
        if (count($deltas) !== count($receiptIds)) {
            throw new DomainException(__('purchase_invoices.messages.cost_lineage_missing'));
        }

        return ['root_deltas' => $deltas, 'cost_events' => self::normalize($events)];
    }

    private function root(UnpricedInventoryReceiptLine $receipt): InventoryTransaction
    {
        $root = InventoryTransaction::query()->where('company_id', $receipt->company_id)->where('posting_key', 'purchase-receipt:'.$receipt->id)
            ->lockForUpdate()->first();
        $factor = $receipt->product_snapshot['stock_conversion_factor'] ?? null;
        if ($root === null || $factor === null || $root->source_type !== UnpricedInventoryReceipt::class
            || (int) $root->source_id !== (int) $receipt->receipt_id || $root->source_line_type !== UnpricedInventoryReceiptLine::class
            || (int) $root->source_line_id !== (int) $receipt->id || (int) $root->branch_id !== (int) $receipt->branch_id
            || (int) $root->product_id !== (int) $receipt->product_id || ! $receipt->receipt?->approved || $receipt->receipt?->posting_status !== 'posted'
            || $root->total_cost === null || bccomp((string) $root->total_cost, (string) $receipt->provisional_total_value, 4) !== 0
            || bccomp((string) $root->quantity_in, bcmul((string) $receipt->accepted_quantity, (string) $factor, 8), 8) !== 0
            || bccomp((string) InventoryReceiptLayer::query()->where('receipt_transaction_id', $root->id)->sum('original_quantity'), (string) $root->quantity_in, 8) !== 0) {
            throw new DomainException(__('purchase_invoices.messages.cost_lineage_missing'));
        }

        return $root;
    }

    /** @param array<string, mixed> $basis */
    private function saveBasis(Model $source, string $event, array $basis): void
    {
        $basis = self::normalize($basis);
        $existing = $this->readBasis($source, $event);
        if ($existing !== null && $existing !== $basis) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }
        if ($existing === null) {
            app(ProcurementAuditService::class)->record($source, $event, ['basis' => $basis, 'basis_sha256' => self::hash($basis)]);
            if ($this->readBasis($source, $event) !== $basis) {
                throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function readBasis(Model $source, string $event): ?array
    {
        $rows = DB::table('activity_log')->where('company_id', $source->company_id)->where('subject_type', $source::class)
            ->where('subject_id', $source->id)->where('event', $event)->orderBy('id')->lockForUpdate()->get(['properties']);
        if ($rows->isEmpty()) {
            return null;
        }
        if ($rows->count() !== 1) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }
        $data = json_decode($rows->first()->properties, true, flags: JSON_THROW_ON_ERROR);
        $basis = $data['basis'] ?? null;
        if (! is_array($basis) || ($data['basis_sha256'] ?? null) !== self::hash($basis)) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }

        return $basis;
    }

    /** @return array<string, mixed> */
    public static function journalSnapshot(?JournalEntry $journal): array
    {
        if ($journal === null || ! $journal->is_posted || $journal->status !== JournalEntry::StatusPosted) {
            throw new DomainException(__('purchase_invoices.messages.cost_history_missing'));
        }

        return self::normalize(['header' => $journal->only(['id', 'company_id', 'financial_period_id', 'currency_id', 'exchange_rate', 'source_type', 'source_id']),
            'lines' => $journal->lines()->orderBy('id')->get()->map(fn ($line): array => $line->only(['id', 'account_id', 'branch_id', 'cost_center_id', 'supplier_id', 'debit_amount', 'credit_amount']))->all()]);
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    public static function normalize(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $value */
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
