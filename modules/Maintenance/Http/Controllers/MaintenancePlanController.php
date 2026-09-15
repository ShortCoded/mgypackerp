<?php

namespace Modules\Maintenance\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Maintenance\Http\Requests\StoreMaintenanceMeterReadingRequest;
use Modules\Maintenance\Http\Requests\StoreMaintenancePlanRequest;
use Modules\Maintenance\Models\MaintenancePlan;
use Modules\Maintenance\Models\MaintenancePlanDue;
use Modules\Maintenance\Services\MaintenancePlanService;
use Modules\Production\Models\ProductionMold;
use Modules\Purchases\Models\Supplier;

class MaintenancePlanController extends Controller
{
    public function __construct(private readonly OperatingContextService $context) {}

    public function index(Request $request): View
    {
        $context = $this->requiredContext($request);
        $plans = MaintenancePlan::query()
            ->forContext($context['company_id'], $context['branch_id'])
            ->with(['asset', 'mold', 'supplier', 'dues.workOrder', 'readings'])
            ->latest('id')
            ->get();
        $dues = MaintenancePlanDue::query()
            ->forContext($context['company_id'], $context['financial_period_id'], $context['branch_id'])
            ->with(['plan.asset', 'plan.mold', 'workOrder'])
            ->orderBy('due_at')
            ->get();

        return view('modules.maintenance.plans.index', [
            'plans' => $plans,
            'dues' => $dues,
            'assets' => FixedAsset::query()
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereNotIn('status', [FixedAsset::StatusDisposed, FixedAsset::StatusSold, FixedAsset::StatusWrittenOff])
                ->orderBy('asset_name')
                ->get(),
            'molds' => ProductionMold::query()
                ->where('company_id', $context['company_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereNot('status', ProductionMold::StatusUnavailable)
                ->orderBy('name')
                ->get(),
            'suppliers' => Supplier::query()->where('company_id', $context['company_id'])->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreMaintenancePlanRequest $request, MaintenancePlanService $service): RedirectResponse
    {
        try {
            $service->create($request->validated());

            return redirect()->route('admin.maintenance.plans.index')->with('success', __('maintenance.messages.plan_created'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['plan' => $exception->getMessage()]);
        }
    }

    public function approve(MaintenancePlan $maintenancePlan, MaintenancePlanService $service): JsonResponse
    {
        return $this->guard(fn () => $service->approve($maintenancePlan), __('maintenance.messages.plan_approved'));
    }

    public function generate(MaintenancePlan $maintenancePlan, MaintenancePlanService $service): JsonResponse
    {
        return $this->guard(fn () => $service->generateDue($maintenancePlan), __('maintenance.messages.plan_due_generated'));
    }

    public function recordReading(
        StoreMaintenanceMeterReadingRequest $request,
        MaintenancePlan $maintenancePlan,
        MaintenancePlanService $service,
    ): RedirectResponse {
        try {
            $service->recordReading($maintenancePlan, $request->validated());

            return redirect()->route('admin.maintenance.plans.index')->with('success', __('maintenance.messages.reading_recorded'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['reading' => $exception->getMessage()]);
        }
    }

    public function convert(MaintenancePlanDue $maintenancePlanDue, MaintenancePlanService $service): JsonResponse
    {
        try {
            $order = $service->convertDue($maintenancePlanDue);

            return response()->json([
                'success' => true,
                'status' => $order->status,
                'message' => __('maintenance.messages.plan_due_converted'),
                'redirect_url' => route('admin.maintenance.orders.show', $order),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function guard(callable $callback, string $message): JsonResponse
    {
        try {
            $record = $callback();

            return response()->json(['success' => true, 'status' => $record->status, 'message' => $message]);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(Request $request): array
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, __('maintenance.messages.operating_context_required'));

        return ['company_id' => (int) $context['company_id'], 'financial_period_id' => (int) $context['financial_period_id'], 'branch_id' => (int) $context['branch_id']];
    }
}
