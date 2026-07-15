<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrGrade;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrGradeController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('grades');
    }

    public function show(Request $request, HrGrade $grade): View
    {
        return $this->showRecord($request, $grade);
    }

    public function edit(HrGrade $grade): View
    {
        return $this->editRecord($grade);
    }

    public function clone(HrGrade $grade): View
    {
        return $this->cloneRecord($grade);
    }

    public function update(UpdateHrFoundationRequest $request, HrGrade $grade): JsonResponse
    {
        return $this->updateRecord($request, $grade);
    }

    public function destroy(Request $request, HrGrade $grade): JsonResponse
    {
        return $this->destroyRecord($request, $grade);
    }
}
