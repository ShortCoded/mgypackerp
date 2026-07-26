<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrEmployeeCategory;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrEmployeeCategoryController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('employee-categories');
    }

    public function show(Request $request, HrEmployeeCategory $employeeCategory): View
    {
        return $this->showRecord($request, $employeeCategory);
    }

    public function edit(HrEmployeeCategory $employeeCategory): View
    {
        return $this->editRecord($employeeCategory);
    }

    public function clone(HrEmployeeCategory $employeeCategory): View
    {
        return $this->cloneRecord($employeeCategory);
    }

    public function update(UpdateHrFoundationRequest $request, HrEmployeeCategory $employeeCategory): JsonResponse
    {
        return $this->updateRecord($request, $employeeCategory);
    }

    public function destroy(Request $request, HrEmployeeCategory $employeeCategory): JsonResponse
    {
        return $this->destroyRecord($request, $employeeCategory);
    }
}
