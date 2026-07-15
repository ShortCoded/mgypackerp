<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\PlasticsDashboardService;

class DashboardController extends Controller
{
    public function __invoke(Request $request, PlasticsDashboardService $dashboard): View
    {
        return view('dashboard', $dashboard->forRequest($request));
    }
}
