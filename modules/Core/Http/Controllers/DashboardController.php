<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\PendingDecisionService;
use Modules\Core\Services\PersonalDashboardService;
use Modules\Core\Services\PlasticsDashboardService;
use Modules\HR\Services\HrAttendanceService;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PlasticsDashboardService $dashboard, PersonalDashboardService $personal, HrAttendanceService $attendance): View
    {
        return view('dashboard', [
            ...$dashboard->forRequest($request),
            'personalDashboard' => $personal->forRequest($request),
            'employeeAttendance' => $attendance->statusForUser($request->user()),
        ]);
    }

    public function data(Request $request, PersonalDashboardService $personal): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $personal->forRequest($request),
        ]);
    }

    public function pendingDecisions(
        Request $request,
        PendingDecisionService $pendingDecisions,
        OperatingContextService $operatingContext,
    ): View {
        return view('dashboard.pending-decisions', [
            'decisions' => $pendingDecisions->paginate($request->user(), $operatingContext->snapshot($request)),
        ]);
    }
}
