<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrDepartmentController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('departments');
    }

    public function show(Request $request, HrDepartment $department): View
    {
        return $this->showRecord($request, $department);
    }

    public function edit(HrDepartment $department): View
    {
        return $this->editRecord($department);
    }

    public function clone(HrDepartment $department): View
    {
        return $this->cloneRecord($department);
    }

    public function update(UpdateHrFoundationRequest $request, HrDepartment $department): JsonResponse
    {
        return $this->updateRecord($request, $department);
    }

    public function destroy(Request $request, HrDepartment $department): JsonResponse
    {
        return $this->destroyRecord($request, $department);
    }
}
