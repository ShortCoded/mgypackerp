<?php

namespace Modules\Inventory\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Http\Requests\StoreInventoryMovementCorrectionRequest;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Services\InventoryMovementCorrectionService;

final class InventoryMovementCorrectionController
{
    public function __construct(private readonly InventoryMovementCorrectionService $corrections) {}

    public function index(InventoryDocument $inventoryDocument): View
    {
        try {
            $plan = $this->corrections->preview($inventoryDocument);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return view('modules.inventory.documents.corrections', $plan);
    }

    public function store(StoreInventoryMovementCorrectionRequest $request, InventoryDocument $inventoryDocument): JsonResponse|RedirectResponse
    {
        try {
            $proposal = $this->corrections->prepare($inventoryDocument, $request->validated());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $inventoryDocument, $proposal);
    }

    public function approve(Request $request, InventoryDocument $inventoryDocument, int $correction): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['approval_reason' => ['required', 'string', 'max:3000']]);
        try {
            $proposal = $this->corrections->approve($inventoryDocument, $correction, $data['approval_reason']);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $this->response($request, $inventoryDocument, $proposal);
    }

    public function reject(Request $request, InventoryDocument $inventoryDocument, int $correction): JsonResponse|RedirectResponse
    {
        try {
            $this->corrections->reject($inventoryDocument, $correction);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['success' => true])
            : redirect()->route('admin.inventory.documents.corrections.index', $inventoryDocument)->with('success', __('inventory_correction.rejected'));
    }

    private function response(Request $request, InventoryDocument $document, InventoryMovementCorrection $proposal): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['proposal_id' => $proposal->id, 'status' => $proposal->status, 'replacement_document_id' => $proposal->replacement_document_id]])
            : redirect()->route('admin.inventory.documents.corrections.index', $document)->with('success', __('inventory_correction.'.$proposal->status));
    }
}
