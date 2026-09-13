<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\PlasticsDashboardService;
use Modules\HR\Services\HrAttendanceService;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PlasticsDashboardService $dashboard, HrAttendanceService $attendance): View
    {
        return view('dashboard', [
            ...$dashboard->forRequest($request),
            'employeeAttendance' => $attendance->statusForUser($request->user()),
        ]);
    }
}
