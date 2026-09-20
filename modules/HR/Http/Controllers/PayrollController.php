<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Finance\Models\Cashbox;
use Modules\HR\Http\Requests\CalculatePayrollRequest;
use Modules\HR\Http\Requests\CreatePayrollPaymentRequest;
use Modules\HR\Services\HrLifecycleAuditLogger;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollLifecycleService;
use Modules\HR\Services\PayrollPaymentService;
use Modules\HR\Services\PayrollReconciliationService;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollCalculationService $calculations,
        private readonly PayrollLifecycleService $lifecycle,
        private readonly PayrollPaymentService $payments,
        private readonly PayrollReconciliationService $reconciliation,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingScopeAccessService $scope,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly HrLifecycleAuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        abort_unless((bool) $request->user()?->can('hr.payroll_preparation.view'), 403);
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $companyId = (int) $company->getKey();
        $branchIds = $this->allowedBranchIds($request, (string) $company->doc_num);
        $financialPeriodIds = $this->allowedFinancialPeriodIds($request, (string) $company->doc_num);
        $runs = DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->leftJoin('branches as branch', 'branch.id', '=', 'run.branch_id')
            ->leftJoin('hr_payslips as payslip', 'payslip.payroll_run_id', '=', 'run.id')
            ->where('period.company_id', $companyId)
            ->when($branchIds !== null, fn ($query) => $query->whereNotNull('run.branch_id')->whereIn('run.branch_id', $branchIds !== [] ? $branchIds : [0]))
            ->when($financialPeriodIds !== null, fn ($query) => $query->whereExists(fn ($financial) => $financial
                ->selectRaw('1')
                ->from('financial_periods as access_period')
                ->whereIn('access_period.id', $financialPeriodIds !== [] ? $financialPeriodIds : [0])
                ->whereColumn('access_period.from_date', '<=', 'period.period_start')
                ->whereColumn('access_period.to_date', '>=', 'period.period_end')
                ->whereNull('access_period.deleted_at')))
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->groupBy('run.id', 'period.id', 'branch.id')
            ->orderByDesc('period.period_end')
            ->orderByDesc('run.id')
            ->select([
                'run.id',
                'run.status',
                'run.branch_id',
                'run.calculated_at',
                'run.reviewed_at',
                'run.approved_at',
                'run.posted_at',
                'period.period_start',
                'period.period_end',
                'branch.doc_num as branch_doc_num',
                'branch.name as branch_name',
            ])
            ->selectRaw('COUNT(payslip.id) as employee_count')
            ->selectRaw('COALESCE(SUM(payslip.gross_amount), 0) as gross_amount')
            ->selectRaw('COALESCE(SUM(payslip.deduction_amount), 0) as deduction_amount')
            ->selectRaw('COALESCE(SUM(payslip.net_amount), 0) as net_amount')
            ->paginate(20)
            ->withQueryString();
        $selectedRunId = $request->integer('run') ?: ($runs->items()[0]->id ?? null);
        if ($selectedRunId !== null) {
            $this->findRunOrFail($request, $selectedRunId, $companyId, (string) $company->doc_num);
        }
        $selected = $selectedRunId === null ? null : $this->guardDomain(
            fn (): array => $this->reconciliation->forRun(
                $selectedRunId,
                $companyId,
                $request->string('as_of')->toString() ?: null,
                $financialPeriodIds,
            )
        );
        $cashboxes = Cashbox::query()
            ->forCompany($companyId)
            ->active()
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]))
            ->when($selected !== null && $selected['run']->branch_id !== null, fn ($query) => $query->where('branch_id', $selected['run']->branch_id))
            ->orderBy('name')
            ->get(['doc_num', 'name', 'branch_id']);

        return view('modules.hr.payroll.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.payroll-preparation.index'),
            'runs' => $runs,
            'selected' => $selected,
            'branches' => $this->scope->allowedBranchQuery($request->user(), [(string) $company->doc_num])->get(['branches.doc_num', 'branches.name']),
            'cashboxes' => $cashboxes,
            'paymentIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function calculate(CalculatePayrollRequest $request): JsonResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $result = $this->guardDomain(fn (): array => DB::transaction(function () use ($request, $companyId): array {
            $result = $this->calculations->calculate($companyId, $request->validated());
            $this->audit->logStrict(
                $request,
                'hr.payroll.calculated',
                $companyId,
                [
                    'payroll_run_id' => $result['run_id'],
                    'employee_count' => $result['employee_count'],
                    'period_start' => $request->validated('period_start'),
                    'period_end' => $request->validated('period_end'),
                    'branch_doc_num' => $request->validated('branch_doc_num'),
                    'status' => 'calculated',
                ],
                null,
                'payroll-run:'.$result['run_id'].':calculated',
            );

            return $result;
        }));

        return response()->json(['success' => true, 'message' => __('hr_payroll.messages.calculated'), 'data' => $result]);
    }

    public function review(Request $request, int $payrollRun): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('hr.payroll_approval.review'), 403);
        $companyId = $this->companies->requireCompanyId($request);
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $previousRun = $this->findRunOrFail($request, $payrollRun, $companyId, (string) $company->doc_num);
        $run = $this->guardDomain(fn (): object => DB::transaction(function () use ($request, $payrollRun, $companyId, $previousRun): object {
            $run = $this->lifecycle->submitForReview($payrollRun, $companyId);
            $this->audit->logStrict(
                $request,
                'hr.payroll.reviewed',
                $companyId,
                [
                    'payroll_run_id' => $payrollRun,
                    'previous_status' => $previousRun->status,
                    'status' => $run->status,
                ],
                null,
                'payroll-run:'.$payrollRun.':reviewed',
            );

            return $run;
        }));

        return response()->json(['success' => true, 'message' => __('hr_payroll.messages.submitted_for_review'), 'data' => ['status' => $run->status]]);
    }

    public function approve(Request $request, int $payrollRun): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('hr.payroll_approval.approve'), 403);
        $companyId = $this->companies->requireCompanyId($request);
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $previousRun = $this->findRunOrFail($request, $payrollRun, $companyId, (string) $company->doc_num);
        $result = $this->guardDomain(fn (): array => DB::transaction(function () use ($request, $payrollRun, $companyId, $previousRun): array {
            $result = $this->lifecycle->approve($payrollRun, $companyId);
            $properties = [
                'payroll_run_id' => $payrollRun,
                'previous_status' => $previousRun->status,
                'status' => $result['run']->status,
                'journal_entry_id' => $result['journal_entry_id'],
            ];
            $this->audit->logStrict(
                $request,
                'hr.payroll.approved',
                $companyId,
                $properties,
                null,
                'payroll-run:'.$payrollRun.':approved',
            );
            $this->audit->logStrict(
                $request,
                'hr.payroll.posted',
                $companyId,
                $properties,
                null,
                'payroll-run:'.$payrollRun.':posted',
            );

            return $result;
        }));

        return response()->json([
            'success' => true,
            'message' => __('hr_payroll.messages.approved_and_posted'),
            'data' => ['status' => $result['run']->status, 'journal_entry_id' => $result['journal_entry_id']],
        ]);
    }

    public function storePayment(CreatePayrollPaymentRequest $request, int $payrollRun): JsonResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $run = $this->findRunOrFail($request, $payrollRun, $companyId, (string) $company->doc_num);
        $this->guardPaymentScope($request, $companyId, (string) $company->doc_num, $run);
        $result = $this->guardDomain(fn (): array => DB::transaction(function () use ($request, $payrollRun, $companyId): array {
            $result = $this->payments->createCashPayment($payrollRun, $companyId, $request->validated());
            $this->audit->logStrict(
                $request,
                'hr.payroll.payment_initiated',
                $companyId,
                [
                    'payroll_run_id' => $payrollRun,
                    'payroll_payment_id' => $result['payment']->id,
                    'payment_status' => $result['payment']->status,
                    'voucher_doc_num' => $result['voucher']->doc_num,
                ],
                null,
                'payroll-payment:'.$result['payment']->id.':initiated',
            );

            return $result;
        }));

        return response()->json([
            'success' => true,
            'message' => __('hr_payroll.messages.payment_draft_created'),
            'data' => [
                'payment_id' => $result['payment']->id,
                'voucher_doc_num' => $result['voucher']->doc_num,
                'voucher_url' => route('admin.finance.cash-payment-vouchers.show', $result['voucher']->doc_num),
            ],
        ]);
    }

    public function reconcile(Request $request, int $payrollRun): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('hr.payroll_reconciliation.view'), 403);
        $companyId = $this->companies->requireCompanyId($request);
        $company = $this->companies->currentCompany($request);
        abort_unless($company !== null, 409);
        $this->findRunOrFail($request, $payrollRun, $companyId, (string) $company->doc_num);
        $allowedPaymentPeriodIds = $this->allowedFinancialPeriodIds($request, (string) $company->doc_num);
        $result = $this->guardDomain(fn (): array => $this->reconciliation->forRun(
            $payrollRun,
            $companyId,
            $request->string('as_of')->toString() ?: null,
            $allowedPaymentPeriodIds,
        ));

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function findRunOrFail(Request $request, int $payrollRunId, int $companyId, string $companyDocNum): object
    {
        $run = DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('run.id', $payrollRunId)
            ->where('period.company_id', $companyId)
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->first(['run.*', 'period.period_start', 'period.period_end']);
        abort_unless($run !== null, 404);

        if ($run->branch_id === null) {
            abort_unless($this->scope->hasUnrestrictedBranchAccess($request->user()), 404);
        } else {
            abort_unless($this->scope->allowedBranchQuery($request->user(), [$companyDocNum])
                ->where('branches.id', $run->branch_id)->exists(), 404);
        }

        if (! $this->scope->hasUnrestrictedFinancialPeriodAccess($request->user())) {
            abort_unless($this->scope->allowedFinancialPeriodQuery($request->user(), [$companyDocNum])
                ->whereDate('financial_periods.from_date', '<=', $run->period_start)
                ->whereDate('financial_periods.to_date', '>=', $run->period_end)
                ->exists(), 404);
        }

        return $run;
    }

    /** @return list<int>|null */
    private function allowedBranchIds(Request $request, string $companyDocNum): ?array
    {
        if ($this->scope->hasUnrestrictedBranchAccess($request->user())) {
            return null;
        }

        return $this->scope->allowedBranchQuery($request->user(), [$companyDocNum])
            ->pluck('branches.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return list<int>|null */
    private function allowedFinancialPeriodIds(Request $request, string $companyDocNum): ?array
    {
        if ($this->scope->hasUnrestrictedFinancialPeriodAccess($request->user())) {
            return null;
        }

        return $this->scope->allowedFinancialPeriodQuery($request->user(), [$companyDocNum])
            ->pluck('financial_periods.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    private function guardPaymentScope(Request $request, int $companyId, string $companyDocNum, object $run): void
    {
        $paymentDate = $request->string('payment_date')->toString();
        if (! $this->scope->hasUnrestrictedFinancialPeriodAccess($request->user())) {
            abort_unless($this->scope->allowedFinancialPeriodQuery($request->user(), [$companyDocNum])
                ->whereDate('financial_periods.from_date', '<=', $paymentDate)
                ->whereDate('financial_periods.to_date', '>=', $paymentDate)
                ->exists(), 404);
        }

        $cashbox = Cashbox::query()->forCompany($companyId)->active()
            ->where('doc_num', $request->string('cashbox_doc_num')->toString())
            ->firstOrFail();
        if ($cashbox->branch_id !== null) {
            abort_unless($this->scope->allowedBranchQuery($request->user(), [$companyDocNum])
                ->where('branches.id', $cashbox->branch_id)->exists(), 404);
        }
        if ($run->branch_id !== null) {
            abort_unless($cashbox->branch_id !== null && (int) $run->branch_id === (int) $cashbox->branch_id, 404);
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function guardDomain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['payroll' => $exception->getMessage()]);
        }
    }
}
