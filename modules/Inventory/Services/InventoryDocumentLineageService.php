<?php

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Production\Models\ProductionMaterialRequirement;

final class InventoryDocumentLineageService
{
    /**
     * @return array<int, array{line_number: int, item_code: ?string, item_name: string, request_number: ?string, request_line: ?int, request_url: ?string, reservation_quantity: ?string, unit_name: ?string, reservation_status: ?string, requirement_line: ?int, run_number: ?string, run_url: ?string, source_label: string}>
     */
    public function forDocument(InventoryDocument $document): array
    {
        $context = app(OperatingContextService::class)->snapshot(request());
        abort_unless((int) $document->company_id === (int) ($context['company_id'] ?? 0)
            && (int) $document->branch_id === (int) ($context['branch_id'] ?? 0), 404);
        $document->loadMissing('lines');
        if ($document->lines->every(fn (InventoryDocumentLine $line): bool => $line->inventory_reservation_id === null && $line->source_line_public_id === null)) {
            return [];
        }
        $company = Company::query()->findOrFail($document->company_id);
        $periods = app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->select('financial_periods.id');
        $scope = fn (Builder|Relation $query): Builder|Relation => $query->where('company_id', $document->company_id)
            ->where('branch_id', $document->branch_id)->whereIn('financial_period_id', $periods);
        $reservations = InventoryReservation::query()->where('company_id', $document->company_id)
            ->where('branch_id', $document->branch_id)->whereIn('financial_period_id', $periods)
            ->whereIn('id', $document->lines->pluck('inventory_reservation_id')->filter())
            ->with([
                'product' => fn (Builder|Relation $query): Builder|Relation => $query->withTrashed()->where('company_id', $document->company_id),
                'productionRun' => $scope,
                'productionMaterialRequirement' => fn (Builder|Relation $query): Builder|Relation => $query->whereHas('run', $scope),
                'productionMaterialRequirement.run' => $scope,
                'productionMaterialRequirement.unit' => fn (Builder|Relation $query): Builder|Relation => $query->where('company_id', $document->company_id),
                'productionMaterialRequestLine' => fn (Builder|Relation $query): Builder|Relation => $query->whereHas('request', $scope),
                'productionMaterialRequestLine.request' => $scope,
                'orderLine' => fn (Builder|Relation $query): Builder|Relation => $query->whereHas('order', $scope),
                'orderLine.order' => $scope,
            ])->get()->keyBy('id');
        $rows = [];
        foreach ($document->lines->sortBy([['line_number', 'asc'], ['id', 'asc']]) as $line) {
            if ($line->inventory_reservation_id === null && $line->source_line_public_id === null) {
                continue;
            }
            $reservation = $reservations->get($line->inventory_reservation_id);
            if ((int) $line->company_id !== (int) $document->company_id
                || (int) $reservation?->product_id !== (int) $line->product_id
                || ($document->production_order_id && (int) $reservation?->production_order_id !== (int) $document->production_order_id)
                || (($line->production_run_id ?? $document->production_run_id) && (int) $reservation?->production_run_id !== (int) ($line->production_run_id ?? $document->production_run_id))) {
                $reservation = null;
            }
            $requirement = $reservation?->productionMaterialRequirement;
            if ((int) $requirement?->product_id !== (int) $line->product_id
                || ($reservation?->production_run_id && (int) $requirement?->production_run_id !== (int) $reservation->production_run_id)
                || ($line->source_line_type === ProductionMaterialRequirement::class
                    && (($line->source_line_id && (int) $line->source_line_id !== (int) $requirement?->id)
                        || ($line->source_line_public_id && $line->source_line_public_id !== $requirement?->public_id)))) {
                $requirement = null;
            }
            $requestLine = $reservation?->productionMaterialRequestLine;
            if ((int) $requestLine?->product_id !== (int) $line->product_id
                || ($requirement && (int) $requestLine?->production_material_requirement_id !== (int) $requirement->id)
                || ($document->production_material_request_id && (int) $requestLine?->production_material_request_id !== (int) $document->production_material_request_id)) {
                $requestLine = null;
            }
            $request = $requestLine?->request;
            $run = $requirement?->run ?? $reservation?->productionRun;
            $orderLine = $reservation?->orderLine;
            if ((int) $orderLine?->product_id !== (int) $line->product_id) {
                $orderLine = null;
            }
            $sourceNumber = $request?->doc_num ?? $run?->run_number ?? $orderLine?->order?->doc_num;
            $sourceLine = $requestLine?->line_number ?? $requirement?->line_number ?? $orderLine?->line_number;
            $product = $reservation?->product;
            $sourceLabel = $sourceNumber && $sourceLine
                ? $sourceNumber.' · '.__('Line').' '.$sourceLine.($product?->doc_num ? ' · '.$product->doc_num.' / '.$product->name : '')
                : __('inventory.movements.lineage.unavailable');
            $rows[$line->id] = [
                'line_number' => (int) $line->line_number,
                'item_code' => $product?->doc_num,
                'item_name' => $product?->name ?? __('inventory.movements.lineage.unavailable'),
                'request_number' => $request?->doc_num,
                'request_line' => $requestLine?->line_number,
                'request_url' => $request && auth()->user()?->can('production.material_requests.view') ? route('admin.production.material-requests.show', $request) : null,
                'reservation_quantity' => $reservation?->quantity,
                'unit_name' => $requirement?->unit?->name,
                'reservation_status' => $reservation?->status,
                'requirement_line' => $requirement?->line_number,
                'run_number' => $run?->run_number,
                'run_url' => $run && auth()->user()?->can('production.runs.view') ? route('admin.production.runs.show', $run) : null,
                'source_label' => $sourceLabel,
            ];
        }

        return $rows;
    }
}
