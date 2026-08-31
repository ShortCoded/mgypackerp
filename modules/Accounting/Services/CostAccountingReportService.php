<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;

final class CostAccountingReportService
{
    public function __construct(private readonly CostCenterRequirementPolicy $requirements) {}

    /** @return Collection<int, object> */
    public function trialBalanceByCostCenter(int $companyId, int $financialPeriodId): Collection
    {
        return $this->postedLines($companyId, $financialPeriodId)
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_entry_lines.cost_center_id')
            ->groupBy(
                'journal_entry_lines.cost_center_id', 'cost_centers.cost_center_code', 'cost_centers.name',
                'accounts.id', 'accounts.account_code', 'accounts.name', 'accounts.normal_balance'
            )
            ->orderBy('cost_centers.cost_center_code')
            ->orderBy('accounts.account_code')
            ->get([
                'journal_entry_lines.cost_center_id', 'cost_centers.cost_center_code', 'cost_centers.name as cost_center_name',
                'accounts.id as account_id', 'accounts.account_code', 'accounts.name as account_name', 'accounts.normal_balance',
                DB::raw('SUM(journal_entry_lines.debit_amount * journal_entries.exchange_rate) as debit'),
                DB::raw('SUM(journal_entry_lines.credit_amount * journal_entries.exchange_rate) as credit'),
            ]);
    }

    /** @return Collection<int, object> */
    public function costCenterLedger(int $companyId, int $financialPeriodId, int $costCenterId): Collection
    {
        $costCenterIds = $this->descendantIds($companyId, $costCenterId);

        return $this->postedLines($companyId, $financialPeriodId)
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_entry_lines.cost_center_id')
            ->whereIn('journal_entry_lines.cost_center_id', $costCenterIds)
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.doc_number')
            ->orderBy('journal_entry_lines.line_no')
            ->get([
                'journal_entries.entry_date', 'journal_entries.doc_num', 'journal_entries.source_type',
                'accounts.account_code', 'accounts.name as account_name', 'journal_entry_lines.description',
                'journal_entry_lines.debit_amount', 'journal_entry_lines.credit_amount', 'journal_entries.exchange_rate',
                'cost_centers.cost_center_code', 'cost_centers.name as cost_center_name', 'journal_entry_lines.branch_id',
            ]);
    }

    /** @return Collection<int, object> */
    public function departmentalProfitAndLoss(int $companyId, int $financialPeriodId): Collection
    {
        return $this->postedLines($companyId, $financialPeriodId)
            ->leftJoin('hr_departments', 'hr_departments.id', '=', 'journal_entry_lines.department_id')
            ->where('accounts.statement_type', 'income_statement')
            ->groupBy('journal_entry_lines.department_id', 'hr_departments.name', 'accounts.account_type')
            ->orderBy('hr_departments.name')
            ->get([
                'journal_entry_lines.department_id', 'hr_departments.name as department_name', 'accounts.account_type',
                DB::raw('SUM(journal_entry_lines.debit_amount * journal_entries.exchange_rate) as debit'),
                DB::raw('SUM(journal_entry_lines.credit_amount * journal_entries.exchange_rate) as credit'),
            ]);
    }

    /** @return Collection<int, object> */
    public function payrollCostByDepartmentAndCostCenter(int $companyId, int $financialPeriodId): Collection
    {
        return $this->postedLines($companyId, $financialPeriodId)
            ->leftJoin('hr_departments', 'hr_departments.id', '=', 'journal_entry_lines.department_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_entry_lines.cost_center_id')
            ->where('journal_entries.source_type', 'hr_payroll_run')
            ->where('journal_entry_lines.debit_amount', '>', 0)
            ->groupBy(
                'journal_entry_lines.department_id', 'hr_departments.name',
                'journal_entry_lines.cost_center_id', 'cost_centers.cost_center_code', 'cost_centers.name'
            )
            ->orderBy('hr_departments.name')
            ->orderBy('cost_centers.cost_center_code')
            ->get([
                'journal_entry_lines.department_id', 'hr_departments.name as department_name',
                'journal_entry_lines.cost_center_id', 'cost_centers.cost_center_code', 'cost_centers.name as cost_center_name',
                DB::raw('SUM(journal_entry_lines.debit_amount * journal_entries.exchange_rate) as amount'),
            ]);
    }

    /** @return Collection<int, object> */
    public function manufacturingOverheadByCostCenter(int $companyId, int $financialPeriodId): Collection
    {
        return $this->postedLines($companyId, $financialPeriodId)
            ->join('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_entry_lines.cost_center_id')
            ->whereIn('account_classifications.code', [
                'indirect_labor_cost', 'manufacturing_overhead', 'applied_manufacturing_overhead',
            ])
            ->groupBy(
                'journal_entry_lines.cost_center_id', 'cost_centers.cost_center_code', 'cost_centers.name',
                'account_classifications.code'
            )
            ->orderBy('cost_centers.cost_center_code')
            ->get([
                'journal_entry_lines.cost_center_id', 'cost_centers.cost_center_code', 'cost_centers.name as cost_center_name',
                'account_classifications.code as classification_code',
                DB::raw('SUM((journal_entry_lines.debit_amount - journal_entry_lines.credit_amount) * journal_entries.exchange_rate) as amount'),
            ]);
    }

    /** @return Collection<int, object> */
    public function unallocatedRequiredTransactions(int $companyId, int $financialPeriodId): Collection
    {
        return $this->postedLines($companyId, $financialPeriodId)
            ->join('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->whereNull('journal_entry_lines.cost_center_id')
            ->whereIn('account_classifications.code', $this->requirements->requiredClassificationCodes())
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.doc_number')
            ->get([
                'journal_entries.entry_date', 'journal_entries.doc_num', 'journal_entries.source_type',
                'accounts.account_code', 'accounts.name as account_name', 'account_classifications.code as classification_code',
                'journal_entry_lines.line_no', 'journal_entry_lines.description',
                'journal_entry_lines.debit_amount', 'journal_entry_lines.credit_amount', 'journal_entry_lines.branch_id',
            ]);
    }

    private function postedLines(int $companyId, int $financialPeriodId): Builder
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->whereNull('journal_entries.deleted_at')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entries.financial_period_id', $financialPeriodId)
            ->where('journal_entries.status', JournalEntry::StatusPosted)
            ->where('journal_entries.is_posted', true);
    }

    /** @return list<int> */
    private function descendantIds(int $companyId, int $costCenterId): array
    {
        $ids = [$costCenterId];
        $parentIds = [$costCenterId];

        while ($parentIds !== []) {
            $children = CostCenter::withTrashed()
                ->forCompany($companyId)
                ->whereIn('parent_id', $parentIds)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $ids = [...$ids, ...$children];
            $parentIds = $children;
        }

        return array_values(array_unique($ids));
    }
}
