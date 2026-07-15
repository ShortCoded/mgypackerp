<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrMilitaryServicesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrMilitaryService;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrMilitaryServiceController extends HrLookupController
{
    public function data(Request $request, HrMilitaryServicesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrMilitaryService $militaryService): View
    {
        return $this->showRecord($request, $militaryService);
    }

    public function edit(HrMilitaryService $militaryService): View
    {
        return $this->editRecord($militaryService);
    }

    public function clone(HrMilitaryService $militaryService): View
    {
        return $this->cloneRecord($militaryService);
    }

    public function update(UpdateHrLookupRequest $request, HrMilitaryService $militaryService): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $militaryService);
    }

    public function destroy(Request $request, HrMilitaryService $militaryService): JsonResponse
    {
        return $this->destroyRecord($request, $militaryService);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('military-services');
    }
}
