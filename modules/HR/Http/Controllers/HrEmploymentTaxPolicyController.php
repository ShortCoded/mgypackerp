<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrEmploymentTaxPolicy;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrEmploymentTaxPolicyController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('employment-tax-policies');
    }

    public function show(Request $request, HrEmploymentTaxPolicy $employmentTaxPolicy): View
    {
        return $this->showRecord($request, $employmentTaxPolicy);
    }

    public function edit(HrEmploymentTaxPolicy $employmentTaxPolicy): View
    {
        return $this->editRecord($employmentTaxPolicy);
    }

    public function clone(HrEmploymentTaxPolicy $employmentTaxPolicy): View
    {
        return $this->cloneRecord($employmentTaxPolicy);
    }

    public function update(UpdateHrFoundationRequest $request, HrEmploymentTaxPolicy $employmentTaxPolicy): JsonResponse
    {
        return $this->updateRecord($request, $employmentTaxPolicy);
    }

    public function destroy(Request $request, HrEmploymentTaxPolicy $employmentTaxPolicy): JsonResponse
    {
        return $this->destroyRecord($request, $employmentTaxPolicy);
    }
}
