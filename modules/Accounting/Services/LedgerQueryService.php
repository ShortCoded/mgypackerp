<?php

namespace Modules\Accounting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Currency;
use Modules\Core\Services\NumericFormatService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\HR\Models\HrEmployee;
use Modules\Sales\Models\CustomerReceipt;

class LedgerQueryService
{
    public function __construct(private readonly NumericFormatService $numbers) {}

    /**
     * @param  array{
     *     company_id: int,
     *     financial_period_id: int,
     *     account_id: int,
     *     from_date: string,
     *     to_date: string,
     *     all_periods?: bool,
     *     branch_id?: int|null,
     *     cost_center_id?: int|null
     * }  $filters
     * @return array<string, mixed>
     */
    public function accountLedger(array $filters): array
    {
        $account = Account::query()
            ->withTrashed()
            ->whereKey($filters['account_id'])
            ->where('company_id', $filters['company_id'])
            ->firstOrFail();
        $base = $this->baseQuery($filters)
            ->where('journal_entry_lines.account_id', $account->getKey());
        $openingRows = (clone $base)
            ->whereDate('journal_entries.entry_date', '<', $filters['from_date'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.doc_number')
            ->orderBy('journal_entry_lines.line_no')
            ->orderBy('journal_entry_lines.id')
            ->get($this->movementColumns());
        $movementRows = (clone $base)
            ->whereDate('journal_entries.entry_date', '>=', $filters['from_date'])
            ->whereDate('journal_entries.entry_date', '<=', $filters['to_date'])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.doc_number')
            ->orderBy('journal_entry_lines.line_no')
            ->orderBy('journal_entry_lines.id')
            ->get($this->movementColumns());

        return $this->result($account, $filters, $openingRows, $movementRows);
    }

    /**
     * @param  array{
     *     company_id: int,
     *     financial_period_id: int,
     *     from_date: string,
     *     to_date: string,
     *     all_periods?: bool,
     *     account_id?: int|null,
     *     branch_id?: int|null,
     *     cost_center_id?: int|null
     * }  $filters
     * @return array<string, mixed>
     */
    public function generalJournal(array $filters): array
    {
        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_entry_lines.cost_center_id')
            ->leftJoin('branches', 'branches.id', '=', 'journal_entry_lines.branch_id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $filters['company_id'])
            ->when(! ($filters['all_periods'] ?? false), fn (Builder $query): Builder => $query->where('journal_entries.financial_period_id', $filters['financial_period_id']))
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->whereDate('journal_entries.entry_date', '>=', $filters['from_date'])
            ->whereDate('journal_entries.entry_date', '<=', $filters['to_date'])
            ->when($filters['account_id'] ?? null, fn (Builder $query, int $accountId): Builder => $query->where('journal_entry_lines.account_id', $accountId))
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId): Builder => $query->where(function (Builder $query) use ($branchId): void {
                $query->where('journal_entry_lines.branch_id', $branchId)
                    ->orWhere(function (Builder $query) use ($branchId): void {
                        $query->whereNull('journal_entry_lines.branch_id')
                            ->where('journal_entries.branch_id', $branchId);
                    });
            }))
            ->when($filters['cost_center_id'] ?? null, fn (Builder $query, int $costCenterId): Builder => $query->where('journal_entry_lines.cost_center_id', $costCenterId))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.doc_number')
            ->orderBy('journal_entry_lines.line_no')
            ->orderBy('journal_entry_lines.id')
            ->get([
                'journal_entries.entry_date',
                'journal_entries.doc_num',
                'journal_entries.reference_no',
                'journal_entries.source_type',
                'journal_entries.source_doc_num',
                'journal_entries.exchange_rate',
                'journal_entries.description as entry_description',
                'journal_entry_lines.line_no',
                'journal_entry_lines.description as line_description',
                'journal_entry_lines.debit_amount',
                'journal_entry_lines.credit_amount',
                'accounts.doc_num as account_doc_num',
                'accounts.account_code',
                'accounts.name as account_name',
                'accounts.name_en as account_name_en',
                'cost_centers.doc_num as cost_center_doc_num',
                'cost_centers.name as cost_center_name',
                'branches.doc_num as branch_doc_num',
                'branches.name as branch_name',
            ]);

        $totalDebit = '0.0000';
        $totalCredit = '0.0000';
        $movements = $rows->map(function (object $row) use (&$totalDebit, &$totalCredit): array {
            $debit = $this->baseAmount($row->debit_amount, $row->exchange_rate);
            $credit = $this->baseAmount($row->credit_amount, $row->exchange_rate);
            $totalDebit = bcadd($totalDebit, $debit, 4);
            $totalCredit = bcadd($totalCredit, $credit, 4);
            $accountName = app()->getLocale() === 'en' && filled($row->account_name_en)
                ? $row->account_name_en
                : $row->account_name;

            return [
                'entry_date' => CarbonImmutable::parse($row->entry_date)->toDateString(),
                'doc_num' => (string) $row->doc_num,
                'reference_no' => $row->reference_no,
                'source_type' => $row->source_type,
                'source_doc_num' => $row->source_doc_num,
                'line_no' => (int) $row->line_no,
                'account_doc_num' => (string) $row->account_doc_num,
                'account' => trim(implode(' / ', array_filter([$row->account_code, $accountName]))),
                'description' => $row->line_description ?: $row->entry_description,
                'cost_center' => trim(implode(' / ', array_filter([$row->cost_center_doc_num, $row->cost_center_name]))),
                'branch' => trim(implode(' / ', array_filter([$row->branch_doc_num, $row->branch_name]))),
                'debit' => $debit,
                'credit' => $credit,
            ];
        })->all();

        return [
            'currency' => Currency::query()
                ->forCompany($filters['company_id'])
                ->active()
                ->where('is_main', true)
                ->first(['doc_num', 'code', 'name'])?->only(['doc_num', 'code', 'name']),
            'filters' => $filters,
            'totals' => ['debit' => $totalDebit, 'credit' => $totalCredit],
            'movements' => $movements,
            'generated_at' => now(),
            'generated_by' => auth()->user()?->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $customerReceiptsTable = (new CustomerReceipt)->getTable();
        $employeesTable = (new HrEmployee)->getTable();
        $cashboxesTable = (new Cashbox)->getTable();
        $bankAccountsTable = (new BankAccount)->getTable();

        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_entry_lines.cost_center_id')
            ->leftJoin('branches', 'branches.id', '=', 'journal_entry_lines.branch_id')
            ->leftJoin("{$customerReceiptsTable} as ledger_customer_receipts", function ($join): void {
                $join->on('ledger_customer_receipts.journal_entry_id', '=', 'journal_entries.id')
                    ->orOn('ledger_customer_receipts.reversal_journal_entry_id', '=', 'journal_entries.id');
            })
            ->leftJoin("{$employeesTable} as ledger_collection_employees", 'ledger_collection_employees.id', '=', 'ledger_customer_receipts.received_by_employee_id')
            ->leftJoin("{$cashboxesTable} as ledger_collection_cashboxes", 'ledger_collection_cashboxes.id', '=', 'ledger_customer_receipts.cashbox_id')
            ->leftJoin("{$bankAccountsTable} as ledger_collection_bank_accounts", 'ledger_collection_bank_accounts.id', '=', 'ledger_customer_receipts.bank_account_id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $filters['company_id'])
            ->when(! ($filters['all_periods'] ?? false), fn (Builder $query): Builder => $query->where('journal_entries.financial_period_id', $filters['financial_period_id']))
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true)
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId): Builder => $query->where(function (Builder $query) use ($branchId): void {
                $query->where('journal_entry_lines.branch_id', $branchId)
                    ->orWhere(function (Builder $query) use ($branchId): void {
                        $query->whereNull('journal_entry_lines.branch_id')
                            ->where('journal_entries.branch_id', $branchId);
                    });
            }))
            ->when($filters['cost_center_id'] ?? null, fn (Builder $query, int $costCenterId): Builder => $query->where('journal_entry_lines.cost_center_id', $costCenterId));
    }

