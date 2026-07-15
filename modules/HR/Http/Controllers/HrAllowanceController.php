<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\HR\DataTables\HrAllowancesDataTable;
use Modules\HR\Http\Requests\UpdateHrLookupRequest;
use Modules\HR\Models\HrAllowance;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class HrAllowanceController extends HrLookupController
{
    public function data(Request $request, HrAllowancesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function show(Request $request, HrAllowance $allowance): View
    {
        return $this->showRecord($request, $allowance);
    }

    public function edit(HrAllowance $allowance): View
    {
        return $this->editRecord($allowance);
    }

    public function clone(HrAllowance $allowance): View
    {
        return $this->cloneRecord($allowance);
    }

    public function update(UpdateHrLookupRequest $request, HrAllowance $allowance): JsonResponse|RedirectResponse
    {
        return $this->updateRecord($request, $allowance);
    }

    public function destroy(Request $request, HrAllowance $allowance): JsonResponse
    {
        return $this->destroyRecord($request, $allowance);
    }

    protected function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->get('allowances');
    }
}
