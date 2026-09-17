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
use Modules\Core\Models\Branch;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\Cashbox;
use Modules\HR\Http\Requests\CalculatePayrollRequest;
use Modules\HR\Http\Requests\CreatePayrollPaymentRequest;
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
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request): View
    {
        abort_unless((bool) $request->user()?->can('hr.payroll_preparation.view'), 403);
        $companyId = $this->companies->requireCompanyId($request);
        $runs = DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->leftJoin('branches as branch', 'branch.id', '=', 'run.branch_id')
            ->leftJoin('hr_payslips as payslip', 'payslip.payroll_run_id', '=', 'run.id')
            ->where('period.company_id', $companyId)
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
        $selected = $selectedRunId === null
            ? null
            : $this->guardDomain(fn (): array => $this->reconciliation->forRun($selectedRunId, $companyId, $request->string('as_of')->toString() ?: null));
        $cashboxes = Cashbox::query()
            ->forCompany($companyId)
            ->active()
            ->when($selected !== null && $selected['run']->branch_id !== null, fn ($query) => $query->where('branch_id', $selected['run']->branch_id))
            ->orderBy('name')
            ->get(['doc_num', 'name', 'branch_id']);

        return view('modules.hr.payroll.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.hr.payroll-preparation.index'),
            'runs' => $runs,
            'selected' => $selected,
            'branches' => Branch::query()->where('company_id', $companyId)->active()->orderBy('name')->get(['doc_num', 'name']),
            'cashboxes' => $cashboxes,
            'paymentIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function calculate(CalculatePayrollRequest $request): JsonResponse
    {
        $result = $this->guardDomain(fn (): array => $this->calculations->calculate(
            $this->companies->requireCompanyId($request),
            $request->validated(),
        ));

        return response()->json(['success' => true, 'message' => __('hr_payroll.messages.calculated'), 'data' => $result]);
    }

    public function review(Request $request, int $payrollRun): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('hr.payroll_approval.review'), 403);
        $companyId = $this->companies->requireCompanyId($request);
        $this->findRunOrFail($payrollRun, $companyId);
        $run = $this->guardDomain(fn (): object => $this->lifecycle->submitForReview($payrollRun, $companyId));

        return response()->json(['success' => true, 'message' => __('hr_payroll.messages.submitted_for_review'), 'data' => ['status' => $run->status]]);
    }

    public function approve(Request $request, int $payrollRun): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('hr.payroll_approval.approve'), 403);
        $companyId = $this->companies->requireCompanyId($request);
        $this->findRunOrFail($payrollRun, $companyId);
        $result = $this->guardDomain(fn (): array => $this->lifecycle->approve($payrollRun, $companyId));

        return response()->json([
            'success' => true,
            'message' => __('hr_payroll.messages.approved_and_posted'),
            'data' => ['status' => $result['run']->status, 'journal_entry_id' => $result['journal_entry_id']],
        ]);
    }

    public function storePayment(CreatePayrollPaymentRequest $request, int $payrollRun): JsonResponse
    {
        $companyId = $this->companies->requireCompanyId($request);
        $this->findRunOrFail($payrollRun, $companyId);
        $result = $this->guardDomain(fn (): array => $this->payments->createCashPayment($payrollRun, $companyId, $request->validated()));

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
        $this->findRunOrFail($payrollRun, $companyId);
        $result = $this->guardDomain(fn (): array => $this->reconciliation->forRun(
            $payrollRun,
            $companyId,
            $request->string('as_of')->toString() ?: null,
        ));

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function findRunOrFail(int $payrollRunId, int $companyId): void
    {
        abort_unless(DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as period', 'period.id', '=', 'run.payroll_period_id')
            ->where('run.id', $payrollRunId)
            ->where('period.company_id', $companyId)
            ->whereNull('run.deleted_at')
            ->whereNull('period.deleted_at')
            ->exists(), 404);
    }

    /**
     * @template TReturn
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
