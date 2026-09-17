<?php

namespace Modules\HR\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;

final class PayrollReconciliationService
{
    /**
     * @return array{
     *     run: object,
     *     status: string,
     *     summary: array<string, string>,
     *     journal: object|null,
     *     payments: \Illuminate\Support\Collection<int, object>
     * }
     */
    public function forRun(int $payrollRunId, int $companyId, ?string $asOf = null): array
    {
        $run = $this->run($payrollRunId, $companyId);
        $asOfDate = Carbon::parse($asOf ?? now())->toDateString();
        $payableAccount = $this->payrollPayableAccount($companyId);
        $currentPayable = $this->runPayable($payrollRunId);
        $currentPaid = $this->runPaidAsOf($payrollRunId, $asOfDate);
        $currentRemaining = bcsub($currentPayable, $currentPaid, 4);
        if (! $payableAccount instanceof Account) {
            return [
                'run' => $run,
                'status' => 'missing_mapping',
                'summary' => [
                    'approved_payroll' => $currentPayable,
                    'payable' => $currentPayable,
                    'paid' => $currentPaid,
                    'remaining' => $currentRemaining,
                    'opening' => '0.0000',
                    'period_payroll_liability' => '0.0000',
                    'settlements_adjustments' => '0.0000',
                    'ending_payable' => $currentRemaining,
                    'gl_ending' => '0.0000',
                    'gl_difference' => $currentRemaining,
                    'cash_bank_effect' => '0.0000',
                    'cash_bank_difference' => $currentPaid,
                ],
                'journal' => null,
                'payments' => $this->payments($payrollRunId, $asOfDate),
            ];
        }

        $journal = DB::table('hr_payroll_postings as posting')
            ->leftJoin('journal_entries as journal', 'journal.id', '=', 'posting.journal_entry_id')
            ->where('posting.payroll_run_id', $payrollRunId)
            ->first([
                'posting.journal_entry_id',
                'posting.status as posting_status',
                'journal.doc_num',
                'journal.entry_date',
                'journal.status as journal_status',
            ]);
        $payments = $this->payments($payrollRunId, $asOfDate);
        $opening = $this->subledgerBalanceBefore($companyId, $run->branch_id, $run->period_start);
        $periodPayroll = $this->periodPayroll($companyId, $run->branch_id, $run->period_start, $asOfDate);
        $periodSettlements = $this->periodSettlements($companyId, $run->branch_id, $run->period_start, $asOfDate);
        $endingPayable = bcsub(bcadd($opening, $periodPayroll, 4), $periodSettlements, 4);
        $glEnding = $this->glPayableBalance($companyId, $run->branch_id, $asOfDate, (int) $payableAccount->getKey());
        $glDifference = bcsub($endingPayable, $glEnding, 4);
        $cashEffect = $this->cashEffect($payrollRunId, $asOfDate);
        $cashDifference = bcsub($currentPaid, $cashEffect, 4);

        $status = match (true) {
            $run->status !== 'posted' || $journal === null || $journal->journal_entry_id === null => 'insufficient_data',
            bccomp($currentPayable, '0.0000', 4) === 0 => 'insufficient_data',
            bccomp($glDifference, '0.0000', 4) === 0 && bccomp($cashDifference, '0.0000', 4) === 0 => 'matched',
            default => 'difference',
        };

        return [
            'run' => $run,
            'status' => $status,
            'summary' => [
                'approved_payroll' => $currentPayable,
                'payable' => $currentPayable,
                'paid' => $currentPaid,
                'remaining' => $currentRemaining,
                'opening' => $opening,
                'period_payroll_liability' => $periodPayroll,
                'settlements_adjustments' => $periodSettlements,
                'ending_payable' => $endingPayable,
                'gl_ending' => $glEnding,
                'gl_difference' => $glDifference,
                'cash_bank_effect' => $cashEffect,
                'cash_bank_difference' => $cashDifference,
            ],
            'journal' => $journal,
            'payments' => $payments,
        ];
    }

    private function payrollPayableAccount(int $companyId): ?Account
    {
        $classification = AccountClassification::query()->where('code', 'payroll_payable')->where('status', 'active')->first();
        if (! $classification instanceof AccountClassification) {
            return null;
        }

        return Account::query()
            ->forCompany($companyId)
            ->eligibleForDirectPosting()
            ->where('account_classification_id', $classification->getKey())
            ->orderBy('account_code')
            ->first();
    }

    private function runPayable(int $payrollRunId): string
    {
        return $this->money(DB::table('hr_payslips')->where('payroll_run_id', $payrollRunId)->sum('net_amount'));
    }

