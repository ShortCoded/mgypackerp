<?php

namespace Modules\Sales\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;
use Modules\Sales\Models\SalesReturnCorrection;

final class SalesBalanceProjectionService
{
    /**
     * Current counters retain any explicitly entered legacy opening balance. Only
     * documented canonical events are moved across the requested business cutoff.
     * This projection never manufactures receipt or credit history for that balance.
     */
    public function invoicesAt(int $companyId, string $date): Builder
    {
        $cutoff = $this->cutoff($date);
        $paid = 'i.paid_amount + '.$this->receiptDelta($cutoff, false);
        $credited = 'i.credited_amount + '.$this->directCreditDelta($cutoff, false).' + '.$this->allocationDelta($cutoff, false);
        $source = DB::table('customer_invoices as i')->where('i.company_id', $companyId)
            ->select($this->columns('customer_invoices', 'i', ['paid_amount', 'credited_amount', 'remaining_amount']))
            ->selectRaw("($paid) as paid_amount, ($credited) as credited_amount, (i.total_amount - ($paid) - ($credited)) as remaining_amount");

        return CustomerInvoice::query()->fromSub($source, 'customer_invoices');
    }

    public function schedulesAt(int $companyId, string $date): QueryBuilder
    {
        $cutoff = $this->cutoff($date);
        $collected = 's.collected_amount + '.$this->receiptDelta($cutoff, true);
        $credited = 's.credited_amount + '.$this->directCreditDelta($cutoff, true).' + '.$this->allocationDelta($cutoff, true);
        $source = DB::table('customer_invoice_payment_schedules as s')->join('customer_invoices as i', 'i.id', '=', 's.customer_invoice_id')
            ->where('i.company_id', $companyId)
            ->select($this->columns('customer_invoice_payment_schedules', 's', ['collected_amount', 'credited_amount']))
            ->selectRaw("($collected) as collected_amount, ($credited) as credited_amount");

        return DB::query()->fromSub($source, 'customer_invoice_payment_schedules');
    }

    /** Document membership only; historical available/allocated credit counters are not selected. */
    public function activeCreditDocumentIdsAt(int $companyId, string $date): Builder
    {
        $cutoff = $this->cutoff($date);

        return CustomerInvoice::query()->where('company_id', $companyId)->where('document_type', CustomerInvoice::TypeCreditNote)
            ->whereRaw($this->creditActiveAt('customer_invoices', $cutoff))->select('customer_invoices.id');
    }

    public function assertCorrectionEvidence(int $companyId): void
    {
        app(CustomerReceiptApplicationHistoryService::class)->assertEvidence($companyId);
        CustomerInvoiceCorrection::query()->where('company_id', $companyId)->where('status', 'approved')->orderBy('id')
            ->chunkById(100, function ($proposals): void {
                foreach ($proposals as $proposal) {
                    app(CustomerInvoiceCorrectionService::class)->assertApproved($proposal);
                }
            });
        SalesReturnCorrection::query()->where('company_id', $companyId)->where('status', 'approved')->orderBy('id')
            ->chunkById(100, function ($proposals): void {
                foreach ($proposals as $proposal) {
                    app(SalesReturnCorrectionService::class)->assertApproved($proposal);
                }
            });
    }

    /** @param list<string> $except @return list<string> */
    private function columns(string $table, string $alias, array $except): array
    {
        return array_values(array_map(fn (string $column): string => "$alias.$column", array_diff(Schema::getColumnListing($table), $except)));
    }

    private function cutoff(string $date): string
    {
        return DB::getPdo()->quote(CarbonImmutable::parse($date)->toDateString());
    }

    private function scalar(string $column, string $path): string
    {
        return DB::getQueryGrammar()->wrap($column.'->'.$path);
    }

