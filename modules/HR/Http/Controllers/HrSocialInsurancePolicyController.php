<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrSocialInsurancePolicy;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrSocialInsurancePolicyController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('social-insurance-policies');
    }

    public function show(Request $request, HrSocialInsurancePolicy $socialInsurancePolicy): View
    {
        return $this->showRecord($request, $socialInsurancePolicy);
    }

    public function edit(HrSocialInsurancePolicy $socialInsurancePolicy): View
    {
        return $this->editRecord($socialInsurancePolicy);
    }

    public function clone(HrSocialInsurancePolicy $socialInsurancePolicy): View
    {
        return $this->cloneRecord($socialInsurancePolicy);
    }

    public function update(UpdateHrFoundationRequest $request, HrSocialInsurancePolicy $socialInsurancePolicy): JsonResponse
    {
        return $this->updateRecord($request, $socialInsurancePolicy);
    }

    public function destroy(Request $request, HrSocialInsurancePolicy $socialInsurancePolicy): JsonResponse
    {
        return $this->destroyRecord($request, $socialInsurancePolicy);
    }
}
