<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrUniversitiesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrUniversity;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrUniversityController extends HrLookupController
{
    public function data(Request $request, HrUniversitiesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrUniversity $university): View
    {
        return $this->showRecord($request, $university);
    }

    public function edit(HrUniversity $university): View
    {
        return $this->editRecord($university);
    }

    public function clone(HrUniversity $university): View
    {
        return $this->cloneRecord($university);
    }

    public function update(UpdateHrLookupRequest $request, HrUniversity $university): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $university);
    }

    public function destroy(Request $request, HrUniversity $university): JsonResponse
    {
        return $this->destroyRecord($request, $university);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('universities');
    }
}
