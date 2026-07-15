<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrReligionsDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrReligion;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrReligionController extends HrLookupController
{
    public function data(Request $request, HrReligionsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrReligion $religion): View
    {
        return $this->showRecord($request, $religion);
    }

    public function edit(HrReligion $religion): View
    {
        return $this->editRecord($religion);
    }

    public function clone(HrReligion $religion): View
    {
        return $this->cloneRecord($religion);
    }

    public function update(UpdateHrLookupRequest $request, HrReligion $religion): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $religion);
    }

    public function destroy(Request $request, HrReligion $religion): JsonResponse
    {
        return $this->destroyRecord($request, $religion);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('religions');
    }
}
