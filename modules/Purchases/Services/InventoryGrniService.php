<?php

namespace Modules\Purchases\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseReturn;

class InventoryGrniService
{
    public function __construct(
        private readonly PostingAccountResolver $accounts,
        private readonly JournalEntryService $journals,
    ) {}

    public function postAcceptedLine(
        UnpricedInventoryReceipt $receipt,
        UnpricedInventoryReceiptLine $receiptLine,
        InventoryTransaction $movement,
    ): JournalEntry {
        $receipt->loadMissing('purchaseOrder.currency');
        $receiptLine->loadMissing(['purchaseOrderLine', 'product']);
        $order = $receipt->purchaseOrder;
        $orderLine = $receiptLine->purchaseOrderLine;

        if ($order === null || $orderLine === null || bccomp((string) $orderLine->ordered_quantity, '0', 8) <= 0) {
            throw new DomainException(__('Cannot post Goods Receipt because the approved Purchase Order value is unavailable.'));
        }

        $inventoryAccount = $this->accounts->inventoryForProduct((int) $receipt->company_id, $receiptLine->product, __('Goods Receipt'));
        $grniAccount = $this->accounts->resolve(
            (int) $receipt->company_id,
            PostingAccountResolver::GoodsReceivedNotInvoiced,
            __('Goods Receipt'),
        );
        $unitValue = bcdiv((string) $orderLine->total_before_tax, (string) $orderLine->ordered_quantity, 8);
        $provisionalValue = bcmul($unitValue, (string) $receiptLine->accepted_quantity, 4);
        $baseUnitValue = bcmul($unitValue, (string) $order->exchange_rate, 8);
        $baseValue = bcmul($provisionalValue, (string) $order->exchange_rate, 4);

        if (bccomp($provisionalValue, '0', 4) <= 0) {
            throw new DomainException(__('Cannot post Goods Receipt because its approved provisional value is zero.'));
        }

        $journal = $this->journals->createPostedFromSource([
            'entry_date' => $receipt->document_date,
            'company_id' => (int) $receipt->company_id,
            'financial_period_id' => (int) $receipt->financial_period_id,
            'branch_id' => $receipt->branch_id,
            'currency_id' => $order->currency_id,
            'exchange_rate' => $order->exchange_rate,
            'description' => __('GRNI receipt :document', ['document' => $receipt->doc_num]),
            'notes' => $receipt->notes,
            'source_type' => 'grni_receipt',
            'source_id' => $receiptLine->getKey(),
            'source_doc_num' => $receipt->doc_num,
        ], [
            [
                'account_id' => $inventoryAccount->getKey(), 'debit_amount' => $provisionalValue,
                'credit_amount' => '0.0000', 'description' => __('Provisional accepted Inventory'),
                'supplier_id' => $receipt->supplier_id, 'branch_id' => $receipt->branch_id,
            ],
            [
                'account_id' => $grniAccount->getKey(), 'debit_amount' => '0.0000',
                'credit_amount' => $provisionalValue, 'description' => __('Goods received not invoiced'),
                'supplier_id' => $receipt->supplier_id, 'branch_id' => $receipt->branch_id,
            ],
        ]);

        $movement->forceFill(['unit_cost' => bcdiv($baseValue, (string) $movement->quantity_in, 8), 'total_cost' => $baseValue])->save();
        $receiptLine->forceFill([
            'provisional_unit_value' => $baseUnitValue,
            'provisional_total_value' => $baseValue,
            'grni_journal_entry_id' => $journal->getKey(),
            'updated_by' => auth()->id(),
        ])->save();

        return $journal;
    }

    public function postAcceptedReturn(PurchaseReturn $return): ?JournalEntry
    {
        $return->loadMissing(['purchaseOrder', 'lines.receiptLine.product']);
        if ($return->purchase_invoice_id !== null) {
            return null;
        }

        $grniAccount = $this->accounts->resolve(
            (int) $return->company_id,
            PostingAccountResolver::GoodsReceivedNotInvoiced,
            __('Purchase Return'),
        );
        $posting = [];
        $total = '0.0000';

        foreach ($return->lines->where('from_quarantine', false) as $returnLine) {
            $receiptLine = $returnLine->receiptLine;
            if ($receiptLine === null) {
                throw new DomainException(__('The accepted receipt lineage is missing for the Purchase Return.'));
            }
            $value = bcmul((string) $receiptLine->provisional_unit_value, (string) $returnLine->quantity, 4);
            $available = bcsub(
                bcsub((string) $receiptLine->accepted_quantity, (string) $receiptLine->grni_cleared_quantity, 8),
                (string) $receiptLine->grni_returned_quantity,
                8,
            );
            if (bccomp((string) $returnLine->quantity, $available, 8) > 0) {
                throw new DomainException(__('Purchase Return quantity exceeds the accepted uninvoiced quantity.'));
            }

            $inventoryAccount = $this->accounts->inventoryForProduct((int) $return->company_id, $receiptLine->product, __('Purchase Return'));
            $key = (string) $inventoryAccount->getKey();
            $posting[$key] = bcadd($posting[$key] ?? '0.0000', $value, 4);
            $total = bcadd($total, $value, 4);
            $receiptLine->forceFill([
                'grni_returned_quantity' => bcadd((string) $receiptLine->grni_returned_quantity, (string) $returnLine->quantity, 8),
                'grni_returned_value' => bcadd((string) $receiptLine->grni_returned_value, $value, 4),
                'updated_by' => auth()->id(),
            ])->save();
            $returnLine->forceFill(['grni_reversed_value' => $value])->save();
        }

        if (bccomp($total, '0', 4) <= 0) {
            return null;
        }

        $rate = (string) ($return->purchaseOrder?->exchange_rate ?? 1);
        $currencyAmount = bcdiv($total, $rate, 4);
        $lines = [[
            'account_id' => $grniAccount->getKey(), 'debit_amount' => $currencyAmount,
            'credit_amount' => '0.0000', 'description' => __('GRNI accepted return'),
            'supplier_id' => $return->supplier_id, 'branch_id' => $return->branch_id,
        ]];
        foreach ($posting as $accountId => $baseValue) {
            $lines[] = [
                'account_id' => (int) $accountId, 'debit_amount' => '0.0000',
                'credit_amount' => bcdiv($baseValue, $rate, 4), 'description' => __('Provisional Inventory return'),
                'supplier_id' => $return->supplier_id, 'branch_id' => $return->branch_id,
            ];
        }

        return $this->journals->createPostedFromSource([
            'entry_date' => $return->return_date,
            'company_id' => (int) $return->company_id,
            'financial_period_id' => (int) $return->financial_period_id,
            'branch_id' => $return->branch_id,
            'currency_id' => $return->purchaseOrder?->currency_id,
            'exchange_rate' => $rate,
            'description' => __('GRNI accepted return :document', ['document' => $return->doc_num]),
            'notes' => $return->notes,
            'source_type' => 'grni_purchase_return',
            'source_id' => $return->getKey(),
            'source_doc_num' => $return->doc_num,
        ], $lines);
    }
}
