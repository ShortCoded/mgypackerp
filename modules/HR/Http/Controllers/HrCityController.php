<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrCitiesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrCity;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrCityController extends HrLookupController
{
    public function data(Request $request, HrCitiesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrCity $city): View
    {
        return $this->showRecord($request, $city);
    }

    public function edit(HrCity $city): View
    {
        return $this->editRecord($city);
    }

    public function clone(HrCity $city): View
    {
        return $this->cloneRecord($city);
    }

    public function update(UpdateHrLookupRequest $request, HrCity $city): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $city);
    }

    public function destroy(Request $request, HrCity $city): JsonResponse
    {
        return $this->destroyRecord($request, $city);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('cities');
    }
}
