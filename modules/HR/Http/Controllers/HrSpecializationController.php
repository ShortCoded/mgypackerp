<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrSpecializationsDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrSpecialization;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrSpecializationController extends HrLookupController
{
    public function data(Request $request, HrSpecializationsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrSpecialization $specialization): View
    {
        return $this->showRecord($request, $specialization);
    }

    public function edit(HrSpecialization $specialization): View
    {
        return $this->editRecord($specialization);
    }

    public function clone(HrSpecialization $specialization): View
    {
        return $this->cloneRecord($specialization);
    }

    public function update(UpdateHrLookupRequest $request, HrSpecialization $specialization): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $specialization);
    }

    public function destroy(Request $request, HrSpecialization $specialization): JsonResponse
    {
        return $this->destroyRecord($request, $specialization);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('specializations');
    }
}