    /**
     * @return list<string>
     */
    private function movementColumns(): array
    {
        return [
            'journal_entries.entry_date',
            'journal_entries.doc_num',
            'journal_entries.reference_no',
            'journal_entries.source_type',
            'journal_entries.source_doc_num',
            'journal_entries.exchange_rate',
            'journal_entries.description as entry_description',
            'journal_entry_lines.line_no',
            'journal_entry_lines.description as line_description',
            'journal_entry_lines.debit_amount',
            'journal_entry_lines.credit_amount',
            'cost_centers.doc_num as cost_center_doc_num',
            'cost_centers.name as cost_center_name',
            'branches.doc_num as branch_doc_num',
            'branches.name as branch_name',
            'ledger_collection_employees.doc_num as collection_employee_doc_num',
            'ledger_collection_employees.name as collection_employee_name',
            'ledger_collection_employees.full_name as collection_employee_full_name',
            'ledger_customer_receipts.payment_method as collection_method',
            'ledger_customer_receipts.reference_no as collection_reference',
            'ledger_collection_cashboxes.doc_num as collection_cashbox_doc_num',
            'ledger_collection_cashboxes.name as collection_cashbox_name',
            'ledger_collection_bank_accounts.doc_num as collection_bank_doc_num',
            'ledger_collection_bank_accounts.account_name as collection_bank_name',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, object>  $openingRows
     * @param  Collection<int, object>  $movementRows
     * @return array<string, mixed>
     */
    private function result(Account $account, array $filters, Collection $openingRows, Collection $movementRows): array
    {
        $openingSigned = '0.0000';
        $openingMovements = [];

        foreach ($openingRows as $row) {
            $debit = $this->baseAmount($row->debit_amount, $row->exchange_rate);
            $credit = $this->baseAmount($row->credit_amount, $row->exchange_rate);
            $openingSigned = bcadd($openingSigned, bcsub($debit, $credit, 4), 4);
            [$runningDebit, $runningCredit] = $this->splitSigned($openingSigned);

            $openingMovements[] = $this->movement($row, $debit, $credit, $runningDebit, $runningCredit);
        }

        $runningSigned = $openingSigned;
        $periodDebit = '0.0000';
        $periodCredit = '0.0000';
        $movements = [];

        foreach ($movementRows as $row) {
            $debit = $this->baseAmount($row->debit_amount, $row->exchange_rate);
            $credit = $this->baseAmount($row->credit_amount, $row->exchange_rate);
            $runningSigned = bcadd($runningSigned, bcsub($debit, $credit, 4), 4);
            $periodDebit = bcadd($periodDebit, $debit, 4);
            $periodCredit = bcadd($periodCredit, $credit, 4);
            [$runningDebit, $runningCredit] = $this->splitSigned($runningSigned);

            $movements[] = $this->movement($row, $debit, $credit, $runningDebit, $runningCredit);
        }

        [$openingDebit, $openingCredit] = $this->splitSigned($openingSigned);
        [$endingDebit, $endingCredit] = $this->splitSigned($runningSigned);

        return [
            'account' => [
                'doc_num' => $account->doc_num,
                'account_code' => $account->account_code,
                'name' => $account->displayName(),
                'normal_balance' => $account->normal_balance,
            ],
            'currency' => Currency::query()
                ->forCompany($filters['company_id'])
                ->active()
                ->where('is_main', true)
                ->first(['doc_num', 'code', 'name'])?->only(['doc_num', 'code', 'name']),
            'filters' => $filters,
            'opening' => ['debit' => $openingDebit, 'credit' => $openingCredit],
            'opening_movements' => $openingMovements,
            'period' => ['debit' => $periodDebit, 'credit' => $periodCredit],
            'ending' => ['debit' => $endingDebit, 'credit' => $endingCredit],
            'movements' => $movements,
            'generated_at' => now(),
            'generated_by' => auth()->user()?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movement(object $row, string $debit, string $credit, string $runningDebit, string $runningCredit): array
    {
        return [
            'entry_date' => CarbonImmutable::parse($row->entry_date)->toDateString(),
            'doc_num' => (string) $row->doc_num,
            'reference_no' => $row->reference_no,
            'source_type' => $row->source_type,
            'source_doc_num' => $row->source_doc_num,
            'line_no' => (int) $row->line_no,
            'description' => $row->line_description ?: $row->entry_description,
            'debit' => $debit,
            'credit' => $credit,
            'running_debit' => $runningDebit,
            'running_credit' => $runningCredit,
            'cost_center' => trim(implode(' / ', array_filter([$row->cost_center_doc_num, $row->cost_center_name]))),
            'branch' => trim(implode(' / ', array_filter([$row->branch_doc_num, $row->branch_name]))),
            'collector' => trim(implode(' / ', array_filter([
                $row->collection_employee_doc_num,
                $row->collection_employee_full_name ?: $row->collection_employee_name,
            ]))),
            'collection_method' => $row->collection_method,
            'collection_reference' => $row->collection_reference,
            'collection_account' => trim(implode(' / ', array_filter([
                $row->collection_cashbox_doc_num ?: $row->collection_bank_doc_num,
                $row->collection_cashbox_name ?: $row->collection_bank_name,
            ]))),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitSigned(string $amount): array
    {
        return bccomp($amount, '0', 4) >= 0
            ? [$amount, '0.0000']
            : ['0.0000', bcmul($amount, '-1', 4)];
    }

    private function scale(mixed $amount): string
    {
        return $this->numbers->normalizeToScale($amount, 4) ?? '0.0000';
    }

    private function baseAmount(mixed $amount, mixed $exchangeRate): string
    {
        $rate = $this->numbers->normalizeToScale($exchangeRate, 6) ?? '1.000000';

        return bcmul($this->scale($amount), $rate, 4);
    }
}