    private function runPaidAsOf(int $payrollRunId, string $asOf): string
    {
        return $this->money(DB::table('hr_payroll_payments')
            ->where('payroll_run_id', $payrollRunId)
            ->whereNotNull('approved_at')
            ->whereDate('approved_at', '<=', $asOf)
            ->where(fn ($query) => $query->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>', $asOf))
            ->sum('amount'));
    }

    private function subledgerBalanceBefore(int $companyId, ?int $branchId, string $periodStart): string
    {
        $payroll = $this->money(DB::table('hr_payslips as payslip')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('period.company_id', $companyId)
            ->where('run.status', 'posted')
            ->whereDate('period.period_end', '<', $periodStart)
            ->when($branchId !== null, fn ($query) => $query->where('payslip.branch_id', $branchId))
            ->sum('payslip.net_amount'));
        $payments = $this->money(DB::table('hr_payroll_payments')
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNotNull('approved_at')
            ->whereDate('approved_at', '<', $periodStart)
            ->where(fn ($query) => $query->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>=', $periodStart))
            ->sum('amount'));

        return bcsub($payroll, $payments, 4);
    }

    private function periodPayroll(int $companyId, ?int $branchId, string $periodStart, string $asOf): string
    {
        return $this->money(DB::table('hr_payslips as payslip')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('period.company_id', $companyId)
            ->where('run.status', 'posted')
            ->whereBetween('period.period_end', [$periodStart, $asOf])
            ->when($branchId !== null, fn ($query) => $query->where('payslip.branch_id', $branchId))
            ->sum('payslip.net_amount'));
    }

    private function periodSettlements(int $companyId, ?int $branchId, string $periodStart, string $asOf): string
    {
        $approved = $this->money(DB::table('hr_payroll_payments')
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(approved_at)'), [$periodStart, $asOf])
            ->sum('amount'));
        $cancelled = $this->money(DB::table('hr_payroll_payments')
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(cancelled_at)'), [$periodStart, $asOf])
            ->sum('amount'));

        return bcsub($approved, $cancelled, 4);
    }

    private function glPayableBalance(int $companyId, ?int $branchId, string $asOf, int $accountId): string
    {
        $row = DB::table('journal_entry_lines as line')
            ->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('journal.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->where('journal.is_posted', true)
            ->whereDate('journal.entry_date', '<=', $asOf)
            ->whereIn('journal.source_type', ['hr_payroll_run', 'hr_payroll_payment', 'hr_payroll_payment_reversal'])
            ->where('line.account_id', $accountId)
            ->when($branchId !== null, fn ($query) => $query->where('line.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(line.credit_amount - line.debit_amount), 0) as balance')
            ->first();

        return $this->money($row?->balance ?? 0);
    }

    private function cashEffect(int $payrollRunId, string $asOf): string
    {
        $row = DB::table('hr_payroll_payments as payment')
            ->join('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
            ->join('cashboxes as cashbox', 'cashbox.id', '=', 'voucher.cashbox_id')
            ->join('journal_entries as journal', function ($join): void {
                $join->on('journal.source_id', '=', 'payment.id')
                    ->whereIn('journal.source_type', ['hr_payroll_payment', 'hr_payroll_payment_reversal']);
            })
            ->join('journal_entry_lines as line', function ($join): void {
                $join->on('line.journal_entry_id', '=', 'journal.id')
                    ->on('line.account_id', '=', 'cashbox.account_id');
            })
            ->where('payment.payroll_run_id', $payrollRunId)
            ->whereDate('journal.entry_date', '<=', $asOf)
            ->where('journal.status', 'posted')
            ->selectRaw('COALESCE(SUM(line.credit_amount - line.debit_amount), 0) as balance')
            ->first();

        return $this->money($row?->balance ?? 0);
    }

    private function payments(int $payrollRunId, string $asOf): \Illuminate\Support\Collection
    {
        return DB::table('hr_payroll_payments as payment')
            ->join('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
            ->leftJoin('journal_entries as journal', 'journal.id', '=', 'payment.journal_entry_id')
            ->leftJoin('journal_entries as reversal', 'reversal.id', '=', 'payment.reversal_journal_entry_id')
            ->where('payment.payroll_run_id', $payrollRunId)
            ->whereDate('voucher.voucher_date', '<=', $asOf)
            ->orderBy('voucher.voucher_date')
            ->orderBy('payment.id')
            ->get([
                'payment.id',
                'payment.amount',
                'payment.status',
                'payment.approved_at',
                'payment.cancelled_at',
                'voucher.doc_num as voucher_doc_num',
                'voucher.voucher_date',
                'voucher.status as voucher_status',
                'journal.doc_num as journal_doc_num',
                'reversal.doc_num as reversal_journal_doc_num',
            ]);
    }

    private function run(int $payrollRunId, int $companyId): object
    {
        return DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('run.id', $payrollRunId)
            ->where('period.company_id', $companyId)
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->first(['run.*', 'period.company_id', 'period.period_start', 'period.period_end'])
            ?? throw new DomainException(__('hr_payroll.messages.run_not_found'));
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
