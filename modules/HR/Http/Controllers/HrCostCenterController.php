<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrCostCenter;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrCostCenterController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('cost-centers');
    }

    public function show(Request $request, HrCostCenter $costCenter): View
    {
        return $this->showRecord($request, $costCenter);
    }

    public function edit(HrCostCenter $costCenter): View
    {
        return $this->editRecord($costCenter);
    }

    public function clone(HrCostCenter $costCenter): View
    {
        return $this->cloneRecord($costCenter);
    }

    public function update(UpdateHrFoundationRequest $request, HrCostCenter $costCenter): JsonResponse
    {
        return $this->updateRecord($request, $costCenter);
    }

    public function destroy(Request $request, HrCostCenter $costCenter): JsonResponse
    {
        return $this->destroyRecord($request, $costCenter);
    }
}
