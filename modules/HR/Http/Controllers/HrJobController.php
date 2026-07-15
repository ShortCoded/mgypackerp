<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrJob;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrJobController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('jobs');
    }

    public function show(Request $request, HrJob $job): View
    {
        return $this->showRecord($request, $job);
    }

    public function edit(HrJob $job): View
    {
        return $this->editRecord($job);
    }

    public function clone(HrJob $job): View
    {
        return $this->cloneRecord($job);
    }

    public function update(UpdateHrFoundationRequest $request, HrJob $job): JsonResponse
    {
        return $this->updateRecord($request, $job);
    }

    public function destroy(Request $request, HrJob $job): JsonResponse
    {
        return $this->destroyRecord($request, $job);
    }
}
