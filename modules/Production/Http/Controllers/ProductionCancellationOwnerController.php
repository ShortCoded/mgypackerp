<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCancellationOwnerService;

final class ProductionCancellationOwnerController extends Controller
{
    public function __construct(private readonly ProductionCancellationOwnerService $service) {}

    public function run(ProductionRun $productionRun): View
    {
        return view('modules.production.runs.cancellation-owner', $this->service->preview($productionRun));
    }

    public function order(ProductionOrder $productionOrder): View
    {
        return view('modules.production.runs.cancellation-owner', $this->service->preview($productionOrder));
    }

    public function prepareRun(Request $request, ProductionRun $productionRun): RedirectResponse
    {
        return $this->prepare($request, $productionRun);
    }

    public function prepareOrder(Request $request, ProductionOrder $productionOrder): RedirectResponse
    {
        return $this->prepare($request, $productionOrder);
    }

    public function approveRun(ProductionRun $productionRun, int $owner): RedirectResponse
    {
        return $this->action($productionRun, $owner, true);
    }

    public function approveOrder(ProductionOrder $productionOrder, int $owner): RedirectResponse
    {
        return $this->action($productionOrder, $owner, true);
    }

    public function rejectRun(ProductionRun $productionRun, int $owner): RedirectResponse
    {
        return $this->action($productionRun, $owner, false);
    }

    public function rejectOrder(ProductionOrder $productionOrder, int $owner): RedirectResponse
    {
        return $this->action($productionOrder, $owner, false);
    }

    private function prepare(Request $request, ProductionOrder|ProductionRun $record): RedirectResponse
    {
        $data = $request->validate(['treatment' => ['required', 'in:document_error,stop_remaining,material_document_error'], 'confirmed' => ['required', 'accepted'],
            'inventory_document_id' => ['nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence' => ['required', 'string', 'min:5', 'max:2000'], 'fingerprint' => ['required', 'string', 'size:64']]);
        try {
            $this->service->prepare($record, $data);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['owner' => $exception->getMessage()]);
        }

        return $this->redirect($record);
    }

    private function action(ProductionOrder|ProductionRun $record, int $owner, bool $approve): RedirectResponse
    {
        try {
            if ($approve) {
                $this->service->approve($record, $owner);
            } else {
                $this->service->reject($record, $owner);
            }
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['owner' => $exception->getMessage()]);
        }

        return $this->redirect($record);
    }

    private function redirect(ProductionOrder|ProductionRun $record): RedirectResponse
    {
        return redirect()->route($record instanceof ProductionRun ? 'admin.production.runs.cancellation-owner' : 'admin.production.work-orders.cancellation-owner', $record);
    }
}
