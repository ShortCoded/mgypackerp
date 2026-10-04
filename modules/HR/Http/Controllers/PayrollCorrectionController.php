<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\HR\Http\Requests\ProposePayrollCorrectionRequest;
use Modules\HR\Services\PayrollCorrectionService;

final class PayrollCorrectionController extends Controller
{
    public function __construct(private readonly PayrollCorrectionService $corrections) {}

    public function index(int $payrollRun): View
    {
        return view('modules.hr.payroll.correction', $this->corrections->preview($payrollRun));
    }

    public function store(ProposePayrollCorrectionRequest $request, int $payrollRun): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        try {
            $proposal = $this->corrections->propose($payrollRun, $data['reversal_date'], $data['reason'], $data['fingerprint'], $data['correction_mode'] ?? PayrollCorrectionService::ModeOriginalPeriod);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $payrollRun, ['correction_id' => $proposal->id, 'status' => $proposal->status], 'prepared');
    }

    public function approve(Request $request, int $payrollRun, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $proposal = $this->corrections->approve($payrollRun, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $payrollRun, ['correction_id' => $proposal->id, 'journal_entry_id' => $proposal->reversal_journal_entry_id, 'status' => $proposal->status], 'approved');
    }

    public function reject(Request $request, int $payrollRun, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $this->corrections->reject($payrollRun, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $payrollRun, ['correction_id' => $correction, 'status' => 'rejected'], 'rejected');
    }

    /** @param array<string, mixed> $data */
    private function response(Request $request, int $runId, array $data, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'data' => $data, 'message' => __('hr_payroll_correction.'.$message)]);
        }

        return redirect()->route('admin.hr.payroll-runs.corrections.index', $runId)->with('success', __('hr_payroll_correction.'.$message));
    }
}
