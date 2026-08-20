<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Http\Requests\ConfirmExcelImportRequest;
use Modules\Core\Http\Requests\StoreExcelImportRequest;
use Modules\Core\Models\ExcelImportBatch;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ExcelImportService;

class ExcelImportController extends Controller
{
    public function __construct(
        private readonly ExcelImportService $imports,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request): View
    {
        $module = $this->module($request);
        $this->imports->authorize($request, $module);

        return view('modules.core.excel-imports.wizard', [
            'module' => $module,
            'definition' => $this->imports->definition($module),
            'batch' => null,
            'rows' => null,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($this->indexRoute($module), [
                ['label' => __('excel_imports.title')],
            ]),
        ]);
    }

    public function template(Request $request)
    {
        $module = $this->module($request);
        $this->imports->authorize($request, $module);
        $path = $this->imports->createTemplate($module, $request);

        return response()->download(
            $path,
            $module.'-'.$this->imports->definition($module)->templateVersion().'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function store(StoreExcelImportRequest $request): RedirectResponse
    {
        $module = $this->module($request);
        $batch = $this->imports->storeAndValidate($module, $request->file('workbook'), $request);

        return redirect()->route($this->routeName($module, 'show'), $batch->public_uuid);
    }

    public function show(Request $request, ExcelImportBatch $batch): View
    {
        $module = $this->module($request);
        abort_unless($batch->module === $module, 404);
        $review = $this->imports->review($batch, $request);

        return view('modules.core.excel-imports.wizard', [
            'module' => $module,
            'definition' => $review['definition'],
            'batch' => $review['batch'],
            'rows' => $review['rows'],
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($this->indexRoute($module), [
                ['label' => __('excel_imports.title')],
            ]),
        ]);
    }

    public function errorWorkbook(Request $request, ExcelImportBatch $batch)
    {
        $module = $this->module($request);
        abort_unless($batch->module === $module, 404);
        $path = $this->imports->errorWorkbook($batch, $request);

        return response()->download(
            $path,
            $module.'-validation-errors.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function replace(StoreExcelImportRequest $request, ExcelImportBatch $batch): RedirectResponse
    {
        $module = $this->module($request);
        abort_unless($batch->module === $module, 404);
        $replacement = $this->imports->replace($batch, $request->file('workbook'), $request);

        return redirect()->route($this->routeName($module, 'show'), $replacement->public_uuid);
    }

    public function confirm(ConfirmExcelImportRequest $request, ExcelImportBatch $batch): RedirectResponse
    {
        $module = $this->module($request);
        abort_unless($batch->module === $module, 404);
        $result = $this->imports->commit($batch, $request);

        return redirect()
            ->route($this->routeName($module, 'show'), $batch->public_uuid)
            ->with($result === null ? 'warning' : 'success', $result === null
                ? __('excel_imports.messages.revalidation_failed')
                : __('excel_imports.messages.imported', ['count' => count($result)]));
    }

    public function cancel(Request $request, ExcelImportBatch $batch): RedirectResponse
    {
        $module = $this->module($request);
        abort_unless($batch->module === $module, 404);
        $this->imports->cancel($batch, $request);

        return redirect()
            ->route($this->indexRoute($module))
            ->with('success', __('excel_imports.messages.cancelled'));
    }

    private function module(Request $request): string
    {
        return (string) $request->route('excelImportModule');
    }

    private function indexRoute(string $module): string
    {
        return $module === ExcelImportBatch::ModuleFixedAssets
            ? 'admin.fixed-assets.assets.index'
            : 'admin.products.index';
    }

    private function routeName(string $module, string $action): string
    {
        return $module === ExcelImportBatch::ModuleFixedAssets
            ? "admin.fixed-assets.assets.import.{$action}"
            : "admin.products.import.{$action}";
    }
}
