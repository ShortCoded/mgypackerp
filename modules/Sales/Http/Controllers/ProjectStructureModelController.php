<?php

namespace Modules\Sales\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\DocumentNumberSettingsService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Sales\DataTables\ProjectStructureModelsDataTable;
use Modules\Sales\Http\Requests\BulkDeleteProjectStructureModelsRequest;
use Modules\Sales\Http\Requests\StoreProjectStructureModelRequest;
use Modules\Sales\Http\Requests\UpdateProjectStructureModelDocumentNumberSettingsRequest;
use Modules\Sales\Http\Requests\UpdateProjectStructureModelRequest;
use Modules\Sales\Models\ProjectStructureModel;
use Modules\Sales\Services\ProjectStructureModelService;
use Throwable;

class ProjectStructureModelController extends Controller
{
    public function __construct(
        private readonly ProjectStructureModelService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(DocumentNumberSettingsService $settings): View
    {
        return view('modules.sales.project-structure-models.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.sales.project-structure-models.index'),
            'documentNumberSettings' => $settings->current('project_structure_models'),
        ]);
    }

    public function data(Request $request, ProjectStructureModelsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, string $projectStructureModel): View
    {
        $record = $this->service->resolve($projectStructureModel, withTrashed: true);

        abort_if($record->trashed() && ! $request->user()?->can('project_structure_models.view_trashed'), 404);

        return $this->form('view', $record);
    }

    public function edit(string $projectStructureModel): View
    {
        return $this->form('edit', $this->service->resolve($projectStructureModel));
    }

    public function clone(string $projectStructureModel): View
    {
        $record = $this->service->resolve($projectStructureModel);
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $record->doc_num);

        return $this->form('clone', $record, $cloneSourceToken);
    }

    public function store(StoreProjectStructureModelRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);
        $record = $this->service->create($request->validated());

        if ($cloneSource instanceof ProjectStructureModel) {
            $this->logActivity($request, 'project_structure_models.clone', ActivityLogProperties::crudCloned(
                'project_structure_models',
                ActivityLogProperties::record('project_structure_models', $cloneSource->label(), $cloneSource->doc_num),
                $record->label(),
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        } else {
            $this->logActivity($request, 'project_structure_models.create', ActivityLogProperties::crudCreated(
                'project_structure_models',
                $record->label(),
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof ProjectStructureModel ? __('project_structure_models.messages.cloned') : __('project_structure_models.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateProjectStructureModelRequest $request, string $projectStructureModel): JsonResponse
    {
        $this->authorizeSubmitAction($request, $this->submitAction($request));
        $record = $this->service->resolve($projectStructureModel);
        $result = $this->service->update($record, $request->validated());

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
                'submit_action' => $this->submitAction($request),
            ]);
        }

        /** @var ProjectStructureModel $record */
        $record = $result['record'];

        $this->logActivity($request, 'project_structure_models.update', ActivityLogProperties::crudUpdated(
            'project_structure_models',
            $record->label(),
            $record->doc_num,
            $result['changes'],
            $this->submitActionProperties($request),
        ));

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'project_structure_models.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'project_structure_models',
                $record->label(),
                $result['old_doc_number'],
                $result['old_doc_num'],
                $record->doc_number,
                $record->doc_num,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('project_structure_models.messages.updated'),
            ...$this->saveResponse($request, $record, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function destroy(Request $request, string $projectStructureModel): JsonResponse
    {
        $record = $this->service->resolve($projectStructureModel);

        try {
            $this->service->delete($record);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, 'project_structure_models.delete', ActivityLogProperties::crudDeleted(
            'project_structure_models',
            $record->label(),
            $record->doc_num,
        ));

        return response()->json(['success' => true, 'message' => __('project_structure_models.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteProjectStructureModelsRequest $request): JsonResponse
    {
        $docNums = $request->validated('doc_nums');
        $deleted = $this->service->bulkDelete($docNums);

        $this->logActivity($request, 'project_structure_models.bulk_delete', ActivityLogProperties::bulkDeleted('project_structure_models', $deleted, $docNums));

        return response()->json([
            'success' => true,
            'message' => __('project_structure_models.messages.bulk_deleted', ['count' => $deleted]),
            'data' => ['deleted' => $deleted],
        ]);
    }

    public function restore(Request $request, string $projectStructureModel): JsonResponse
    {
        try {
            $record = $this->service->restore($this->service->resolve($projectStructureModel, withTrashed: true));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, 'project_structure_models.restore', ActivityLogProperties::crudRestored(
            'project_structure_models',
            $record->label(),
            $record->doc_num,
            $this->submitActionProperties($request),
        ));

        return response()->json(['success' => true, 'message' => __('project_structure_models.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateProjectStructureModelDocumentNumberSettingsRequest $request, DocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('project_structure_models', $request->validated('prefix'), (int) $request->validated('padding'));

        $this->logActivity($request, 'project_structure_models.document_number_settings.update', ActivityLogProperties::settingsUpdated('project_structure_models', [
            'prefix' => ['old' => $result['old']['prefix'], 'new' => $result['new']['prefix']],
            'padding' => ['old' => $result['old']['padding'], 'new' => $result['new']['padding']],
        ]));

        return response()->json([
            'success' => true,
            'message' => __('project_structure_models.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function form(string $mode, ?ProjectStructureModel $record = null, ?string $cloneSourceToken = null): View
    {
        return view('modules.sales.project-structure-models.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.sales.project-structure-models.store') : route('admin.sales.project-structure-models.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('project_structure_models.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'metadata' => $this->metadata($record),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function urls(ProjectStructureModel $record): array
    {
        return [
            'show' => route('admin.sales.project-structure-models.show', $record->doc_num),
            'edit' => route('admin.sales.project-structure-models.edit', $record->doc_num),
            'clone' => route('admin.sales.project-structure-models.clone', $record->doc_num),
            'update' => route('admin.sales.project-structure-models.update', $record->doc_num),
            'destroy' => route('admin.sales.project-structure-models.destroy', $record->doc_num),
            'index' => route('admin.sales.project-structure-models.index'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveResponse(Request $request, ProjectStructureModel $record, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.sales.project-structure-models.show', $record->doc_num),
            'save_edit' => route('admin.sales.project-structure-models.edit', $record->doc_num),
            'save_back' => route('admin.sales.project-structure-models.index'),
            'save_new' => $operation === 'store' ? null : route('admin.sales.project-structure-models.create'),
            'save_clone' => route('admin.sales.project-structure-models.clone', $record->doc_num),
            default => null,
        };
        $response = ['submit_action' => $action];

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can('project_structure_models.document_number.control')) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumberForCompany(
                    'project_structure_models',
                    ProjectStructureModel::class,
                    $this->companies->requireCompanyId($request),
                );
            }
        }

        return $response;
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';

        if ($creating && $action === 'save') {
            return 'save_new';
        }

        if (! $creating && in_array($action, ['save_new', 'save_edit'], true)) {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'], true)
            ? $action
            : ($creating ? 'save_new' : 'save');
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'project_structure_models.view',
            'save_edit' => 'project_structure_models.edit',
            'save_back' => 'project_structure_models.view',
            'save_new' => $cloning ? 'project_structure_models.clone' : 'project_structure_models.create',
            'save_clone' => 'project_structure_models.clone',
            default => $cloning ? 'project_structure_models.clone' : null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('project_structure_models.messages.action_forbidden'));
    }

    private function cloneSourceFromRequest(StoreProjectStructureModelRequest $request): ?ProjectStructureModel
    {
        $token = $request->string('clone_source_token')->trim()->toString();

        if ($token === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('project_structure_models.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($token), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('project_structure_models.messages.clone_not_allowed'),
            ]);
        }

        return ProjectStructureModel::query()
            ->forCompany($this->companies->requireCompanyId($request))
            ->where('doc_num', $sourceDocNum)
            ->first()
            ?? throw ValidationException::withMessages([
                'name' => __('project_structure_models.messages.clone_not_allowed'),
            ]);
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'project_structure_models.clone_sources.'.$token;
    }

    /**
     * @return array{submit_action?: string, company_doc_num?: string|null, company_name?: string|null}
     */
    private function submitActionProperties(Request $request, bool $creating = false): array
    {
        $companyContext = $this->companies->companyPublicContext($request);
        $rawAction = $request->string('submit_action')->trim()->toString();

        if ($rawAction === '' && ! $creating) {
            return $companyContext;
        }

        return [
            'submit_action' => $this->submitAction($request, creating: $creating),
            ...$companyContext,
        ];
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function breadcrumbs(string $mode, ?ProjectStructureModel $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.sales.project-structure-models.show', $record->doc_num) : null],
                ['label' => __('project_structure_models.clone')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.sales.project-structure-models.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.sales.project-structure-models.index', $extra);
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?ProjectStructureModel $record): array
    {
        if (! $record instanceof ProjectStructureModel) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->deleted_by, $record->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($record->created_by)),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($record->updated_by)),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($record->deleted_by)),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($record->restored_by)),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'sales', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
