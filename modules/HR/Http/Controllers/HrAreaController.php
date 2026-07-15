<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrAreasDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrArea;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrAreaController extends HrLookupController
{
    public function data(Request $request, HrAreasDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrArea $area): View
    {
        return $this->showRecord($request, $area);
    }

    public function edit(HrArea $area): View
    {
        return $this->editRecord($area);
    }

    public function clone(HrArea $area): View
    {
        return $this->cloneRecord($area);
    }

    public function update(UpdateHrLookupRequest $request, HrArea $area): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $area);
    }

    public function destroy(Request $request, HrArea $area): JsonResponse
    {
        return $this->destroyRecord($request, $area);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('areas');
    }
}
