<?php

namespace Modules\Core\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Requests\OpenDocumentsRequest;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OpenDocumentsService;

class OpenDocumentsController extends Controller
{
    public function __construct(
        private readonly OpenDocumentsService $openDocuments,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(): View
    {
        return view('modules.core.open-documents.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.tools.open-documents.index'),
            'documentTypes' => $this->openDocuments->documentTypes(),
        ]);
    }

    public function store(OpenDocumentsRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $result = $this->openDocuments->reopen(
                $data['document_type'],
                $data['from_number'],
                $data['to_number'],
                $request,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'document_type' => $exception->getMessage(),
            ]);
        }

        return response()->json($result);
    }
}
