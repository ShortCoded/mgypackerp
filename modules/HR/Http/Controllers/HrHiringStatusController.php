<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrHiringStatusesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrHiringStatus;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrHiringStatusController extends HrLookupController
{
    public function data(Request $request, HrHiringStatusesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrHiringStatus $hiringStatus): View
    {
        return $this->showRecord($request, $hiringStatus);
    }

    public function edit(HrHiringStatus $hiringStatus): View
    {
        return $this->editRecord($hiringStatus);
    }

    public function clone(HrHiringStatus $hiringStatus): View
    {
        return $this->cloneRecord($hiringStatus);
    }

    public function update(UpdateHrLookupRequest $request, HrHiringStatus $hiringStatus): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $hiringStatus);
    }

    public function destroy(Request $request, HrHiringStatus $hiringStatus): JsonResponse
    {
        return $this->destroyRecord($request, $hiringStatus);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('hiring-statuses');
    }
}
