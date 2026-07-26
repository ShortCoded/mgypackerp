<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrJobLevel;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrJobLevelController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('job-levels');
    }

    public function show(Request $request, HrJobLevel $jobLevel): View
    {
        return $this->showRecord($request, $jobLevel);
    }

    public function edit(HrJobLevel $jobLevel): View
    {
        return $this->editRecord($jobLevel);
    }

    public function clone(HrJobLevel $jobLevel): View
    {
        return $this->cloneRecord($jobLevel);
    }

    public function update(UpdateHrFoundationRequest $request, HrJobLevel $jobLevel): JsonResponse
    {
        return $this->updateRecord($request, $jobLevel);
    }

    public function destroy(Request $request, HrJobLevel $jobLevel): JsonResponse
    {
        return $this->destroyRecord($request, $jobLevel);
    }
}
