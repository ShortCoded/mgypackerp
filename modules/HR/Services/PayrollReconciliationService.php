<?php

namespace Modules\HR\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\NumericFormatService;

final class PayrollReconciliationService
{
    public function __construct(private readonly NumericFormatService $numbers) {}

    /**
     * @return array{
     *     mapping_configured: bool,
     *     source_available: bool,
     *     payable_opening: string,
     *     payable_ending: string,
     *     gl_opening: string,
     *     gl_ending: string,
     *     settlement_opening: string,
     *     settlement_ending: string,
     *     cash_bank_opening: string,
     *     cash_bank_ending: string
     * }
     */
    public function forScope(int $companyId, ?int $branchId, string $openingDate, string $asOf): array
    {
        $payableAccount = $this->payrollPayableAccount($companyId);

        return [
            'mapping_configured' => $payableAccount instanceof Account,
            'source_available' => $this->hasScopeEvidence($companyId, $branchId, $asOf),
            'payable_opening' => $this->subledgerBalanceAt($companyId, $branchId, $openingDate),
            'payable_ending' => $this->subledgerBalanceAt($companyId, $branchId, $asOf),
            'gl_opening' => $payableAccount instanceof Account
                ? $this->glPayableBalance($companyId, $branchId, $openingDate, (int) $payableAccount->getKey())
                : '0.0000',
            'gl_ending' => $payableAccount instanceof Account
                ? $this->glPayableBalance($companyId, $branchId, $asOf, (int) $payableAccount->getKey())
                : '0.0000',
            'settlement_opening' => $this->settlementsAt($companyId, $branchId, $openingDate),
            'settlement_ending' => $this->settlementsAt($companyId, $branchId, $asOf),
            'cash_bank_opening' => $this->cashEffectForScope($companyId, $branchId, $openingDate),
            'cash_bank_ending' => $this->cashEffectForScope($companyId, $branchId, $asOf),
        ];
    }

