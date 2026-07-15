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
use Modules\Sales\DataTables\ProjectStructuresDataTable;
use Modules\Sales\Http\Requests\BulkDeleteProjectStructuresRequest;
use Modules\Sales\Http\Requests\StoreProjectStructureRequest;
use Modules\Sales\Http\Requests\UpdateProjectStructureDocumentNumberSettingsRequest;
use Modules\Sales\Http\Requests\UpdateProjectStructureRequest;
use Modules\Sales\Models\ProjectStructure;
use Modules\Sales\Services\ProjectStructureSelect2Service;
use Modules\Sales\Services\ProjectStructureService;
use Modules\Sales\Services\ProjectStructureTreeReport;
use Throwable;

class ProjectStructureController extends Controller
{
    public function __construct(
        private readonly ProjectStructureService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function index(DocumentNumberSettingsService $settings): View
    {
        return view('modules.sales.project-structures.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.sales.project-structures.index'),
            'documentNumberSettings' => $settings->current('project_structures'),
        ]);
    }

    public function data(Request $request, ProjectStructuresDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function tree(Request $request, ProjectStructureTreeReport $report): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('project_structures.tree.view'), 403);

        $rows = $report->rows($report->filtersFromRequest($request));

        return response()->json([
            'success' => true,
            'data' => $report->treeNodes($rows),
        ]);
    }

    public function select2(Request $request, ProjectStructureSelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUseProjectStructureSelector($request), 403);

        return response()->json($select2->projectStructures($request));
    }

    public function create(): View
    {
        return $this->form('create');
    }

    public function show(Request $request, string $projectStructure): View
    {
        $record = $this->service->resolve($projectStructure, withTrashed: true);

        abort_if($record->trashed() && ! $request->user()?->can('project_structures.view_trashed'), 404);

        return $this->form('view', $record);
    }

    public function edit(string $projectStructure): View
    {
        return $this->form('edit', $this->service->resolve($projectStructure));
    }

    public function clone(string $projectStructure): View
    {
        $record = $this->service->resolve($projectStructure);
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $record->doc_num);

        return $this->form('clone', $record, $cloneSourceToken);
    }

    public function store(StoreProjectStructureRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);
        $record = $this->service->create($request->validated());

        if ($cloneSource instanceof ProjectStructure) {
            $this->logActivity($request, 'project_structures.clone', ActivityLogProperties::crudCloned(
                'project_structures',
                ActivityLogProperties::record('project_structures', $cloneSource->label(), $cloneSource->doc_num),
                $record->label(),
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        } else {
            $this->logActivity($request, 'project_structures.create', ActivityLogProperties::crudCreated(
                'project_structures',
                $record->label(),
                $record->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof ProjectStructure ? __('project_structures.messages.cloned') : __('project_structures.messages.created'),
            ...$this->saveResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function update(UpdateProjectStructureRequest $request, string $projectStructure): JsonResponse
    {
        $this->authorizeSubmitAction($request, $this->submitAction($request));
        $record = $this->service->resolve($projectStructure);
        $result = $this->service->update($record, $request->validated());

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
                'submit_action' => $this->submitAction($request),
            ]);
        }

        /** @var ProjectStructure $record */
        $record = $result['record'];

        $this->logActivity($request, 'project_structures.update', ActivityLogProperties::crudUpdated(
            'project_structures',
            $record->label(),
            $record->doc_num,
            $result['changes'],
            $this->submitActionProperties($request),
        ));

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'project_structures.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'project_structures',
                $record->label(),
                $result['old_doc_number'],
                $result['old_doc_num'],
                $record->doc_number,
                $record->doc_num,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('project_structures.messages.updated'),
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

    public function destroy(Request $request, string $projectStructure): JsonResponse
    {
        $record = $this->service->resolve($projectStructure);

        try {
            $this->service->delete($record);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, 'project_structures.delete', ActivityLogProperties::crudDeleted(
            'project_structures',
            $record->label(),
            $record->doc_num,
        ));

        return response()->json(['success' => true, 'message' => __('project_structures.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteProjectStructuresRequest $request): JsonResponse
    {
        try {
            $deleted = $this->service->bulkDelete($request->validated('doc_nums'));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $docNums = $request->validated('doc_nums');
        $this->logActivity($request, 'project_structures.bulk_delete', ActivityLogProperties::bulkDeleted('project_structures', $deleted, $docNums));

        return response()->json([
            'success' => true,
            'message' => __('project_structures.messages.bulk_deleted', ['count' => $deleted]),
            'data' => ['deleted' => $deleted],
        ]);
    }

    public function restore(Request $request, string $projectStructure): JsonResponse
    {
        try {
            $record = $this->service->restore($this->service->resolve($projectStructure, withTrashed: true));
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->logActivity($request, 'project_structures.restore', ActivityLogProperties::crudRestored(
            'project_structures',
            $record->label(),
            $record->doc_num,
            $this->submitActionProperties($request),
        ));

        return response()->json(['success' => true, 'message' => __('project_structures.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateProjectStructureDocumentNumberSettingsRequest $request, DocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('project_structures', $request->validated('prefix'), (int) $request->validated('padding'));

        $this->logActivity($request, 'project_structures.document_number_settings.update', ActivityLogProperties::settingsUpdated('project_structures', [
            'prefix' => ['old' => $result['old']['prefix'], 'new' => $result['new']['prefix']],
            'padding' => ['old' => $result['old']['padding'], 'new' => $result['new']['padding']],
        ]));

        return response()->json([
            'success' => true,
            'message' => __('project_structures.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function form(string $mode, ?ProjectStructure $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing('parent');

        return view('modules.sales.project-structures.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.sales.project-structures.store') : route('admin.sales.project-structures.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('project_structures.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'metadata' => $this->metadata($record),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function urls(ProjectStructure $record): array
    {
        return [
            'show' => route('admin.sales.project-structures.show', $record->doc_num),
            'edit' => route('admin.sales.project-structures.edit', $record->doc_num),
            'clone' => route('admin.sales.project-structures.clone', $record->doc_num),
            'update' => route('admin.sales.project-structures.update', $record->doc_num),
            'destroy' => route('admin.sales.project-structures.destroy', $record->doc_num),
            'index' => route('admin.sales.project-structures.index'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveResponse(Request $request, ProjectStructure $record, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.sales.project-structures.show', $record->doc_num),
            'save_edit' => route('admin.sales.project-structures.edit', $record->doc_num),
            'save_back' => route('admin.sales.project-structures.index'),
            'save_new' => $operation === 'store' ? null : route('admin.sales.project-structures.create'),
            'save_clone' => route('admin.sales.project-structures.clone', $record->doc_num),
            default => null,
        };
        $response = ['submit_action' => $action];

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';

            if ($request->user()?->can('project_structures.document_number.control')) {
                $response['next_doc_number'] = app(DocumentNumberService::class)->nextNumberForCompany(
                    'project_structures',
                    ProjectStructure::class,
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
            'save_view' => 'project_structures.view',
            'save_edit' => 'project_structures.edit',
            'save_back' => 'project_structures.view',
            'save_new' => $cloning ? 'project_structures.clone' : 'project_structures.create',
            'save_clone' => 'project_structures.clone',
            default => $cloning ? 'project_structures.clone' : null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('project_structures.messages.action_forbidden'));
    }

    private function cloneSourceFromRequest(StoreProjectStructureRequest $request): ?ProjectStructure
    {
        $token = $request->string('clone_source_token')->trim()->toString();

        if ($token === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('project_structures.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($token), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('project_structures.messages.clone_not_allowed'),
            ]);
        }

        return ProjectStructure::query()
            ->forCompany($this->companies->requireCompanyId($request))
            ->where('doc_num', $sourceDocNum)
            ->first()
            ?? throw ValidationException::withMessages([
                'name' => __('project_structures.messages.clone_not_allowed'),
            ]);
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'project_structures.clone_sources.'.$token;
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
    private function breadcrumbs(string $mode, ?ProjectStructure $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.sales.project-structures.show', $record->doc_num) : null],
                ['label' => __('project_structures.clone')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.sales.project-structures.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.sales.project-structures.index', $extra);
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?ProjectStructure $record): array
    {
        if (! $record instanceof ProjectStructure) {
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

    private function canUseProjectStructureSelector(Request $request): bool
    {
        $user = $request->user();

        foreach ([
            'project_structures.view',
            'project_structures.create',
            'project_structures.edit',
        ] as $permission) {
            if ((bool) $user?->can($permission)) {
                return true;
            }
        }

        return false;
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
