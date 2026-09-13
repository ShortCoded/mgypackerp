<?php

namespace Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\Production\DataTables\ProductionExecutionDataTable;
use Modules\Production\Models\ProductionOrder;

class ProductionOrderController extends Controller
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly CompanyPrintIdentityService $printIdentity,
        private readonly ReportPdfService $pdf,
    ) {}

    public function index(Request $request): View
    {
        $context = $this->context->snapshot($request);
        abort_unless($context['company_id'] && $context['financial_period_id'] && $context['branch_id'], 422, 'Operating context is required.');

        return view('modules.production.work-orders.index');
    }

    public function data(Request $request, ProductionExecutionDataTable $dataTable): JsonResponse
    {
        return $dataTable->orders($request);
    }

    public function show(Request $request, ProductionOrder $productionOrder): View
    {
        $this->assertInCurrentContext($request, $productionOrder);
        $record = $productionOrder->load(['salesOrder.branch', 'salesOrder.branchStore', 'lines.product', 'lines.unit', 'lines.stageSnapshots', 'runs.product', 'runs.stageSnapshot', 'runs.requirements.product', 'runs.inventoryDocuments']);

        return view('modules.production.work-orders.show', [
            'record' => $record,
            'relatedDocuments' => collect([
                ['label' => __('Sales Requirement / Order'), 'number' => $record->salesOrder?->doc_num, 'url' => $record->salesOrder ? route('admin.sales.sales-orders.show', $record->salesOrder) : null, 'permission' => 'sales_orders.view'],
                ...$record->runs->map(fn ($run) => ['label' => __('Production Run'), 'number' => $run->run_number, 'url' => route('admin.production.runs.show', $run), 'permission' => 'production.runs.view', 'meta' => $run->status])->all(),
                ...$record->runs->flatMap->inventoryDocuments->map(fn ($document) => ['label' => __(str($document->document_type)->replace('_', ' ')->title()->toString()), 'number' => $document->doc_num, 'url' => route('admin.inventory.documents.show', $document), 'permission' => 'inventory.documents.view', 'meta' => $document->status])->all(),
            ]),
        ]);
    }

    public function print(Request $request, ProductionOrder $productionOrder): Response
    {
        return $this->printDocument($request, $productionOrder, __('Production Order'), 'production-order');
    }

    public function printRequirement(Request $request, ProductionOrder $productionOrder): Response
    {
        return $this->printDocument($request, $productionOrder, __('Production Requirement'), 'production-requirement');
    }

    private function printDocument(Request $request, ProductionOrder $productionOrder, string $documentTitle, string $filenamePrefix): Response
    {
        $this->assertInCurrentContext($request, $productionOrder);
        $record = $productionOrder->load(['company', 'salesOrder.branch', 'salesOrder.salesEmployee', 'lines.product', 'lines.unit', 'lines.stageSnapshots']);

        return $this->pdf->stream('reports.production.order', [
            'title' => $documentTitle.' — '.$record->doc_num,
            'record' => $record,
            'companyPrintIdentity' => $record->print_identity_snapshot ?: $this->printIdentity->forCompany($record->company),
        ], str($filenamePrefix.'-'.$record->doc_num)->slug().'.pdf');
    }

    private function assertInCurrentContext(Request $request, ProductionOrder $productionOrder): void
    {
        $context = $this->context->snapshot($request);

        abort_unless(
            $context['company_id']
            && $context['financial_period_id']
            && $context['branch_id']
            && (int) $productionOrder->company_id === (int) $context['company_id']
            && (int) $productionOrder->financial_period_id === (int) $context['financial_period_id']
            && (int) $productionOrder->branch_id === (int) $context['branch_id'],
            404,
        );
    }
}
