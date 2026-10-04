<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\HR\Services\PayrollCostAllocationService;

class PayrollCostPreviewController extends Controller
{
    public function __construct(
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
    ) {}

    public function __invoke(Request $request, int $payrollRun, PayrollCostAllocationService $allocations): View
    {
        $this->assertReadable($request, $payrollRun);
        $preview = $allocations->previewRun($payrollRun);

        return view('modules.hr.payroll.cost-preview', $preview);
    }

    public function recalculate(Request $request, int $payrollRun, PayrollCostAllocationService $allocations): RedirectResponse
    {
        $company = $this->assertReadable($request, $payrollRun);
        DB::transaction(function () use ($request, $payrollRun, $allocations, $company): void {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->assertReadable($request, $payrollRun);
            try {
                $allocations->recalculateStoredAllocations($payrollRun);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['allocations' => $exception->getMessage()]);
            }
        });

        return redirect()->route('admin.hr.payroll-runs.cost-preview', $payrollRun)
            ->with('success', __('hr_payroll.messages.cost_allocation_recalculated'));
    }

    private function assertReadable(Request $request, int $payrollRun): Company
    {
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $run = DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('run.id', $payrollRun)
            ->where('period.company_id', $company->getKey())
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->first(['run.branch_id', 'period.period_start', 'period.period_end']);
        abort_unless($run !== null, 404);

        if ($run->branch_id === null) {
            abort_unless($this->scope->hasUnrestrictedBranchAccess($request->user()), 404);
        } else {
            abort_unless($this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])
                ->where('branches.id', $run->branch_id)->exists(), 404);
        }

        if (! $this->scope->hasUnrestrictedFinancialPeriodAccess($request->user())) {
            abort_unless($this->scope->allowedFinancialPeriodQuery($request->user(), [(string) $company->doc_num])
                ->whereDate('financial_periods.from_date', '<=', $run->period_start)
                ->whereDate('financial_periods.to_date', '>=', $run->period_end)
                ->exists(), 404);
        }

        return $company;
    }
}
