<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrFacultiesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrFaculty;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrFacultyController extends HrLookupController
{
    public function data(Request $request, HrFacultiesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrFaculty $faculty): View
    {
        return $this->showRecord($request, $faculty);
    }

    public function edit(HrFaculty $faculty): View
    {
        return $this->editRecord($faculty);
    }

    public function clone(HrFaculty $faculty): View
    {
        return $this->cloneRecord($faculty);
    }

    public function update(UpdateHrLookupRequest $request, HrFaculty $faculty): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $faculty);
    }

    public function destroy(Request $request, HrFaculty $faculty): JsonResponse
    {
        return $this->destroyRecord($request, $faculty);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('faculties');
    }
}
