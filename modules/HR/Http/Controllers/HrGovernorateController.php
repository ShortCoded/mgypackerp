<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrGovernoratesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrGovernorate;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrGovernorateController extends HrLookupController
{
    public function data(Request $request, HrGovernoratesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrGovernorate $governorate): View
    {
        return $this->showRecord($request, $governorate);
    }

    public function edit(HrGovernorate $governorate): View
    {
        return $this->editRecord($governorate);
    }

    public function clone(HrGovernorate $governorate): View
    {
        return $this->cloneRecord($governorate);
    }

    public function update(UpdateHrLookupRequest $request, HrGovernorate $governorate): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $governorate);
    }

    public function destroy(Request $request, HrGovernorate $governorate): JsonResponse
    {
        return $this->destroyRecord($request, $governorate);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('governorates');
    }
}
