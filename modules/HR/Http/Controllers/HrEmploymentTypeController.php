<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrEmploymentType;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrEmploymentTypeController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('employment-types');
    }

    public function show(Request $request, HrEmploymentType $employmentType): View
    {
        return $this->showRecord($request, $employmentType);
    }

    public function edit(HrEmploymentType $employmentType): View
    {
        return $this->editRecord($employmentType);
    }

    public function clone(HrEmploymentType $employmentType): View
    {
        return $this->cloneRecord($employmentType);
    }

    public function update(UpdateHrFoundationRequest $request, HrEmploymentType $employmentType): JsonResponse
    {
        return $this->updateRecord($request, $employmentType);
    }

    public function destroy(Request $request, HrEmploymentType $employmentType): JsonResponse
    {
        return $this->destroyRecord($request, $employmentType);
    }
}
