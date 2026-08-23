<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesOrder;

class CreditControlService
{
    public function __construct(private readonly SalesAmountService $amounts) {}

    public function agreementFor(SalesOrder $order): ?CustomerCommercialAgreement
    {
        return CustomerCommercialAgreement::query()
            ->where('company_id', $order->company_id)
            ->where('customer_id', $order->customer_id)
            ->where(fn ($query) => $query->whereNull('currency_id')->orWhere('currency_id', $order->currency_id))
            ->effective($order->order_date?->toDateString() ?? now()->toDateString())
            ->orderByRaw('currency_id is null')
            ->latest('effective_from')
            ->first();
    }

    /** @return array<string, mixed> */
    public function evaluate(SalesOrder $order): array
    {
        $agreement = $this->agreementFor($order);
        $snapshot = $order->agreement_snapshot ?? $agreement?->snapshot() ?? [];
        $creditLimit = (string) ($snapshot['credit_limit'] ?? '0');
        $isCash = ($snapshot['customer_type'] ?? CustomerCommercialAgreement::TypeCredit) === CustomerCommercialAgreement::TypeCash;
        $blockingEnabled = (bool) ($snapshot['blocking_enabled'] ?? true);
        $includeOpenOrders = (bool) ($snapshot['include_open_orders'] ?? true);
        $outstanding = $this->outstandingReceivables($order);
        $openOrders = $includeOpenOrders ? $this->openOrderExposure($order) : '0.0000';
        $advancePaid = $this->approvedAdvance($order);
        $requiredAdvance = (string) $order->required_advance_amount;
        $projectedExposure = $this->amounts->sum([$outstanding, $openOrders, $order->total_amount]);
        $creditExceeded = ! $isCash && $this->amounts->compare($projectedExposure, $creditLimit) > 0;
        $advanceMissing = $this->amounts->compare($advancePaid, $requiredAdvance) < 0;
        $blocked = $blockingEnabled && ($creditExceeded || $advanceMissing || ($isCash && $this->amounts->compare($advancePaid, $order->total_amount) < 0));

        return [
            'blocked' => $blocked,
            'customer_type' => $isCash ? CustomerCommercialAgreement::TypeCash : CustomerCommercialAgreement::TypeCredit,
            'credit_limit' => $creditLimit,
            'outstanding_receivables' => $outstanding,
            'open_order_exposure' => $openOrders,
            'order_amount' => (string) $order->total_amount,
            'projected_exposure' => $projectedExposure,
            'required_advance' => $requiredAdvance,
            'approved_advance' => $advancePaid,
            'credit_exceeded' => $creditExceeded,
            'advance_missing' => $advanceMissing,
            'temporary_override_allowed' => (bool) ($snapshot['temporary_override_allowed'] ?? false),
        ];
    }

    private function outstandingReceivables(SalesOrder $order): string
    {
        return (string) CustomerInvoice::query()
            ->where('company_id', $order->company_id)
            ->where('customer_id', $order->customer_id)
            ->where('posting_status', 'posted')
            ->where('document_type', CustomerInvoice::TypeInvoice)
            ->sum('remaining_amount');
    }

    private function openOrderExposure(SalesOrder $order): string
    {
        $orders = SalesOrder::query()
            ->where('company_id', $order->company_id)
            ->where('customer_id', $order->customer_id)
            ->whereKeyNot($order->getKey())
            ->whereIn('status', [SalesOrder::StatusApproved, SalesOrder::StatusPartiallyFulfilled, SalesOrder::StatusHeldCredit])
            ->withSum(['invoices as invoiced_amount' => fn ($query) => $query->where('document_type', CustomerInvoice::TypeInvoice)], 'total_amount')
            ->get();

        return $this->amounts->sum($orders->map(function (SalesOrder $openOrder): string {
            $remaining = $this->amounts->subtract($openOrder->total_amount, $openOrder->invoiced_amount ?? '0');

            return $this->amounts->compare($remaining, '0') < 0 ? '0.0000' : $remaining;
        }));
    }

    private function approvedAdvance(SalesOrder $order): string
    {
        return (string) DB::table('customer_receipts')
            ->where('sales_order_id', $order->getKey())
            ->where('receipt_type', CustomerReceipt::TypeAdvance)
            ->where('status', CustomerReceipt::StatusApproved)
            ->sum('amount');
    }
}
