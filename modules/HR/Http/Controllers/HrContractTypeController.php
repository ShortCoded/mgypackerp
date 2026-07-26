<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrContractType;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrContractTypeController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('contract-types');
    }

    public function show(Request $request, HrContractType $contractType): View
    {
        return $this->showRecord($request, $contractType);
    }

    public function edit(HrContractType $contractType): View
    {
        return $this->editRecord($contractType);
    }

    public function clone(HrContractType $contractType): View
    {
        return $this->cloneRecord($contractType);
    }

    public function update(UpdateHrFoundationRequest $request, HrContractType $contractType): JsonResponse
    {
        return $this->updateRecord($request, $contractType);
    }

    public function destroy(Request $request, HrContractType $contractType): JsonResponse
    {
        return $this->destroyRecord($request, $contractType);
    }
}