    private function creditActiveAt(string $alias, string $cutoff): string
    {
        return "DATE($alias.invoice_date) <= $cutoff AND ($alias.posting_status = 'posted' OR ($alias.posting_status = 'reversed' AND EXISTS
            (SELECT 1 FROM journal_entries AS inverse WHERE inverse.id = $alias.reversal_journal_entry_id
             AND inverse.company_id = $alias.company_id AND inverse.branch_id = $alias.branch_id
             AND inverse.currency_id = $alias.currency_id AND inverse.status = 'posted' AND inverse.is_posted = TRUE
             AND inverse.deleted_at IS NULL AND DATE(inverse.entry_date) > $cutoff)))";
    }

    private function directCreditDelta(string $cutoff, bool $schedule): string
    {
        $amount = 'CAST(COALESCE('.$this->scalar('c.credit_application_snapshot', 'applied_to_original').", '0') AS DECIMAL(24,4))";
        if ($schedule) {
            $json = DB::getDriverName() === 'pgsql'
                ? "jsonb_array_elements(COALESCE(c.credit_application_snapshot::jsonb -> 'schedules', '[]'::jsonb)) AS application(value)"
                : "json_each(c.credit_application_snapshot, '$.schedules') AS application";
            $id = $this->scalar('application.value', 'schedule_id');
            $rowAmount = $this->scalar('application.value', 'amount');
            $amount = "(SELECT COALESCE(SUM(CAST($rowAmount AS DECIMAL(24,4))), 0) FROM $json WHERE CAST($id AS BIGINT) = s.id)";
        }
        $active = $this->creditActiveAt('c', $cutoff);

        return "(SELECT COALESCE(SUM(($amount) * ((CASE WHEN $active THEN 1 ELSE 0 END) - (CASE WHEN c.posting_status = 'posted' THEN 1 ELSE 0 END))), 0)
            FROM customer_invoices AS c WHERE c.original_invoice_id = i.id AND c.company_id = i.company_id AND c.branch_id = i.branch_id
            AND c.currency_id = i.currency_id AND c.customer_id = i.customer_id AND c.document_type = 'credit_note' AND c.deleted_at IS NULL)";
    }

    private function allocationDelta(string $cutoff, bool $schedule): string
    {
        $date = $this->scalar('a.reversal_effect_snapshot', 'reversal_date');
        $scope = $schedule ? ' AND a.target_payment_schedule_id = s.id' : '';

        return "(SELECT COALESCE(SUM(a.amount * ((CASE WHEN DATE(a.allocation_date) <= $cutoff AND
            (a.status = 'applied' OR (a.status = 'reversed' AND DATE(COALESCE(CAST(plan.posting_date AS VARCHAR(30)), $date, SUBSTR(CAST(a.reversed_at AS VARCHAR(30)), 1, 10))) > $cutoff))
            THEN 1 ELSE 0 END) - (CASE WHEN a.status = 'applied' THEN 1 ELSE 0 END))), 0)
            FROM customer_credit_allocations AS a JOIN customer_invoices AS credit ON credit.id = a.credit_note_id
            LEFT JOIN sales_return_corrections AS plan ON plan.allocation_id = a.id AND plan.operation = 'allocation' AND plan.status = 'approved'
                AND plan.company_id = a.company_id
            WHERE a.target_invoice_id = i.id AND a.company_id = i.company_id AND credit.company_id = i.company_id
                AND credit.customer_id = i.customer_id $scope)";
    }

    private function receiptDelta(string $cutoff, bool $schedule): string
    {
        $scope = $schedule ? ' AND application.customer_invoice_payment_schedule_id = s.id' : '';
        $eventScope = $schedule ? ' AND event.schedule_id = s.id' : '';
        $history = "(SELECT COALESCE(SUM(event.amount * ((CASE WHEN DATE(event.posting_date) <= $cutoff THEN 1 ELSE 0 END) - 1)), 0)
            FROM customer_receipt_application_events AS event JOIN customer_receipts AS owner ON owner.id = event.receipt_id
            WHERE event.invoice_id = i.id AND event.company_id = i.company_id AND event.branch_id = i.branch_id
                AND event.currency_id = i.currency_id AND owner.customer_id = i.customer_id $eventScope)";

        return "$history + (SELECT COALESCE(SUM(application.allocated_amount * ((CASE WHEN application.applied_at IS NOT NULL
            AND DATE(COALESCE(original.entry_date, receipt.receipt_date)) <= $cutoff
            AND (receipt.status = 'approved' OR (inverse.status = 'posted' AND inverse.is_posted = TRUE AND inverse.deleted_at IS NULL
                AND DATE(inverse.entry_date) > $cutoff)) THEN 1 ELSE 0 END)
            - (CASE WHEN application.applied_at IS NOT NULL AND receipt.status = 'approved' THEN 1 ELSE 0 END))), 0)
            FROM customer_receipt_allocations AS application JOIN customer_receipts AS receipt ON receipt.id = application.customer_receipt_id
            LEFT JOIN journal_entries AS original ON original.id = receipt.journal_entry_id AND original.company_id = receipt.company_id
                AND original.branch_id = receipt.branch_id AND original.currency_id = receipt.currency_id AND original.deleted_at IS NULL
            LEFT JOIN journal_entries AS inverse ON inverse.id = original.reversed_entry_id AND inverse.id = receipt.reversal_journal_entry_id
                AND inverse.company_id = receipt.company_id AND inverse.branch_id = receipt.branch_id AND inverse.currency_id = receipt.currency_id
            WHERE application.customer_invoice_id = i.id AND receipt.company_id = i.company_id AND receipt.branch_id = i.branch_id
                AND receipt.currency_id = i.currency_id AND receipt.customer_id = i.customer_id AND receipt.deleted_at IS NULL $scope
                AND NOT EXISTS (SELECT 1 FROM customer_receipt_application_events AS recorded WHERE recorded.allocation_id = application.id))";
    }
}
