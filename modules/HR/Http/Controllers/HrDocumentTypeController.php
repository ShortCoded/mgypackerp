<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HR\Http\Requests\Foundation\UpdateHrFoundationRequest;
use Modules\HR\Models\HrDocumentType;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class HrDocumentTypeController extends HrFoundationController
{
    protected function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->get('document-types');
    }

    public function show(Request $request, HrDocumentType $documentType): View
    {
        return $this->showRecord($request, $documentType);
    }

    public function edit(HrDocumentType $documentType): View
    {
        return $this->editRecord($documentType);
    }

    public function clone(HrDocumentType $documentType): View
    {
        return $this->cloneRecord($documentType);
    }

    public function update(UpdateHrFoundationRequest $request, HrDocumentType $documentType): JsonResponse
    {
        return $this->updateRecord($request, $documentType);
    }

    public function destroy(Request $request, HrDocumentType $documentType): JsonResponse
    {
        return $this->destroyRecord($request, $documentType);
    }
}
