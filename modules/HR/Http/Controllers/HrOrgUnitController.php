<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrOrgUnit;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrOrgUnitController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('org-units');
    }

    public function show(Request $request, HrOrgUnit $orgUnit): View
    {
        return $this->showRecord($request, $orgUnit);
    }

    public function edit(HrOrgUnit $orgUnit): View
    {
        return $this->editRecord($orgUnit);
    }

    public function clone(HrOrgUnit $orgUnit): View
    {
        return $this->cloneRecord($orgUnit);
    }

    public function update(UpdateHrFoundationRequest $request, HrOrgUnit $orgUnit): JsonResponse
    {
        return $this->updateRecord($request, $orgUnit);
    }

    public function destroy(Request $request, HrOrgUnit $orgUnit): JsonResponse
    {
        return $this->destroyRecord($request, $orgUnit);
    }
}
