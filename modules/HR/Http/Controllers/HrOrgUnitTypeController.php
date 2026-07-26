<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrOrgUnitType;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrOrgUnitTypeController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('org-unit-types');
    }

    public function show(Request $request, HrOrgUnitType $orgUnitType): View
    {
        return $this->showRecord($request, $orgUnitType);
    }

    public function edit(HrOrgUnitType $orgUnitType): View
    {
        return $this->editRecord($orgUnitType);
    }

    public function clone(HrOrgUnitType $orgUnitType): View
    {
        return $this->cloneRecord($orgUnitType);
    }

    public function update(UpdateHrFoundationRequest $request, HrOrgUnitType $orgUnitType): JsonResponse
    {
        return $this->updateRecord($request, $orgUnitType);
    }

    public function destroy(Request $request, HrOrgUnitType $orgUnitType): JsonResponse
    {
        return $this->destroyRecord($request, $orgUnitType);
    }
}
