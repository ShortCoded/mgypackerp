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
     *     branch_id?: int|null,
     *     cost_center_id?: int|null
     * }  $filters
     * @return array<string, mixed>
     */
    public function accountLedger(array $filters): array
    {
        $account = Account::query()
            ->whereKey($filters['account_id'])
            ->where('company_id', $filters['company_id'])
            ->firstOrFail();
        $base = $this->baseQuery($filters)
            ->where('journal_entry_lines.account_id', $account->getKey());
        $openingRows = (clone $base)
            ->whereDate('journal_entries.entry_date', '<', $filters['from_date'])
            ->get(['journal_entry_lines.debit_amount', 'journal_entry_lines.credit_amount', 'journal_entries.exchange_rate']);
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
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_entry_lines.cost_center_id')
            ->leftJoin('branches', 'branches.id', '=', 'journal_entry_lines.branch_id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $filters['company_id'])
            ->where('journal_entries.financial_period_id', $filters['financial_period_id'])
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
        $openingSigned = $this->signedTotal($openingRows);
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

            $movements[] = [
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
            ];
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
            'period' => ['debit' => $periodDebit, 'credit' => $periodCredit],
            'ending' => ['debit' => $endingDebit, 'credit' => $endingCredit],
            'movements' => $movements,
            'generated_at' => now(),
            'generated_by' => auth()->user()?->name,
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function signedTotal(Collection $rows): string
    {
        return $rows->reduce(
            fn (string $total, object $row): string => bcadd($total, bcsub($this->baseAmount($row->debit_amount, $row->exchange_rate), $this->baseAmount($row->credit_amount, $row->exchange_rate), 4), 4),
            '0.0000',
        );
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
