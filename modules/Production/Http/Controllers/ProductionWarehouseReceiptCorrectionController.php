<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Services\ProductionWarehouseReceiptCorrectionService;

class ProductionWarehouseReceiptCorrectionController extends Controller
{
    public function __construct(private readonly ProductionWarehouseReceiptCorrectionService $service) {}

    public function index(InventoryDocument $inventoryDocument): View
    {
        return view('modules.production.handovers.receipt-correction', $this->service->preview($inventoryDocument));
    }

    public function store(Request $request, InventoryDocument $inventoryDocument): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000'], 'fingerprint' => ['required', 'string', 'size:64'],
            'posting_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        try {
            $proposal = $this->service->prepare($inventoryDocument, $data['reason'], $data['fingerprint'], $data['posting_date']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->respond($request, $inventoryDocument, $proposal->id, $proposal->status);
    }

    public function approve(Request $request, InventoryDocument $inventoryDocument, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $proposal = $this->service->approve($inventoryDocument, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->respond($request, $inventoryDocument, $proposal->id, $proposal->status);
    }

    private function respond(Request $request, InventoryDocument $document, int $id, string $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['success' => true, 'correction_id' => $id, 'status' => $status])
            : redirect()->route('admin.inventory.production-receipts.corrections.index', $document)->with('success', __('Saved successfully.'));
    }
}
