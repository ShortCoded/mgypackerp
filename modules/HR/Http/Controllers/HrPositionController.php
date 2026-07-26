<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrPosition;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrPositionController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('positions');
    }

    public function show(Request $request, HrPosition $position): View
    {
        return $this->showRecord($request, $position);
    }

    public function edit(HrPosition $position): View
    {
        return $this->editRecord($position);
    }

    public function clone(HrPosition $position): View
    {
        return $this->cloneRecord($position);
    }

    public function update(UpdateHrFoundationRequest $request, HrPosition $position): JsonResponse
    {
        return $this->updateRecord($request, $position);
    }

    public function destroy(Request $request, HrPosition $position): JsonResponse
    {
        return $this->destroyRecord($request, $position);
    }
}