    /**
     * @param  list<int>|null  $allowedPaymentPeriodIds
     * @return array{
     *     run: object,
     *     status: string,
     *     summary: array<string, string>,
     *     journal: object|null,
     *     payments: Collection<int, object>
     * }
     */
    public function forRun(
        int $payrollRunId,
        int $companyId,
        ?string $asOf = null,
        ?array $allowedPaymentPeriodIds = null,
    ): array {
        $run = $this->run($payrollRunId, $companyId);
        $asOfDate = Carbon::parse($asOf ?? now())->toDateString();
        $payableAccount = $this->payrollPayableAccount($companyId);
        $currentPayable = $this->runPayable($payrollRunId);
        $currentPaid = $this->runPaidAsOf($payrollRunId, $asOfDate, $allowedPaymentPeriodIds);
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
                'payments' => $this->payments($payrollRunId, $asOfDate, $allowedPaymentPeriodIds),
            ];
        }

        $journal = DB::table('hr_payroll_postings as posting')
            ->leftJoin('journal_entries as journal', function ($join) use ($allowedPaymentPeriodIds): void {
                $join->on('journal.id', '=', 'posting.journal_entry_id');
                if ($allowedPaymentPeriodIds !== null) {
                    $join->whereIn('journal.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]);
                }
            })
            ->where('posting.payroll_run_id', $payrollRunId)
            ->first([
                'posting.journal_entry_id',
                'posting.status as posting_status',
                'journal.doc_num',
                'journal.entry_date',
                'journal.status as journal_status',
            ]);
        $payments = $this->payments($payrollRunId, $asOfDate, $allowedPaymentPeriodIds);
        $branchId = $run->branch_id === null ? null : (int) $run->branch_id;
        $periodStart = (string) $run->period_start;
        $opening = $this->subledgerBalanceBefore($companyId, $branchId, $periodStart, $allowedPaymentPeriodIds);
        $periodPayroll = $this->periodPayroll($companyId, $branchId, $periodStart, $asOfDate);
        $periodSettlements = $this->periodSettlements($companyId, $branchId, $periodStart, $asOfDate, $allowedPaymentPeriodIds);
        $endingPayable = bcsub(bcadd($opening, $periodPayroll, 4), $periodSettlements, 4);
        $glEnding = $this->glPayableBalance(
            $companyId,
            $branchId,
            $asOfDate,
            (int) $payableAccount->getKey(),
            $allowedPaymentPeriodIds,
        );
        $glDifference = bcsub($endingPayable, $glEnding, 4);
        $cashEffect = $this->cashEffect($payrollRunId, $asOfDate, $allowedPaymentPeriodIds);
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

    /** @param list<int>|null $allowedPaymentPeriodIds */
    private function runPaidAsOf(int $payrollRunId, string $asOf, ?array $allowedPaymentPeriodIds): string
    {
        return $this->money(DB::table('hr_payroll_payments as payment')
            ->leftJoin('journal_entries as reversal', function ($join) use ($allowedPaymentPeriodIds): void {
                $join->on('reversal.id', '=', 'payment.reversal_journal_entry_id');
                if ($allowedPaymentPeriodIds !== null) {
                    $join->whereIn('reversal.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]);
                }
            })
            ->where('payment.payroll_run_id', $payrollRunId)
            ->when($allowedPaymentPeriodIds !== null, fn ($query) => $query->whereIn('payment.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]))
            ->whereNotNull('payment.approved_at')
            ->whereDate('payment.approved_at', '<=', $asOf)
            ->where(function ($query) use ($allowedPaymentPeriodIds, $asOf): void {
                $query->whereNull('payment.cancelled_at')
                    ->orWhereDate('payment.cancelled_at', '>', $asOf);
                if ($allowedPaymentPeriodIds !== null) {
                    $query->orWhere(fn ($hiddenReversal) => $hiddenReversal
                        ->whereNotNull('payment.reversal_journal_entry_id')
                        ->whereNull('reversal.id'));
                }
            })
            ->sum('payment.amount'));
    }

    /** @param list<int>|null $allowedPaymentPeriodIds */
    private function subledgerBalanceBefore(
        int $companyId,
        ?int $branchId,
        string $periodStart,
        ?array $allowedPaymentPeriodIds = null,
    ): string {
        $payroll = $this->money(DB::table('hr_payslips as payslip')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('period.company_id', $companyId)
            ->where('run.status', 'posted')
            ->whereDate('period.period_end', '<', $periodStart)
            ->when($branchId !== null, fn ($query) => $query->where('payslip.branch_id', $branchId))
            ->sum('payslip.net_amount'));
        $payments = $this->money(DB::table('hr_payroll_payments as payment')
            ->leftJoin('journal_entries as reversal', function ($join) use ($allowedPaymentPeriodIds): void {
                $join->on('reversal.id', '=', 'payment.reversal_journal_entry_id');
                if ($allowedPaymentPeriodIds !== null) {
                    $join->whereIn('reversal.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]);
                }
            })
            ->where('payment.company_id', $companyId)
            ->when($allowedPaymentPeriodIds !== null, fn ($query) => $query->whereIn('payment.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]))
            ->when($branchId !== null, fn ($query) => $query->where('payment.branch_id', $branchId))
            ->whereNotNull('payment.approved_at')
            ->whereDate('payment.approved_at', '<', $periodStart)
            ->where(function ($query) use ($allowedPaymentPeriodIds, $periodStart): void {
                $query->whereNull('payment.cancelled_at')
                    ->orWhereDate('payment.cancelled_at', '>=', $periodStart);
                if ($allowedPaymentPeriodIds !== null) {
                    $query->orWhere(fn ($hiddenReversal) => $hiddenReversal
                        ->whereNotNull('payment.reversal_journal_entry_id')
                        ->whereNull('reversal.id'));
                }
            })
            ->sum('payment.amount'));

        return bcsub($payroll, $payments, 4);
    }

    private function subledgerBalanceAt(int $companyId, ?int $branchId, string $asOf): string
    {
        $payroll = $this->money(DB::table('hr_payslips as payslip')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('period.company_id', $companyId)
            ->where('run.status', 'posted')
            ->whereDate('period.period_end', '<=', $asOf)
            ->when($branchId !== null, fn ($query) => $query->where('payslip.branch_id', $branchId))
            ->sum('payslip.net_amount'));

        return bcsub($payroll, $this->settlementsAt($companyId, $branchId, $asOf), 4);
    }

    private function settlementsAt(int $companyId, ?int $branchId, string $asOf): string
    {
        return $this->money(DB::table('hr_payroll_payments')
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNotNull('approved_at')
            ->whereDate('approved_at', '<=', $asOf)
            ->where(fn ($query) => $query->whereNull('cancelled_at')->orWhereDate('cancelled_at', '>', $asOf))
            ->sum('amount'));
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

    /** @param list<int>|null $allowedPaymentPeriodIds */
    private function periodSettlements(
        int $companyId,
        ?int $branchId,
        string $periodStart,
        string $asOf,
        ?array $allowedPaymentPeriodIds = null,
    ): string {
        $approved = $this->money(DB::table('hr_payroll_payments')
            ->where('company_id', $companyId)
            ->when($allowedPaymentPeriodIds !== null, fn ($query) => $query->whereIn('financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(approved_at)'), [$periodStart, $asOf])
            ->sum('amount'));
        $cancelled = $this->money(DB::table('hr_payroll_payments as payment')
            ->leftJoin('journal_entries as reversal', function ($join) use ($allowedPaymentPeriodIds): void {
                $join->on('reversal.id', '=', 'payment.reversal_journal_entry_id');
                if ($allowedPaymentPeriodIds !== null) {
                    $join->whereIn('reversal.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]);
                }
            })
            ->where('payment.company_id', $companyId)
            ->when($allowedPaymentPeriodIds !== null, fn ($query) => $query
                ->whereIn('payment.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0])
                ->whereNotNull('reversal.id'))
            ->when($branchId !== null, fn ($query) => $query->where('payment.branch_id', $branchId))
            ->whereBetween(DB::raw('DATE(payment.cancelled_at)'), [$periodStart, $asOf])
            ->sum('payment.amount'));

        return bcsub($approved, $cancelled, 4);
    }

    /** @param list<int>|null $allowedFinancialPeriodIds */
    private function glPayableBalance(
        int $companyId,
        ?int $branchId,
        string $asOf,
        int $accountId,
        ?array $allowedFinancialPeriodIds = null,
    ): string {
        $row = DB::table('journal_entry_lines as line')
            ->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('journal.company_id', $companyId)
            ->where('journal.status', 'posted')
            ->where('journal.is_posted', true)
            ->when($allowedFinancialPeriodIds !== null, fn ($query) => $query->whereIn('journal.financial_period_id', $allowedFinancialPeriodIds !== [] ? $allowedFinancialPeriodIds : [0]))
            ->whereDate('journal.entry_date', '<=', $asOf)
            ->whereIn('journal.source_type', ['hr_payroll_run', 'hr_payroll_payment', 'hr_payroll_payment_reversal'])
            ->where('line.account_id', $accountId)
            ->when($branchId !== null, fn ($query) => $query->where('line.branch_id', $branchId))
            ->selectRaw('COALESCE(SUM(line.credit_amount - line.debit_amount), 0) as balance')
            ->first();

        return $this->money($row?->balance ?? 0);
    }

    /** @param list<int>|null $allowedPaymentPeriodIds */
    private function cashEffect(int $payrollRunId, string $asOf, ?array $allowedPaymentPeriodIds): string
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
            ->when($allowedPaymentPeriodIds !== null, fn ($query) => $query->whereIn('payment.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]))
            ->when($allowedPaymentPeriodIds !== null, fn ($query) => $query->whereIn('journal.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]))
            ->whereDate('journal.entry_date', '<=', $asOf)
            ->where('journal.status', 'posted')
            ->selectRaw('COALESCE(SUM(line.credit_amount - line.debit_amount), 0) as balance')
            ->first();

        return $this->money($row?->balance ?? 0);
    }

    private function cashEffectForScope(int $companyId, ?int $branchId, string $asOf): string
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
            ->where('payment.company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->where('payment.branch_id', $branchId))
            ->whereDate('journal.entry_date', '<=', $asOf)
            ->where('journal.status', JournalEntry::StatusPosted)
            ->where('journal.is_posted', true)
            ->selectRaw('COALESCE(SUM(line.credit_amount - line.debit_amount), 0) as balance')
            ->first();

        return $this->money($row?->balance ?? 0);
    }

    private function hasScopeEvidence(int $companyId, ?int $branchId, string $asOf): bool
    {
        return DB::table('hr_payslips as payslip')
            ->join('hr_payroll_runs as run', 'run.id', '=', 'payslip.payroll_run_id')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('period.company_id', $companyId)
            ->where('run.status', 'posted')
            ->whereDate('period.period_end', '<=', $asOf)
            ->when($branchId !== null, fn ($query) => $query->where('payslip.branch_id', $branchId))
            ->exists();
    }

    /** @param list<int>|null $allowedPaymentPeriodIds */
    private function payments(int $payrollRunId, string $asOf, ?array $allowedPaymentPeriodIds): Collection
    {
        $effectiveStatusSql = $allowedPaymentPeriodIds === null
            ? 'payment.status'
            : "CASE WHEN payment.reversal_journal_entry_id IS NOT NULL AND reversal.id IS NULL THEN 'approved' ELSE payment.status END";
        $effectiveCancelledAtSql = $allowedPaymentPeriodIds === null
            ? 'payment.cancelled_at'
            : 'CASE WHEN payment.reversal_journal_entry_id IS NOT NULL AND reversal.id IS NULL THEN NULL ELSE payment.cancelled_at END';
        $effectiveVoucherStatusSql = $allowedPaymentPeriodIds === null
            ? 'voucher.status'
            : "CASE WHEN payment.reversal_journal_entry_id IS NOT NULL AND reversal.id IS NULL THEN 'approved' ELSE voucher.status END";

        return DB::table('hr_payroll_payments as payment')
            ->join('cash_vouchers as voucher', 'voucher.id', '=', 'payment.cash_voucher_id')
            ->leftJoin('journal_entries as journal', function ($join) use ($allowedPaymentPeriodIds): void {
                $join->on('journal.id', '=', 'payment.journal_entry_id');
                if ($allowedPaymentPeriodIds !== null) {
                    $join->whereIn('journal.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]);
                }
            })
            ->leftJoin('journal_entries as reversal', function ($join) use ($allowedPaymentPeriodIds): void {
                $join->on('reversal.id', '=', 'payment.reversal_journal_entry_id');
                if ($allowedPaymentPeriodIds !== null) {
                    $join->whereIn('reversal.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]);
                }
            })
            ->where('payment.payroll_run_id', $payrollRunId)
            ->when($allowedPaymentPeriodIds !== null, fn ($query) => $query->whereIn('payment.financial_period_id', $allowedPaymentPeriodIds !== [] ? $allowedPaymentPeriodIds : [0]))
            ->whereDate('voucher.voucher_date', '<=', $asOf)
            ->orderBy('voucher.voucher_date')
            ->orderBy('payment.id')
            ->select([
                'payment.id',
                'payment.amount',
                'payment.approved_at',
                'voucher.doc_num as voucher_doc_num',
                'voucher.voucher_date',
                'journal.doc_num as journal_doc_num',
                'reversal.doc_num as reversal_journal_doc_num',
            ])
            ->selectRaw("{$effectiveStatusSql} as status")
            ->selectRaw("{$effectiveCancelledAtSql} as cancelled_at")
            ->selectRaw("{$effectiveVoucherStatusSql} as voucher_status")
            ->get();
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
        return $this->numbers->normalizeToScale($value, 4) ?? '0.0000';
    }
}
