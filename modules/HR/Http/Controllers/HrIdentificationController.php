<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrIdentificationsDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrIdentification;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrIdentificationController extends HrLookupController
{
    public function data(Request $request, HrIdentificationsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrIdentification $identification): View
    {
        return $this->showRecord($request, $identification);
    }

    public function edit(HrIdentification $identification): View
    {
        return $this->editRecord($identification);
    }

    public function clone(HrIdentification $identification): View
    {
        return $this->cloneRecord($identification);
    }

    public function update(UpdateHrLookupRequest $request, HrIdentification $identification): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $identification);
    }

    public function destroy(Request $request, HrIdentification $identification): JsonResponse
    {
        return $this->destroyRecord($request, $identification);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('identifications');
    }
}
