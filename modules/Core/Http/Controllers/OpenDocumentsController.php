<?php

namespace Modules\Core\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Requests\OpenDocumentsRequest;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OpenDocumentsService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;

class OpenDocumentsController extends Controller
{
    public function __construct(
        private readonly OpenDocumentsService $openDocuments,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($this->openDocuments->canView($request), 403);
        $context = app(OperatingContextService::class)->snapshot($request);
        $sourceDocNum = $request->old('source_period_doc_num', $request->query('source_period_doc_num'));
        $sourcePeriod = is_string($sourceDocNum) && $sourceDocNum !== '' && $request->user()
            ? app(OperatingScopeAccessService::class)->allowedFinancialPeriodQuery($request->user(), [$context['company_doc_num']])
                ->where('financial_periods.doc_num', $sourceDocNum)->first() : null;

        return view('modules.core.open-documents.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.tools.open-documents.index'),
            'documentTypes' => $this->openDocuments->documentTypes($request),
            'canExecute' => $this->openDocuments->canExecuteAny($request),
            'sourcePeriod' => $sourcePeriod,
            'activeCompanyDocNum' => $context['company_doc_num'],
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
                (string) $data['preview_token'],
                $data['reason'] ?? null,
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'document_type' => $exception->getMessage(),
            ]);
        }

        return response()->json($result);
    }

    public function preview(OpenDocumentsRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            return response()->json($this->openDocuments->preview(
                $data['document_type'], $data['from_number'], $data['to_number'], $request,
            ));
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                filled($data['source_period_doc_num']) ? 'source_period_doc_num' : 'document_type' => $exception->getMessage(),
            ]);
        }
    }
}
