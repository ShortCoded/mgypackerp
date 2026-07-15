<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrNationalitiesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrNationality;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrNationalityController extends HrLookupController
{
    public function data(Request $request, HrNationalitiesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrNationality $nationality): View
    {
        return $this->showRecord($request, $nationality);
    }

    public function edit(HrNationality $nationality): View
    {
        return $this->editRecord($nationality);
    }

    public function clone(HrNationality $nationality): View
    {
        return $this->cloneRecord($nationality);
    }

    public function update(UpdateHrLookupRequest $request, HrNationality $nationality): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $nationality);
    }

    public function destroy(Request $request, HrNationality $nationality): JsonResponse
    {
        return $this->destroyRecord($request, $nationality);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('nationalities');
    }
}
