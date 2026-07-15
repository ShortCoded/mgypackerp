<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrInsuranceOffice;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrInsuranceOfficeController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('insurance-offices');
    }

    public function show(Request $request, HrInsuranceOffice $insuranceOffice): View
    {
        return $this->showRecord($request, $insuranceOffice);
    }

    public function edit(HrInsuranceOffice $insuranceOffice): View
    {
        return $this->editRecord($insuranceOffice);
    }

    public function clone(HrInsuranceOffice $insuranceOffice): View
    {
        return $this->cloneRecord($insuranceOffice);
    }

    public function update(UpdateHrFoundationRequest $request, HrInsuranceOffice $insuranceOffice): JsonResponse
    {
        return $this->updateRecord($request, $insuranceOffice);
    }

    public function destroy(Request $request, HrInsuranceOffice $insuranceOffice): JsonResponse
    {
        return $this->destroyRecord($request, $insuranceOffice);
    }
}
