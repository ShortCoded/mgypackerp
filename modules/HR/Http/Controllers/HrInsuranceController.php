<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrInsurancesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrInsurance;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrInsuranceController extends HrLookupController
{
    public function data(Request $request, HrInsurancesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrInsurance $insurance): View
    {
        return $this->showRecord($request, $insurance);
    }

    public function edit(HrInsurance $insurance): View
    {
        return $this->editRecord($insurance);
    }

    public function clone(HrInsurance $insurance): View
    {
        return $this->cloneRecord($insurance);
    }

    public function update(UpdateHrLookupRequest $request, HrInsurance $insurance): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $insurance);
    }

    public function destroy(Request $request, HrInsurance $insurance): JsonResponse
    {
        return $this->destroyRecord($request, $insurance);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('insurances');
    }
}
