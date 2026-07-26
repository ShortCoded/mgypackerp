<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrAttendanceRule;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrAttendanceRuleController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('attendance-rules');
    }

    public function show(Request $request, HrAttendanceRule $attendanceRule): View
    {
        return $this->showRecord($request, $attendanceRule);
    }

    public function edit(HrAttendanceRule $attendanceRule): View
    {
        return $this->editRecord($attendanceRule);
    }

    public function clone(HrAttendanceRule $attendanceRule): View
    {
        return $this->cloneRecord($attendanceRule);
    }

    public function update(UpdateHrFoundationRequest $request, HrAttendanceRule $attendanceRule): JsonResponse
    {
        return $this->updateRecord($request, $attendanceRule);
    }

    public function destroy(Request $request, HrAttendanceRule $attendanceRule): JsonResponse
    {
        return $this->destroyRecord($request, $attendanceRule);
    }
}
