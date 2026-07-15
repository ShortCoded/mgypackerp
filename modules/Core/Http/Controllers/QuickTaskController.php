<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\DataTables\QuickTasksDataTable;
use Modules\Core\Http\Requests\BulkDeleteQuickTasksRequest;
use Modules\Core\Http\Requests\BulkRestoreQuickTasksRequest;
use Modules\Core\Http\Requests\ChangeQuickTaskStatusRequest;
use Modules\Core\Http\Requests\StoreQuickTaskRequest;
use Modules\Core\Http\Requests\UpdateQuickTaskRequest;
use Modules\Core\Models\QuickTask;
use Modules\Core\Models\QuickTaskAttachment;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\QuickTaskService;
use Modules\Core\Services\SettingService;
use Modules\Core\Services\TaskBoardService;
use Modules\Core\Services\UserSelect2Service;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class QuickTaskController extends Controller
{
    public function __construct(
        private readonly QuickTaskService $quickTasks,
        private readonly TaskBoardService $taskBoards,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(): View
    {
        return view('modules.core.quick-tasks.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.quick-tasks.index'),
        ]);
    }

    public function data(Request $request, QuickTasksDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    public function show(Request $request, string $quickTask): View
    {
        $record = $this->recordByDocNum($request, $quickTask, withTrashed: true);
        $this->abortIfTrashedRecordIsNotViewable($request, $record);

        return $this->formView('view', $record);
    }

    public function edit(Request $request, string $quickTask): View
    {
        return $this->formView('edit', $this->recordByDocNum($request, $quickTask));
    }

    public function store(StoreQuickTaskRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->quickTasks->create($request->validated(), $request->quickTaskAttachments(), $request->quickTaskAttachmentFileDocNums(), $user, $request);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);
            throw $exception;
        }

        $record = $result['record'];
        $this->logActivity($request, 'quick_tasks.create', ActivityLogProperties::crudCreated(
            'quick_tasks',
            $record->title,
            $record->doc_num,
            $this->quickTasks->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('quick_tasks.messages.created'),
            ...$this->saveActionResponse($request, $record, 'store'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function update(UpdateQuickTaskRequest $request, string $quickTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->recordByDocNum($request, $quickTask);

        try {
            $result = $this->quickTasks->update($record, $request->validated(), $request->quickTaskAttachments(), $request->quickTaskAttachmentFileDocNums(), $user, $request);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);
            throw $exception;
        }

        $record = $result['record'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $this->logActivity($request, 'quick_tasks.update', ActivityLogProperties::crudUpdated(
            'quick_tasks',
            $record->title,
            $record->doc_num,
            $result['changes'],
            $this->quickTasks->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('quick_tasks.messages.updated'),
            ...$this->saveActionResponse($request, $record, 'update'),
            'data' => [
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->recordUrls($record),
            ],
        ]);
    }

    public function destroy(Request $request, string $quickTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->recordByDocNum($request, $quickTask);
        $properties = $this->quickTasks->publicProperties($record);

        $this->quickTasks->delete($record, $user, $request);
        $this->logActivity($request, 'quick_tasks.delete', ActivityLogProperties::crudDeleted(
            'quick_tasks',
            $properties['title'] ?? $record->title,
            $properties['task_doc_num'] ?? $record->doc_num,
            $properties,
        ));

        return response()->json([
            'success' => true,
            'message' => __('quick_tasks.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteQuickTasksRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $docNums = $request->validated('doc_nums');
        $deleted = $this->quickTasks->bulkDelete($docNums, $user, $request);

        $this->logActivity($request, 'quick_tasks.bulk_delete', ActivityLogProperties::bulkDeleted(
            'quick_tasks',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('quick_tasks.messages.bulk_deleted', ['count' => $deleted]),
            'data' => ['deleted' => $deleted],
        ]);
    }

    public function bulkRestore(BulkRestoreQuickTasksRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $docNums = $request->validated('doc_nums');

        try {
            $restored = $this->quickTasks->bulkRestore($docNums, $user, $request);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->logActivity($request, 'quick_tasks.bulk_restore', [
            'module' => 'quick_tasks',
            'restored_count' => $restored,
            'doc_nums' => $docNums,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('quick_tasks.messages.bulk_restored', ['count' => $restored]),
            'data' => ['restored' => $restored],
        ]);
    }

    public function restore(Request $request, string $quickTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->restoreRecordByDocNum($request, $quickTask);

        try {
            $record = $this->quickTasks->restore($record, $user, $request);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->logActivity($request, 'quick_tasks.restore', ActivityLogProperties::crudRestored(
            'quick_tasks',
            $record->title,
            $record->doc_num,
            $this->quickTasks->publicProperties($record),
        ));

        return response()->json([
            'success' => true,
            'message' => __('quick_tasks.messages.restored'),
        ]);
    }

    public function changeStatus(ChangeQuickTaskStatusRequest $request, string $quickTask): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->recordByDocNum($request, $quickTask);

        try {
            $result = $this->quickTasks->changeStatus($record, (string) $request->validated('status'), $user, $request);
        } catch (DomainException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $record = $result['record'];

        if ($result['changed']) {
            $this->logActivity($request, 'quick_tasks.status_change', ActivityLogProperties::statusChanged(
                'quick_tasks',
                $record->title,
                $record->doc_num,
                $result['changes']['status']['old'] ?? null,
                $result['changes']['status']['new'] ?? null,
                $this->quickTasks->publicProperties($record),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $result['changed'] ? __('quick_tasks.messages.status_changed') : __('common.messages.no_changes'),
            'data' => [
                'doc_num' => $record->doc_num,
                'status' => $record->status,
                'status_label' => __("quick_tasks.statuses.{$record->status}"),
            ],
        ]);
    }

    public function showAttachment(Request $request, QuickTaskAttachment $attachment): StreamedResponse
    {
        return $this->attachmentResponse($request, $attachment, inline: true);
    }

    public function downloadAttachment(Request $request, QuickTaskAttachment $attachment): StreamedResponse
    {
        return $this->attachmentResponse($request, $attachment, inline: false);
    }

    public function destroyAttachment(Request $request, QuickTaskAttachment $attachment): JsonResponse
    {
        $this->quickTasks->deleteAttachment($attachment, $request);

        return response()->json([
            'success' => true,
            'message' => __('quick_tasks.messages.attachment_deleted'),
        ]);
    }

    private function formView(string $mode, ?QuickTask $record = null): View
    {
        $record?->loadMissing(['attachments.archiveFile:id,doc_num,original_name,mime_type,size_bytes', 'attachments.uploadedBy:id,name,doc_num', 'taskBoard:id,name,doc_num', 'assignedTo:id,name,doc_num', 'createdBy:id,name,doc_num', 'updatedBy:id,name,doc_num', 'deletedBy:id,name,doc_num', 'restoredBy:id,name,doc_num']);
        $selectedAssigneeOption = $record?->assignedTo ? app(UserSelect2Service::class)->item($record->assignedTo) : null;
        $selectedBoardDocNum = old('task_board_doc_num', $record?->taskBoard?->doc_num ?? request()->string('task_board_doc_num')->trim()->toString());

        return view('modules.core.quick-tasks.form', [
            'mode' => $mode,
            'task' => $record,
            'action' => $mode === 'create' ? route('admin.quick-tasks.store') : route('admin.quick-tasks.update', $record?->doc_num),
            'method' => $mode === 'create' ? 'POST' : 'PUT',
            'selectedAssigneeOption' => $selectedAssigneeOption,
            'selectedBoardDocNum' => $selectedBoardDocNum,
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function recordUrls(QuickTask $record): array
    {
        return [
            'show' => route('admin.quick-tasks.show', $record->doc_num),
            'edit' => route('admin.quick-tasks.edit', $record->doc_num),
            'update' => route('admin.quick-tasks.update', $record->doc_num),
            'destroy' => route('admin.quick-tasks.destroy', $record->doc_num),
            'restore' => route('admin.quick-tasks.restore', $record->doc_num),
        ];
    }

    private function saveActionResponse(Request $request, QuickTask $record, string $operation): array
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        $response = ['submit_action' => $action];
        $redirect = match ($action) {
            'save_view' => route('admin.quick-tasks.show', $record->doc_num),
            'save_edit' => route('admin.quick-tasks.edit', $record->doc_num),
            'save_back' => route('admin.quick-tasks.index'),
            default => $operation === 'store' && $action !== 'save_new' ? $this->redirectAfterStore($request, $record) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($operation === 'store' && $action === 'save_new') {
            $response['reset_form'] = true;
        }

        return $response;
    }

    private function redirectAfterStore(Request $request, QuickTask $record): string
    {
        if ($request->user()?->can('quick_tasks.update')) {
            return route('admin.quick-tasks.edit', $record->doc_num);
        }

        if ($request->user()?->can('quick_tasks.view')) {
            return route('admin.quick-tasks.show', $record->doc_num);
        }

        return route('admin.quick-tasks.index');
    }

    private function recordByDocNum(Request $request, string $docNum, bool $withTrashed = false): QuickTask
    {
        $query = $withTrashed ? QuickTask::withTrashed() : QuickTask::query();

        $query = $this->quickTasks
            ->scopeToCurrentContext($query, $request)
            ->where('doc_num', $docNum);

        if ($request->user()) {
            $this->quickTasks->scopeToVisibleTaskBoards($query, $request->user());
        }

        return $query->firstOrFail();
    }

    private function restoreRecordByDocNum(Request $request, string $docNum): QuickTask
    {
        $query = $this->quickTasks
            ->scopeToCurrentContext(QuickTask::onlyTrashed(), $request)
            ->where('doc_num', $docNum)
            ->latest('deleted_at');

        if ($request->user()) {
            $this->quickTasks->scopeToVisibleTaskBoards($query, $request->user());
        }

        return $query->firstOrFail();
    }

    private function abortIfTrashedRecordIsNotViewable(Request $request, QuickTask $record): void
    {
        abort_if($record->trashed() && ! $request->user()?->can('quick_tasks.restore'), 404);
    }

    private function attachmentResponse(Request $request, QuickTaskAttachment $attachment, bool $inline): StreamedResponse
    {
        $attachment->loadMissing('quickTask');
        $this->quickTasks->assertRecordBelongsToCurrentScope($attachment->quickTask, $request, allowTrashed: true);
        $this->abortIfTrashedRecordIsNotViewable($request, $attachment->quickTask);

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        if ($inline && $attachment->isPreviewable()) {
            return response()->stream(function () use ($attachment): void {
                echo Storage::disk($attachment->disk)->get($attachment->path);
            }, 200, array_filter([
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
            ]));
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            array_filter(['Content-Type' => $attachment->mime_type ?: 'application/octet-stream']),
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?QuickTask $record): array
    {
        if (! $record) {
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

        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($record->createdBy),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($record->updatedBy),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($record->deletedBy),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($record->restoredBy),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        return $user ? trim(implode(' / ', array_filter([$user->name, $user->doc_num]))) : null;
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function breadcrumbs(string $mode, ?QuickTask $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'edit' => [['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.quick-tasks.show', $record->doc_num) : null], ['label' => __('breadcrumb.edit')]],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.quick-tasks.index', $extra);
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();

        foreach (['quick_tasks_company_doc_number_unique_active', 'quick_tasks_company_doc_num_unique_active'] as $needle) {
            if (str_contains($message, $needle)) {
                throw ValidationException::withMessages(['title' => __('quick_tasks.validation.doc_number_unique')]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = []): void
    {
        try {
            $this->activityLogger->log($request, 'core', $action, 'success', [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
