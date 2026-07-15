<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrQualificationsDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrQualification;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrQualificationController extends HrLookupController
{
    public function data(Request $request, HrQualificationsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrQualification $qualification): View
    {
        return $this->showRecord($request, $qualification);
    }

    public function edit(HrQualification $qualification): View
    {
        return $this->editRecord($qualification);
    }

    public function clone(HrQualification $qualification): View
    {
        return $this->cloneRecord($qualification);
    }

    public function update(UpdateHrLookupRequest $request, HrQualification $qualification): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $qualification);
    }

    public function destroy(Request $request, HrQualification $qualification): JsonResponse
    {
        return $this->destroyRecord($request, $qualification);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('qualifications');
    }
}
