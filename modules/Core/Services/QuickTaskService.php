<?php

namespace Modules\Core\Services;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\QuickTask;
use Modules\Core\Models\QuickTaskAttachment;
use Modules\Core\Models\TaskBoard;

class QuickTaskService
{
    /**
     * @var list<string>
     */
    private array $fillableFields = [
        'title',
        'summary',
        'details',
        'status',
        'priority',
        'task_board_doc_num',
        'assigned_to_doc_num',
    ];

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly OperatingContextService $operatingContext,
        private readonly TaskBoardService $taskBoards,
        private readonly TaskBoardAccessService $taskBoardAccess,
        private readonly FilePickerService $filePicker,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $attachments
     * @param  list<string>  $attachmentFileDocNums
     * @return array{record: QuickTask}
     */
    public function create(array $data, array $attachments, array $attachmentFileDocNums, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($data, $attachments, $attachmentFileDocNums, $actor, $request): array {
            $companyId = $this->companyContext->requireCompanyId($request);
            $documentNumber = $this->documentNumberService->nextForCompany('quick_tasks', QuickTask::class, $companyId);
            $values = $this->normalizedValues($data, $request, $actor);

            $record = QuickTask::query()->create([
                'company_id' => $companyId,
                'branch_id' => $this->currentBranchId($request),
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => $actor->getKey(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($record);
            $this->storeAttachments($record, $attachments, $actor);
            $this->attachArchiveFiles($record, $attachmentFileDocNums, $actor, $request);

            return ['record' => $record->refresh()->load(['attachments.archiveFile', 'taskBoard:id,name,doc_num', 'assignedTo:id,name,doc_num', 'createdBy:id,name,doc_num'])];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $attachments
     * @param  list<string>  $attachmentFileDocNums
     * @return array{record: QuickTask, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function update(QuickTask $record, array $data, array $attachments, array $attachmentFileDocNums, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($record, $data, $attachments, $attachmentFileDocNums, $actor, $request): array {
            $record = QuickTask::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentScope($record, $request);

            $newValues = $this->normalizedValues($data, $request, $actor);
            $changes = $this->changedValues($record, $newValues);
            $changedFields = array_values(array_keys($changes));
            $hasAttachments = $attachments !== [];
            $hasArchiveAttachments = $attachmentFileDocNums !== [];

            if ($changedFields === [] && ! $hasAttachments && ! $hasArchiveAttachments) {
                return [
                    'record' => $record->refresh()->load(['attachments.archiveFile', 'taskBoard:id,name,doc_num', 'assignedTo:id,name,doc_num']),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                ];
            }

            if ($changedFields !== []) {
                $this->crudAudit->saveUpdate($record, $newValues, (int) $actor->getKey());
            } else {
                $this->crudAudit->touchUpdateAudit($record, (int) $actor->getKey());
            }

            if ($hasAttachments) {
                $this->storeAttachments($record->refresh(), $attachments, $actor);
            }

            if ($hasArchiveAttachments) {
                $this->attachArchiveFiles($record->refresh(), $attachmentFileDocNums, $actor, $request);
            }

            return [
                'record' => $record->refresh()->load(['attachments.archiveFile', 'taskBoard:id,name,doc_num', 'assignedTo:id,name,doc_num', 'createdBy:id,name,doc_num', 'updatedBy:id,name,doc_num']),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
            ];
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkRestore(array $docNums, User $actor, Request $request): int
    {
        return DB::transaction(function () use ($docNums, $actor, $request): int {
            $recordsQuery = $this->scopeToCurrentContext(QuickTask::onlyTrashed(), $request);
            $this->scopeToVisibleTaskBoards($recordsQuery, $actor);

            $records = $recordsQuery
                ->whereIn('doc_num', $docNums)
                ->lockForUpdate()
                ->get();

            $restored = 0;

            foreach ($records as $record) {
                if ($this->restoreConflictExists($record)) {
                    throw new DomainException(__('quick_tasks.messages.restore_conflict'));
                }

                $this->crudAudit->restore($record, (int) $actor->getKey());
                $restored++;
            }

            return $restored;
        });
    }

    public function delete(QuickTask $record, User $actor, Request $request): void
    {
        DB::transaction(function () use ($record, $actor, $request): void {
            $record = QuickTask::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentScope($record, $request);

            $this->crudAudit->softDelete($record, (int) $actor->getKey());
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums, User $actor, Request $request): int
    {
        return DB::transaction(function () use ($docNums, $actor, $request): int {
            $recordsQuery = $this->scopeToCurrentContext(QuickTask::query(), $request);
            $this->scopeToVisibleTaskBoards($recordsQuery, $actor);

            $records = $recordsQuery
                ->whereIn('doc_num', $docNums)
                ->lockForUpdate()
                ->get();

            $deleted = 0;

            foreach ($records as $record) {
                $this->crudAudit->softDelete($record, (int) $actor->getKey());
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(QuickTask $record, User $actor, Request $request): QuickTask
    {
        return DB::transaction(function () use ($record, $actor, $request): QuickTask {
            $record = QuickTask::withTrashed()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentScope($record, $request, allowTrashed: true);

            if (! $record->trashed()) {
                throw new DomainException(__('quick_tasks.messages.restore_not_allowed'));
            }

            if ($this->restoreConflictExists($record)) {
                throw new DomainException(__('quick_tasks.messages.restore_conflict'));
            }

            $this->crudAudit->restore($record, (int) $actor->getKey());

            return $record->refresh()->load(['attachments', 'taskBoard:id,name,doc_num', 'assignedTo:id,name,doc_num']);
        });
    }

    /**
     * @return array{record: QuickTask, changed: bool, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function changeStatus(QuickTask $record, string $status, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($record, $status, $actor, $request): array {
            $record = QuickTask::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentScope($record, $request);

            if (! $this->canTransition((string) $record->status, $status)) {
                throw new DomainException(__('quick_tasks.messages.status_transition_not_allowed'));
            }

            if ((string) $record->status === $status) {
                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changes' => [],
                ];
            }

            $permission = $this->statusTransitionPermission((string) $record->status, $status);

            abort_unless($permission === null || $actor->can($permission), 403, __('quick_tasks.messages.status_permission_denied'));

            $oldStatus = (string) $record->status;
            $this->crudAudit->saveUpdate($record, ['status' => $status], (int) $actor->getKey());

            return [
                'record' => $record->refresh()->load(['attachments', 'taskBoard:id,name,doc_num', 'assignedTo:id,name,doc_num']),
                'changed' => true,
                'changes' => [
                    'status' => [
                        'old' => $oldStatus,
                        'new' => $status,
                    ],
                ],
            ];
        });
    }

    public function deleteAttachment(QuickTaskAttachment $attachment, Request $request): void
    {
        $attachment->loadMissing('quickTask');
        $this->assertRecordBelongsToCurrentScope($attachment->quickTask, $request, allowTrashed: true);

        DB::transaction(function () use ($attachment): void {
            $attachment = QuickTaskAttachment::query()->whereKey($attachment->getKey())->lockForUpdate()->firstOrFail();

            if ($attachment->archive_file_id === null && Storage::disk($attachment->disk)->exists($attachment->path)) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            $attachment->delete();
        });
    }

    /**
     * @return Collection<int, QuickTask>
     */
    public function boardTasks(Request $request): Collection
    {
        $query = $this->scopeToCurrentContext(QuickTask::query(), $request);
        $user = $request->user();

        if ($user instanceof User) {
            $this->scopeToVisibleTaskBoards($query, $user);
        }

        return $query
            ->activeForBoard()
            ->with(['assignedTo:id,name,doc_num'])
            ->withCount('attachments')
            ->latest('created_at')
            ->latest('doc_number')
            ->limit(150)
            ->get();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeToCurrentContext(Builder $query, Request $request): Builder
    {
        $query = $this->companyContext->applyCompanyScope($query, $query->getModel()->getTable(), $request);
        $branchId = $this->currentBranchId($request);
        $table = $query->getModel()->getTable();

        if ($branchId !== null) {
            $query->where(function (Builder $branchQuery) use ($branchId, $table): void {
                $branchQuery
                    ->whereNull("{$table}.branch_id")
                    ->orWhere("{$table}.branch_id", $branchId);
            });
        }

        return $query;
    }

    public function assertRecordBelongsToCurrentScope(QuickTask $record, Request $request, bool $allowTrashed = false): void
    {
        $companyId = $this->companyContext->requireCompanyId($request);

        abort_if((int) $record->company_id !== $companyId, 404);
        abort_if(! $allowTrashed && $record->trashed(), 404);

        $branchId = $this->currentBranchId($request);

        abort_if($branchId !== null && $record->branch_id !== null && (int) $record->branch_id !== $branchId, 404);

        $user = $request->user();

        if ($record->task_board_id !== null && $user instanceof User) {
            $record->loadMissing('taskBoard');
            abort_unless($record->taskBoard instanceof TaskBoard && $this->taskBoardAccess->canAccess($user, $record->taskBoard, 'task_boards.view'), 403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function publicProperties(QuickTask $record): array
    {
        $record->loadMissing(['taskBoard:id,name,doc_num', 'assignedTo:id,name,doc_num', 'createdBy:id,name,doc_num']);

        return [
            'task_doc_num' => $record->doc_num,
            'title' => $record->title,
            'status' => $record->status,
            'priority' => $record->priority,
            'task_board_doc_num' => $record->taskBoard?->doc_num,
            'task_board_name' => $record->taskBoard?->name,
            'assigned_to_doc_num' => $record->assignedTo?->doc_num,
            'assigned_to_name' => $record->assignedTo?->name,
            'created_by_doc_num' => $record->createdBy?->doc_num,
        ];
    }

    public function sanitizeRichTextContent(mixed $value): ?string
    {
        return $this->sanitizeRichText($value);
    }

    /**
     * @return list<array{status: string, label: string, icon: string, color: string}>
     */
    public function statusActionOptions(QuickTask $record, User $actor): array
    {
        if ($record->trashed() || ! $actor->can('quick_tasks.change_status')) {
            return [];
        }

        $status = (string) $record->status;
        $targets = match ($status) {
            QuickTask::StatusNew => [
                QuickTask::StatusInProgress => ['label' => __('quick_tasks.actions.start'), 'icon' => 'fa-play', 'color' => 'primary'],
                QuickTask::StatusReady => ['label' => __('quick_tasks.actions.mark_ready'), 'icon' => 'fa-check', 'color' => 'info'],
            ],
            QuickTask::StatusInProgress => [
                QuickTask::StatusReady => ['label' => __('quick_tasks.actions.mark_ready'), 'icon' => 'fa-check', 'color' => 'info'],
            ],
            QuickTask::StatusReady => [
                QuickTask::StatusDone => ['label' => __('quick_tasks.actions.mark_done'), 'icon' => 'fa-flag-checkered', 'color' => 'success'],
            ],
            default => [],
        };

        if (in_array($status, QuickTask::ActiveStatuses, true)) {
            $targets[QuickTask::StatusCancelled] = ['label' => __('quick_tasks.actions.cancel'), 'icon' => 'fa-ban', 'color' => 'danger'];
        }

        return collect($targets)
            ->filter(function (array $action, string $targetStatus) use ($status, $actor): bool {
                $permission = $this->statusTransitionPermission($status, $targetStatus);

                return $this->canTransition($status, $targetStatus)
                    && ($permission === null || $actor->can($permission));
            })
            ->map(fn (array $action, string $targetStatus): array => [
                'status' => $targetStatus,
                'label' => (string) $action['label'],
                'icon' => (string) $action['icon'],
                'color' => (string) $action['color'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data, Request $request, User $actor): array
    {
        $values = [];

        foreach ($this->fillableFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            if ($field === 'assigned_to_doc_num') {
                $values['assigned_to'] = $this->assignedUserId($data[$field] ?? null);

                continue;
            }

            if ($field === 'task_board_doc_num') {
                $values['task_board_id'] = $this->taskBoards->resolveAssignableBoardId($data[$field] ?? null, $request, $actor);

                continue;
            }

            $values[$field] = $this->normalizeValue($field, $data[$field]);
        }

        $values['status'] ??= QuickTask::StatusNew;
        $values['priority'] ??= QuickTask::PriorityNormal;

        return $values;
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'title', 'status', 'priority' => trim((string) $value),
            'details' => $this->sanitizeRichText($value),
            default => $this->normalizeNullableString($value),
        };
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function assignedUserId(mixed $docNum): ?int
    {
        $docNum = trim((string) ($docNum ?? ''));

        if ($docNum === '') {
            return null;
        }

        return User::query()
            ->where('doc_num', $docNum)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(QuickTask $record, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $value) {
            $current = $record->{$field};

            if ($field === 'assigned_to') {
                $current = $current === null ? null : (int) $current;
                $value = $value === null ? null : (int) $value;

                if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                    $changes[$field] = [
                        'old' => $this->userPublicLabel($current),
                        'new' => $this->userPublicLabel($value),
                    ];
                }

                continue;
            }

            if ($field === 'task_board_id') {
                $current = $current === null ? null : (int) $current;
                $value = $value === null ? null : (int) $value;

                if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                    $changes[$field] = [
                        'old' => $this->taskBoardPublicLabel($current),
                        'new' => $this->taskBoardPublicLabel($value),
                    ];
                }

                continue;
            }

            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                $changes[$field] = [
                    'old' => $current,
                    'new' => $value,
                ];
            }
        }

        return $changes;
    }

    private function userPublicLabel(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()
            ->select(['name', 'doc_num'])
            ->find($userId);

        return $user ? trim(implode(' / ', array_filter([$user->name, $user->doc_num]))) : null;
    }

    private function taskBoardPublicLabel(?int $taskBoardId): ?string
    {
        if ($taskBoardId === null) {
            return null;
        }

        $board = TaskBoard::query()
            ->select(['name', 'doc_num'])
            ->find($taskBoardId);

        return $board ? trim(implode(' / ', array_filter([$board->name, $board->doc_num]))) : null;
    }

    /**
     * @param  list<UploadedFile>  $attachments
     */
    private function storeAttachments(QuickTask $record, array $attachments, User $actor): void
    {
        foreach ($attachments as $attachment) {
            if (! $attachment instanceof UploadedFile || ! $attachment->isValid()) {
                continue;
            }

            $extension = $attachment->getClientOriginalExtension() ?: $attachment->guessExtension() ?: 'bin';
            $path = $attachment->storeAs(
                'quick-tasks/'.(int) $record->company_id.'/'.(string) $record->doc_num,
                Str::uuid().'.'.mb_strtolower($extension),
                'local',
            );

            $record->attachments()->create([
                'disk' => 'local',
                'path' => $path,
                'original_name' => $this->safeOriginalName($attachment),
                'mime_type' => $attachment->getMimeType(),
                'size' => $attachment->getSize() ?: 0,
                'uploaded_by' => $actor->getKey(),
            ]);
        }
    }

    /**
     * @param  list<string>  $fileDocNums
     */
    private function attachArchiveFiles(QuickTask $record, array $fileDocNums, User $actor, Request $request): void
    {
        if ($fileDocNums === []) {
            return;
        }

        $companyId = $this->companyContext->requireCompanyId($request);
        $existingArchiveFileIds = $record->attachments()
            ->whereNotNull('archive_file_id')
            ->pluck('archive_file_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($fileDocNums as $fileDocNum) {
            $file = $this->filePicker->selectableFileByPublicId($fileDocNum, $companyId, FilePickerService::AcceptDocument);

            if (! $file instanceof ArchiveFile) {
                throw ValidationException::withMessages([
                    'attachment_file_doc_nums' => __('quick_tasks.validation.selected_file_unavailable'),
                ]);
            }

            if (in_array((int) $file->getKey(), $existingArchiveFileIds, true)) {
                continue;
            }

            $record->attachments()->create([
                'archive_file_id' => $file->getKey(),
                'disk' => $file->disk,
                'path' => $file->path,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => (int) $file->size_bytes,
                'uploaded_by' => $actor->getKey(),
            ]);

            $existingArchiveFileIds[] = (int) $file->getKey();
        }
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = str_replace(["\0", '/', '\\'], '', $file->getClientOriginalName());
        $name = trim($name);

        return mb_substr($name !== '' ? $name : 'attachment', 0, 255);
    }

    private function sanitizeRichText(mixed $value): ?string
    {
        $html = trim((string) $value);

        if ($html === '') {
            return null;
        }

        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', ' $1="#"', $html) ?? '';

        $hasText = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '';
        $hasImage = preg_match('/<img\b/i', $html) === 1;

        if (! $hasText && ! $hasImage) {
            return null;
        }

        $allowedTags = '<p><br><b><strong><i><em><u><s><ul><ol><li><blockquote><pre><code><a><img><span><div><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td>';

        return trim(strip_tags($html, $allowedTags)) ?: null;
    }

    /**
     * @param  Builder<QuickTask>  $query
     * @return Builder<QuickTask>
     */
    public function scopeToVisibleTaskBoards(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $taskQuery) use ($user): void {
            $taskQuery
                ->whereNull('quick_tasks.task_board_id')
                ->orWhereHas('taskBoard', fn (Builder $boardQuery): Builder => $this->taskBoardAccess->scopeVisibleBoards($boardQuery, $user, 'task_boards.view'));
        });
    }

    private function currentBranchId(Request $request): ?int
    {
        $snapshot = $this->operatingContext->snapshot($request);
        $branchId = $snapshot['branch_id'] ?? null;

        return is_numeric($branchId) ? (int) $branchId : null;
    }

    private function restoreConflictExists(QuickTask $record): bool
    {
        return QuickTask::query()
            ->forCompany((int) $record->company_id)
            ->whereKeyNot($record->getKey())
            ->where(function (Builder $query) use ($record): void {
                $query
                    ->where('doc_num', $record->doc_num)
                    ->orWhere('doc_number', $record->doc_number);
            })
            ->exists();
    }

    private function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        if (in_array($from, QuickTask::ActiveStatuses, true) && $to === QuickTask::StatusCancelled) {
            return true;
        }

        return in_array("{$from}:{$to}", [
            QuickTask::StatusNew.':'.QuickTask::StatusInProgress,
            QuickTask::StatusNew.':'.QuickTask::StatusReady,
            QuickTask::StatusNew.':'.QuickTask::StatusDone,
            QuickTask::StatusInProgress.':'.QuickTask::StatusReady,
            QuickTask::StatusInProgress.':'.QuickTask::StatusDone,
            QuickTask::StatusReady.':'.QuickTask::StatusDone,
        ], true);
    }

    private function statusTransitionPermission(string $from, string $to): ?string
    {
        if ($from === $to) {
            return null;
        }

        return match ("{$from}:{$to}") {
            QuickTask::StatusNew.':'.QuickTask::StatusInProgress => 'quick_tasks.start',
            QuickTask::StatusNew.':'.QuickTask::StatusReady,
            QuickTask::StatusInProgress.':'.QuickTask::StatusReady => 'quick_tasks.mark_ready',
            QuickTask::StatusNew.':'.QuickTask::StatusDone,
            QuickTask::StatusInProgress.':'.QuickTask::StatusDone,
            QuickTask::StatusReady.':'.QuickTask::StatusDone => 'quick_tasks.mark_done',
            QuickTask::StatusNew.':'.QuickTask::StatusCancelled,
            QuickTask::StatusInProgress.':'.QuickTask::StatusCancelled,
            QuickTask::StatusReady.':'.QuickTask::StatusCancelled => 'quick_tasks.change_status',
            default => null,
        };
    }
}
