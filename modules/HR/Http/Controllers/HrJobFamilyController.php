<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrJobFamily;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrJobFamilyController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('job-families');
    }

    public function show(Request $request, HrJobFamily $jobFamily): View
    {
        return $this->showRecord($request, $jobFamily);
    }

    public function edit(HrJobFamily $jobFamily): View
    {
        return $this->editRecord($jobFamily);
    }

    public function clone(HrJobFamily $jobFamily): View
    {
        return $this->cloneRecord($jobFamily);
    }

    public function update(UpdateHrFoundationRequest $request, HrJobFamily $jobFamily): JsonResponse
    {
        return $this->updateRecord($request, $jobFamily);
    }

    public function destroy(Request $request, HrJobFamily $jobFamily): JsonResponse
    {
        return $this->destroyRecord($request, $jobFamily);
    }
}
