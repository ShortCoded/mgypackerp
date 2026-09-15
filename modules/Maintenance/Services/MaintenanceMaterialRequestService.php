<?php

namespace Modules\Maintenance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenanceMaterialRequestLine;
use Modules\Maintenance\Models\MaintenanceWorkOrder;

class MaintenanceMaterialRequestService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $context,
        private readonly InventoryMovementService $movements,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(MaintenanceWorkOrder $workOrder, array $data): MaintenanceMaterialRequest
    {
        return DB::transaction(function () use ($workOrder, $data): MaintenanceMaterialRequest {
            $context = $this->requiredContext();
            $lockedOrder = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            $this->assertContext($lockedOrder, $context);
            if (in_array($lockedOrder->status, [MaintenanceWorkOrder::StatusCompleted, MaintenanceWorkOrder::StatusClosed, MaintenanceWorkOrder::StatusCancelled], true)) {
                throw new DomainException(__('maintenance.messages.material_request_order_closed'));
            }

            $store = BranchStore::query()->where('branch_id', $context['branch_id'])->lockForUpdate()->findOrFail($data['branch_store_id']);
            $productIds = collect($data['lines'])->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->unique();
            $products = Product::query()->where('company_id', $context['company_id'])->where('status', 'active')->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== $productIds->count()) {
                throw new DomainException(__('maintenance.messages.material_product_invalid'));
            }

            $numbers = $this->documents->nextForCompany(
                'maintenance_material_requests',
                MaintenanceMaterialRequest::class,
                $context['company_id'],
                fn ($query) => $query->where('financial_period_id', $context['financial_period_id']),
            );
            $request = MaintenanceMaterialRequest::query()->create([
                ...$numbers,
                ...$context,
                'maintenance_work_order_id' => $lockedOrder->getKey(),
                'branch_store_id' => $store->getKey(),
                'request_date' => now()->toDateString(),
                'status' => MaintenanceMaterialRequest::StatusSubmitted,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'created_by' => auth()->id(),
            ]);

            foreach (array_values($data['lines']) as $index => $line) {
                $product = $products->get((int) $line['product_id']);
                $request->lines()->create([
                    'line_number' => $index + 1,
                    'product_id' => $product->getKey(),
                    'unit_id' => $product->item_unit_id,
                    'item_type' => $line['item_type'],
                    'requested_quantity' => $line['quantity'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $request->refresh()->load(['workOrder.asset', 'store', 'lines.product', 'lines.unit']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(MaintenanceMaterialRequest $request, array $data): MaintenanceMaterialRequest
    {
        return DB::transaction(function () use ($request, $data): MaintenanceMaterialRequest {
            $context = $this->requiredContext();
            $locked = MaintenanceMaterialRequest::query()->with(['lines', 'workOrder'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== MaintenanceMaterialRequest::StatusSubmitted
                || $locked->inventory_issue_document_id !== null
                || $locked->inventory_return_document_id !== null) {
                throw new DomainException(__('maintenance.messages.material_request_not_editable'));
            }

            $workOrder = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($data['maintenance_work_order_id']);
            $this->assertContext($workOrder, $context);
            if (in_array($workOrder->status, [MaintenanceWorkOrder::StatusCompleted, MaintenanceWorkOrder::StatusClosed, MaintenanceWorkOrder::StatusCancelled], true)) {
                throw new DomainException(__('maintenance.messages.material_request_order_closed'));
            }

            $store = BranchStore::query()->where('branch_id', $context['branch_id'])->lockForUpdate()->findOrFail($data['branch_store_id']);
            $productIds = collect($data['lines'])->pluck('product_id')->map(fn (mixed $id): int => (int) $id)->unique();
            $products = Product::query()->where('company_id', $context['company_id'])->where('status', 'active')->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== $productIds->count()) {
                throw new DomainException(__('maintenance.messages.material_product_invalid'));
            }

            $locked->update([
                'maintenance_work_order_id' => $workOrder->getKey(),
                'branch_store_id' => $store->getKey(),
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => auth()->id(),
            ]);
            $locked->lines()->delete();
            foreach (array_values($data['lines']) as $index => $line) {
                $product = $products->get((int) $line['product_id']);
                $locked->lines()->create([
                    'line_number' => $index + 1,
                    'product_id' => $product->getKey(),
                    'unit_id' => $product->item_unit_id,
                    'item_type' => $line['item_type'],
                    'requested_quantity' => $line['quantity'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $locked->refresh()->load(['workOrder.asset', 'workOrder.mold', 'store', 'lines.product', 'lines.unit']);
        });
    }

    public function approve(MaintenanceMaterialRequest $request): MaintenanceMaterialRequest
    {
        return DB::transaction(function () use ($request): MaintenanceMaterialRequest {
            $locked = $this->lockedRequest($request, [MaintenanceMaterialRequest::StatusSubmitted]);
            foreach ($locked->lines as $line) {
                $line->update(['approved_quantity' => $line->requested_quantity]);
            }
            $locked->update([
                'status' => MaintenanceMaterialRequest::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load('lines.product');
        });
    }

    public function issue(MaintenanceMaterialRequest $request): InventoryDocument
    {
        return DB::transaction(function () use ($request): InventoryDocument {
            $locked = $this->lockedRequest($request, [MaintenanceMaterialRequest::StatusApproved]);
            $document = $this->movements->createAndPost(
                $this->movementHeader($locked, InventoryDocument::TypeAdjustmentOut, __('maintenance.inventory_reasons.issue')),
                $locked->lines->map(fn ($line): array => [
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'quantity' => (string) $line->approved_quantity,
                    'source_line_type' => $line::class,
                    'source_line_id' => $line->getKey(),
                    'notes' => __('maintenance.messages.issued_for_order', ['number' => $locked->workOrder->doc_num]),
                ])->all(),
            );

            foreach ($locked->lines as $line) {
                $line->update(['issued_quantity' => $line->approved_quantity]);
            }
            $locked->update([
                'status' => MaintenanceMaterialRequest::StatusIssued,
                'issued_by' => auth()->id(),
                'issued_at' => now(),
                'inventory_issue_document_id' => $document->getKey(),
                'updated_by' => auth()->id(),
            ]);

            return $document;
        });
    }

    public function returnUnused(MaintenanceMaterialRequest $request): InventoryDocument
    {
        return DB::transaction(function () use ($request): InventoryDocument {
            $locked = $this->lockedRequest($request, [MaintenanceMaterialRequest::StatusIssued, MaintenanceMaterialRequest::StatusPartiallyReturned]);
            $lines = $locked->lines->map(function ($line): ?array {
                $remaining = bcsub(
                    bcsub((string) $line->issued_quantity, (string) $line->consumed_quantity, 8),
                    (string) $line->returned_quantity,
                    8,
                );

                return bccomp($remaining, '0', 8) > 0 ? [
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'quantity' => $remaining,
                    'source_line_type' => $line::class,
                    'source_line_id' => $line->getKey(),
                ] : null;
            })->filter()->values();

            if ($lines->isEmpty()) {
                throw new DomainException(__('maintenance.messages.no_material_to_return'));
            }

            $document = $this->movements->createAndPost(
                $this->movementHeader($locked, InventoryDocument::TypeAdjustmentIn, __('maintenance.inventory_reasons.return')),
                $lines->all(),
            );
            foreach ($locked->lines as $line) {
                $remaining = bcsub(
                    bcsub((string) $line->issued_quantity, (string) $line->consumed_quantity, 8),
                    (string) $line->returned_quantity,
                    8,
                );
                if (bccomp($remaining, '0', 8) > 0) {
                    $line->update(['returned_quantity' => bcadd((string) $line->returned_quantity, $remaining, 8)]);
                }
            }
            $allReturned = $locked->lines->every(fn ($line): bool => bccomp(
                bcadd((string) $line->consumed_quantity, (string) $line->returned_quantity, 8),
                (string) $line->issued_quantity,
                8,
            ) === 0);
            $locked->update([
                'status' => $allReturned ? MaintenanceMaterialRequest::StatusReturned : MaintenanceMaterialRequest::StatusPartiallyReturned,
                'inventory_return_document_id' => $document->getKey(),
                'updated_by' => auth()->id(),
            ]);

            return $document;
        });
    }

    /**
     * @param  list<array{line_id: int, consumed_quantity: numeric-string|int|float}>  $usage
     */
    public function recordConsumption(MaintenanceWorkOrder $workOrder, array $usage): void
    {
        $usageByLine = collect($usage)->keyBy(fn (array $line): int => (int) $line['line_id']);
        $lines = MaintenanceMaterialRequestLine::query()
            ->whereHas('request', fn ($query) => $query->where('maintenance_work_order_id', $workOrder->getKey()))
            ->where('issued_quantity', '>', 0)
            ->lockForUpdate()
            ->get();

        foreach ($lines as $line) {
            $unreturned = bcsub((string) $line->issued_quantity, (string) $line->returned_quantity, 8);
            if (bccomp($unreturned, '0', 8) <= 0) {
                continue;
            }

            $input = $usageByLine->get((int) $line->getKey());
            if (! is_array($input)) {
                throw new DomainException(__('maintenance.messages.material_usage_required'));
            }

            $consumed = bcadd((string) $input['consumed_quantity'], '0', 8);
            if (bccomp($consumed, '0', 8) < 0 || bccomp($consumed, $unreturned, 8) > 0) {
                throw new DomainException(__('maintenance.messages.material_usage_exceeds_issued'));
            }

            $line->update(['consumed_quantity' => $consumed]);
        }

        if ($usageByLine->keys()->diff($lines->modelKeys())->isNotEmpty()) {
            throw new DomainException(__('maintenance.messages.material_usage_outside_order'));
        }
    }

    /** @param list<string> $statuses */
    private function lockedRequest(MaintenanceMaterialRequest $request, array $statuses): MaintenanceMaterialRequest
    {
        $context = $this->requiredContext();
        $locked = MaintenanceMaterialRequest::query()->with(['lines', 'workOrder'])->lockForUpdate()->findOrFail($request->getKey());
        $this->assertContext($locked, $context);
        if (! in_array($locked->status, $statuses, true)) {
            throw new DomainException(__('maintenance.messages.material_request_invalid_state'));
        }

        return $locked;
    }

    /** @return array<string, mixed> */
    private function movementHeader(MaintenanceMaterialRequest $request, string $type, string $reason): array
    {
        return [
            'company_id' => $request->company_id,
            'financial_period_id' => $request->financial_period_id,
            'branch_id' => $request->branch_id,
            'branch_store_id' => $request->branch_store_id,
            'document_type' => $type,
            'document_date' => now()->toDateString(),
            'purpose' => $reason,
            'movement_reason' => $reason,
            'source_stock_status' => InventoryTransaction::StatusAvailable,
            'destination_stock_status' => InventoryTransaction::StatusAvailable,
            'source_document_type' => MaintenanceMaterialRequest::class,
            'source_document_id' => $request->getKey(),
            'source_doc_num' => $request->doc_num,
            'notes' => $request->reason,
        ];
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(): array
    {
        $context = $this->context->snapshot(request());
        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $context['branch_id']) {
            throw new DomainException(__('maintenance.messages.operating_context_required'));
        }

        return ['company_id' => (int) $context['company_id'], 'financial_period_id' => (int) $context['financial_period_id'], 'branch_id' => (int) $context['branch_id']];
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function assertContext(object $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id']
            || (int) $record->financial_period_id !== $context['financial_period_id']
            || (int) $record->branch_id !== $context['branch_id']) {
            throw new DomainException(__('maintenance.messages.document_outside_context'));
        }
    }
}
