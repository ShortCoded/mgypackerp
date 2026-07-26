<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrWorkLocation;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrWorkLocationController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('work-locations');
    }

    public function show(Request $request, HrWorkLocation $workLocation): View
    {
        return $this->showRecord($request, $workLocation);
    }

    public function edit(HrWorkLocation $workLocation): View
    {
        return $this->editRecord($workLocation);
    }

    public function clone(HrWorkLocation $workLocation): View
    {
        return $this->cloneRecord($workLocation);
    }

    public function update(UpdateHrFoundationRequest $request, HrWorkLocation $workLocation): JsonResponse
    {
        return $this->updateRecord($request, $workLocation);
    }

    public function destroy(Request $request, HrWorkLocation $workLocation): JsonResponse
    {
        return $this->destroyRecord($request, $workLocation);
    }
}
