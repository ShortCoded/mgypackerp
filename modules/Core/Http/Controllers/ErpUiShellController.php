<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ErpUi\ErpUiScreenDefinition;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\ErpUi\ErpUiShellOverviewService;

class ErpUiShellController
{
    public function __construct(
        private readonly ErpUiScreenRegistry $screens,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ErpUiShellOverviewService $overviews,
    ) {}

    public function index(Request $request): View
    {
        $screen = $this->screen($request);

        return view('modules.ui-shell.index', [
            'definition' => $screen,
            'screen' => $screen->toArray(),
            'overview' => $this->overviews->for($screen, $request),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($screen->route('index')),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->screen($request);

        return response()->json([
            'draw' => max(0, $request->integer('draw')),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, 'create');
    }

    public function show(Request $request, string $doc_num): View
    {
        return $this->form($request, 'view', $doc_num);
    }

    public function edit(Request $request, string $doc_num): View
    {
        return $this->form($request, 'edit', $doc_num);
    }

    public function clone(Request $request, string $doc_num): View
    {
        return $this->form($request, 'clone', $doc_num);
    }

    private function form(Request $request, string $mode, ?string $docNum = null): View
    {
        $screen = $this->screen($request);

        abort_unless($screen->supportsMode($mode), 404);

        return view('modules.ui-shell.form', [
            'definition' => $screen,
            'screen' => $screen->toArray(),
            'mode' => $mode,
            'docNum' => $docNum,
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute($screen->route('index')),
        ]);
    }

    private function screen(Request $request): ErpUiScreenDefinition
    {
        $key = (string) $request->route('erp_ui_screen', '');
        $screen = $this->screens->find($key);

        abort_unless($screen instanceof ErpUiScreenDefinition, 404);

        return $screen;
    }
}
