<?php

namespace Modules\HR\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\HR\Services\HrFoundationRegistry;
use Modules\HR\Services\HrSelect2Service;

class HrSelect2Controller extends Controller
{
    public function __construct(
        private readonly HrSelect2Service $select2,
        private readonly HrFoundationRegistry $hrFoundationRegistry,
    ) {}

    public function lookup(Request $request, string $resource): JsonResponse
    {
        if (! $this->canUseHrSelect2($request)) {
            return $this->forbiddenSelect2Response();
        }

        return response()->json($this->select2->lookup($resource, $request));
    }

    public function foundation(Request $request, string $resource): JsonResponse
    {
        if (! $this->canUseHrSelect2($request)
            && ! ($this->canUseHrReportSelect2($request)
                && in_array($resource, ['departments', 'sections', 'jobs', 'employment-types'], true))) {
            return $this->forbiddenSelect2Response();
        }

        return response()->json($this->select2->foundation($resource, $request));
    }

    public function employees(Request $request): JsonResponse
    {
        if (! $this->canUseHrSelect2($request) && ! $this->canUseHrReportSelect2($request)) {
            return $this->forbiddenSelect2Response();
        }

        return response()->json($this->select2->employees($request));
    }

    private function forbiddenSelect2Response(): JsonResponse
    {
        return response()->json([
            'message' => __('auth.forbidden'),
            'results' => [],
            'pagination' => ['more' => false],
        ], 403);
    }

    private function canUseHrSelect2(Request $request): bool
    {
        $user = $request->user();

        foreach ([
            'hr.employees.view',
            'hr.employees.create',
            'hr.employees.edit',
            'hr.shift_assignments.view',
            'hr.shift_assignments.manage',
            'hr.employee_attendance.view',
            'hr.attendance_report.view',
            'hr.employee_attendance.correct',
            'hr.employee_attendance.import',
        ] as $permission) {
            if ($user?->can($permission)) {
                return true;
            }
        }

        foreach ($this->hrFoundationRegistry->all() as $definition) {
            foreach (['view', 'create', 'edit'] as $suffix) {
                if ($user?->can($definition->permission($suffix))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function canUseHrReportSelect2(Request $request): bool
    {
        foreach (['hr.employee_reports.view', 'hr.leave_reports.view', 'hr.payroll_reports.view', 'hr.payroll_payment_reports.view'] as $permission) {
            if ($request->user()?->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
