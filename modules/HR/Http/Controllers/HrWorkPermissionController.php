<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrWorkPermissionsDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrWorkPermission;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrWorkPermissionController extends HrLookupController
{
    public function data(Request $request, HrWorkPermissionsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrWorkPermission $workPermission): View
    {
        return $this->showRecord($request, $workPermission);
    }

    public function edit(HrWorkPermission $workPermission): View
    {
        return $this->editRecord($workPermission);
    }

    public function clone(HrWorkPermission $workPermission): View
    {
        return $this->cloneRecord($workPermission);
    }

    public function update(UpdateHrLookupRequest $request, HrWorkPermission $workPermission): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $workPermission);
    }

    public function destroy(Request $request, HrWorkPermission $workPermission): JsonResponse
    {
        return $this->destroyRecord($request, $workPermission);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('work-permissions');
    }
}
