<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrSection;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrSectionController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('sections');
    }

    public function show(Request $request, HrSection $section): View
    {
        return $this->showRecord($request, $section);
    }

    public function edit(HrSection $section): View
    {
        return $this->editRecord($section);
    }

    public function clone(HrSection $section): View
    {
        return $this->cloneRecord($section);
    }

    public function update(UpdateHrFoundationRequest $request, HrSection $section): JsonResponse
    {
        return $this->updateRecord($request, $section);
    }

    public function destroy(Request $request, HrSection $section): JsonResponse
    {
        return $this->destroyRecord($request, $section);
    }
}
