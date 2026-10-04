<?php

namespace Modules\Purchases\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OpenDocumentsService;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\PurchaseReturnLine;
use Modules\Purchases\Models\SupplierPaymentContext;

class ProcurementCorrectionPlanService
{
    /** @return list<array<string, mixed>> */
    public function forInvoice(PurchaseInvoice $invoice, Request $request): array
    {
        $invoice->loadMissing('paymentAllocations.paymentContext', 'purchaseReturns', 'paymentSchedules.cashVoucher');
        $steps = [];
        foreach ($invoice->purchaseReturns->where('company_id', $invoice->company_id)->whereNotIn('status', ['cancelled', PurchaseReturn::StatusReversed])->sortBy('id') as $return) {
            $steps[] = $this->returnStep($return, $request);
        }
        $payments = $invoice->paymentAllocations->pluck('paymentContext')->filter();
        $voucherIds = $invoice->paymentSchedules->filter(fn ($schedule): bool => (bool) $schedule->cashVoucher?->isApproved())->pluck('cash_voucher_id');
        $payments = $payments->merge(SupplierPaymentContext::query()->where('company_id', $invoice->company_id)->whereIn('cash_voucher_id', $voucherIds)->get());
        foreach ($payments->unique('id')->sortBy('id') as $payment) {
            if ((int) $payment->company_id === (int) $invoice->company_id && ! $payment->isCancelled()) {
                $steps[] = $this->step($payment, 'payment', 'admin.purchases.supplier-payments.show',
                    'supplier_payments.view', 'supplier_payments.cancel', $request, prices: true);
            }
        }
        foreach ($invoice->paymentSchedules->filter(fn ($schedule): bool => (bool) $schedule->cashVoucher?->isApproved()) as $schedule) {
            if (! SupplierPaymentContext::query()->where('cash_voucher_id', $schedule->cash_voucher_id)->exists()) {
                $step = $this->step($invoice, 'voucher', 'admin.purchases.purchase-invoices.show',
                    'cash_payment_vouchers.view', 'cash_payment_vouchers.cancel', $request, prices: true);
                $step['id'] = (int) $schedule->cash_voucher_id;
                $step['doc_num'] = $schedule->cashVoucher->doc_num;
                $step['status'] = $schedule->cashVoucher->status;
                $step['updated_at'] = $schedule->cashVoucher->updated_at?->toISOString();
                $step['permitted'] = $step['permitted'] && (bool) $request->user()?->can('purchase_invoices.reverse');
                $step['source_url'] = $step['source_url'] ? route('admin.finance.cash-payment-vouchers.show', $step['doc_num']) : null;
                $steps[] = $step;
            }
        }
        if ($invoice->reversal_journal_entry_id === null && ! $invoice->isCancelled()) {
            $steps[] = $this->step($invoice, 'invoice', 'admin.purchases.purchase-invoices.show',
                'purchase_invoices.view', 'purchase_invoices.reverse', $request, OpenDocumentsService::PurchaseInvoices, prices: true);
        }

        return $this->uniqueSteps($steps);
    }

    /** @return list<array<string, mixed>> */
    public function forReceipt(UnpricedInventoryReceipt $receipt, Request $request): array
    {
        $lineIds = $receipt->lines()->pluck('id');
        $steps = [];
        $returns = PurchaseReturnLine::query()->with('purchaseReturn')
            ->whereIn('receipt_line_id', $lineIds)->whereHas('purchaseReturn', fn ($query) => $query
            ->where('company_id', $receipt->company_id)->whereNotIn('status', ['cancelled', PurchaseReturn::StatusReversed]))
            ->get()->pluck('purchaseReturn')->unique('id')->sortBy('id');
        foreach ($returns as $return) {
            $steps[] = $this->returnStep($return, $request);
        }
        $invoices = PurchaseInvoiceLine::query()->with('purchaseInvoice')
            ->whereIn('receipt_line_id', $lineIds)->whereHas('purchaseInvoice', fn ($query) => $query
            ->where('company_id', $receipt->company_id)->whereNotIn('status', ['cancelled', 'reversed']))
            ->get()->pluck('purchaseInvoice')->unique('id')->sortBy('id');
        foreach ($invoices as $invoice) {
            array_push($steps, ...$this->forInvoice($invoice, $request));
        }
        if ($receipt->status !== UnpricedInventoryReceipt::StatusReversed) {
            $steps[] = $this->step($receipt, 'receipt', 'admin.purchases.goods-receipt-notes.show',
                'purchases.goods_receipt_notes.view', 'purchases.goods_receipt_notes.reverse', $request, OpenDocumentsService::PurchaseReceipts);
        }

        return $this->uniqueSteps($steps);
    }

    /** @return array<string, mixed> */
    private function returnStep(PurchaseReturn $return, Request $request): array
    {
        $draft = $return->status === PurchaseReturn::StatusDraft;
        $step = $this->step($return, 'return', 'admin.purchases.purchase-returns.show',
            'purchases.purchase_returns.view', $draft ? 'purchases.purchase_returns.delete' : 'purchases.purchase_returns.reverse', $request);
        $step['action'] = __('open_documents.correction_steps.'.($draft ? 'return_draft' : 'return'));
        $step['action_route'] = $draft ? 'admin.purchases.purchase-returns.destroy' : 'admin.purchases.purchase-returns.reverse';

        return $step;
    }

    /** @return array<string, mixed> */
    private function step(Model $record, string $kind, string $route, string $viewPermission, string $actionPermission,
        Request $request, ?string $documentType = null, bool $prices = false): array
    {
        $branch = Branch::query()->where('company_id', $record->company_id)->find($record->branch_id);
        $period = FinancialPeriod::query()->where('company_id', $record->company_id)->find($record->financial_period_id);
        $canView = ($request->user()?->can($viewPermission) ?? false) && (! $prices || $request->user()?->can('purchases.prices.view'));
        $canAct = ($request->user()?->can($actionPermission) ?? false) && (! $prices || $request->user()?->can('purchases.prices.view'));

        return [
            'kind' => $kind, 'id' => (int) $record->getKey(), 'doc_num' => $record->doc_num,
            'status' => $record->status, 'updated_at' => $record->updated_at?->toISOString(),
            'company_id' => (int) $record->company_id, 'branch_id' => (int) $record->branch_id,
            'financial_period_id' => (int) $record->financial_period_id,
            'context_label' => ($branch?->name ?? '').' · '.($period?->name ?? ''),
            'action' => __('open_documents.correction_steps.'.$kind), 'permitted' => (bool) $canAct,
            'source_url' => $canView ? route($route, $record->doc_num) : null,
            'correction_url' => $documentType !== null && $canAct && app(OpenDocumentsService::class)->canView($request)
                ? route('admin.tools.open-documents.index', ['document_type' => $documentType,
                    'from_number' => $record->doc_number, 'to_number' => $record->doc_number,
                    'source_period_doc_num' => $period?->doc_num]) : null,
        ];
    }

    /** @param list<array<string, mixed>> $steps @return list<array<string, mixed>> */
    private function uniqueSteps(array $steps): array
    {
        return collect($steps)->unique(fn (array $step): string => $step['kind'].':'.$step['id'])->values()->all();
    }
}
