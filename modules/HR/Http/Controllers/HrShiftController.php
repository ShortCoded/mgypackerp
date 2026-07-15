<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrShift;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrShiftController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('shifts');
    }

    public function show(Request $request, HrShift $shift): View
    {
        return $this->showRecord($request, $shift);
    }

    public function edit(HrShift $shift): View
    {
        return $this->editRecord($shift);
    }

    public function clone(HrShift $shift): View
    {
        return $this->cloneRecord($shift);
    }

    public function update(UpdateHrFoundationRequest $request, HrShift $shift): JsonResponse
    {
        return $this->updateRecord($request, $shift);
    }

    public function destroy(Request $request, HrShift $shift): JsonResponse
    {
        return $this->destroyRecord($request, $shift);
    }
}
