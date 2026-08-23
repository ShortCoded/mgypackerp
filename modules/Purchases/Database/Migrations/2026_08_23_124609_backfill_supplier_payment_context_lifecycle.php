<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('supplier_payment_contexts as contexts')
            ->join('cash_vouchers as vouchers', 'vouchers.id', '=', 'contexts.cash_voucher_id')
            ->whereNull('contexts.doc_num')
            ->select([
                'contexts.id',
                'vouchers.voucher_date',
                'vouchers.amount',
                'vouchers.currency_id',
                'vouchers.exchange_rate',
                'vouchers.status',
                'vouchers.reason',
                'vouchers.description',
                'vouchers.approved_by',
                'vouchers.approved_at',
                'vouchers.cancelled_by',
                'vouchers.cancelled_at',
                'vouchers.cancel_reason',
            ])
            ->orderBy('contexts.id')
            ->get()
            ->each(function (object $payment): void {
                DB::table('supplier_payment_contexts')->where('id', $payment->id)->update([
                    'doc_number' => (int) $payment->id,
                    'doc_num' => 'SPAY-LEGACY-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                    'payment_method' => 'cash',
                    'payment_date' => $payment->voucher_date,
                    'amount' => $payment->amount,
                    'currency_id' => $payment->currency_id,
                    'exchange_rate' => $payment->exchange_rate,
                    'status' => $payment->status,
                    'reason' => $payment->reason,
                    'notes' => $payment->description,
                    'approved_by' => $payment->approved_by,
                    'approved_at' => $payment->approved_at,
                    'cancelled_by' => $payment->cancelled_by,
                    'cancelled_at' => $payment->cancelled_at,
                    'cancel_reason' => $payment->cancel_reason,
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('supplier_payment_contexts')
            ->where('doc_num', 'like', 'SPAY-LEGACY-%')
            ->update([
                'doc_number' => null,
                'doc_num' => null,
                'payment_method' => 'cash',
                'payment_date' => null,
                'amount' => 0,
                'currency_id' => null,
                'exchange_rate' => 1,
                'status' => 'draft',
                'reason' => null,
                'notes' => null,
                'approved_by' => null,
                'approved_at' => null,
                'cancelled_by' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
            ]);
    }
};
