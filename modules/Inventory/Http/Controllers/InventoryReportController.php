<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Services\InventoryReportService;

class InventoryReportController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly InventoryReportService $reports,
    ) {}

    public function index(Request $request): View
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'], 422, 'Operating context is required.');

        return view('modules.inventory.reports.index', [
            'balances' => $this->reports->balances($context['company_id'], $request->only(['branch_store_id', 'warehouse_location_id', 'product_id', 'stock_status'])),
            'reservations' => $this->reports->reservations($context['company_id'], ['status' => 'active']),
            'movements' => $this->reports->movements($context['company_id'], $request->only(['branch_store_id', 'product_id', 'transaction_type', 'from', 'to'])),
        ]);
    }
}
