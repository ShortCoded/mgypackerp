<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrRegulation;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrRegulationController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('regulations');
    }

    public function show(Request $request, HrRegulation $regulation): View
    {
        return $this->showRecord($request, $regulation);
    }

    public function edit(HrRegulation $regulation): View
    {
        return $this->editRecord($regulation);
    }

    public function clone(HrRegulation $regulation): View
    {
        return $this->cloneRecord($regulation);
    }

    public function update(UpdateHrFoundationRequest $request, HrRegulation $regulation): JsonResponse
    {
        return $this->updateRecord($request, $regulation);
    }

    public function destroy(Request $request, HrRegulation $regulation): JsonResponse
    {
        return $this->destroyRecord($request, $regulation);
    }
}
