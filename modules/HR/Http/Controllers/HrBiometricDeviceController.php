<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrBiometricDeviceController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('biometric-devices');
    }

    public function show(Request $request, HrBiometricDevice $biometricDevice): View
    {
        return $this->showRecord($request, $biometricDevice);
    }

    public function edit(HrBiometricDevice $biometricDevice): View
    {
        return $this->editRecord($biometricDevice);
    }

    public function clone(HrBiometricDevice $biometricDevice): View
    {
        return $this->cloneRecord($biometricDevice);
    }

    public function update(UpdateHrFoundationRequest $request, HrBiometricDevice $biometricDevice): JsonResponse
    {
        return $this->updateRecord($request, $biometricDevice);
    }

    public function destroy(Request $request, HrBiometricDevice $biometricDevice): JsonResponse
    {
        return $this->destroyRecord($request, $biometricDevice);
    }
}
