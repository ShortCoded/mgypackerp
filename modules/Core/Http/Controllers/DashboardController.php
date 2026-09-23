<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\IntendedUrlService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\PendingDecisionService;
use Modules\Core\Services\PersonalDashboardService;
use Modules\Core\Services\PlasticsDashboardService;
use Modules\HR\Services\HrAttendanceService;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        PlasticsDashboardService $dashboard,
        PersonalDashboardService $personal,
        HrAttendanceService $attendance,
        OperatingContextService $operatingContext,
        IntendedUrlService $intendedUrls,
    ): View|RedirectResponse {
        $deferredIntended = $request->session()->get(IntendedUrlService::AfterOperatingContextSessionKey);

        if (is_string($deferredIntended)) {
            $deferredIntended = $intendedUrls->sanitizeRelativeUrl($deferredIntended);

            if ($deferredIntended === null) {
                $request->session()->forget(IntendedUrlService::AfterOperatingContextSessionKey);
            } elseif (! $operatingContext->current($request)['requires_selection']) {
                $request->session()->forget(IntendedUrlService::AfterOperatingContextSessionKey);
                $intendedPath = parse_url($deferredIntended, PHP_URL_PATH);

                if (is_string($intendedPath) && $intendedPath !== route('dashboard', absolute: false)) {
                    return redirect()->to($deferredIntended);
                }
            }
        }

        return view('dashboard', [
            ...$dashboard->forRequest($request),
            'summaryFilters' => $dashboard->summaryFilterOptions($request),
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

    public function salesSummary(Request $request, PlasticsDashboardService $dashboard): JsonResponse
    {
        return response()->json($dashboard->summaryForRequest($request, 'sales'));
    }

    public function purchasesSummary(Request $request, PlasticsDashboardService $dashboard): JsonResponse
    {
        return response()->json($dashboard->summaryForRequest($request, 'purchases'));
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
