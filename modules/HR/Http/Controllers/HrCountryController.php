<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrCountriesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrCountry;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrCountryController extends HrLookupController
{
    public function data(Request $request, HrCountriesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrCountry $country): View
    {
        return $this->showRecord($request, $country);
    }

    public function edit(HrCountry $country): View
    {
        return $this->editRecord($country);
    }

    public function clone(HrCountry $country): View
    {
        return $this->cloneRecord($country);
    }

    public function update(UpdateHrLookupRequest $request, HrCountry $country): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $country);
    }

    public function destroy(Request $request, HrCountry $country): JsonResponse
    {
        return $this->destroyRecord($request, $country);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('countries');
    }
}
