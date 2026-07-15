<?php

namespace Modules\Core\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\BoardList;
use Modules\Core\Models\UserTask;

class UserTaskService
{
    /**
     * @var list<string>
     */
    private array $fillableFields = [
        'title',
        'description',
        'type',
        'status',
        'is_active',
        'board_list_doc_num',
        'priority',
        'color',
        'assigned_to_doc_num',
        'assignee_doc_nums',
        'start_at',
        'due_at',
        'completed_at',
    ];

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly DateFormatService $dates,
        private readonly UserTaskAccessService $access,
        private readonly FilePickerService $filePicker,
        private readonly ArchiveFileUsageService $fileUsages,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: UserTask, assignee_user_ids: list<int>}
     */
    public function create(array $data, User $actor, array $attachmentFileDocNums = [], ?Request $request = null): array
    {
        return DB::transaction(function () use ($data, $actor, $attachmentFileDocNums, $request): array {
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber((int) $data['doc_number'])
                : $this->documentNumberService->next('user_tasks', UserTask::class);
            $values = $this->normalizedValues($data, $actor);

            $record = UserTask::query()->create([
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => $actor->getKey(),
                'position' => $this->nextPosition((int) $values['assigned_to'], (int) ($values['board_list_id'] ?? 0), (string) $values['status'], (string) $values['type']),
            ]);

            $assigneeUserIds = $this->syncAssignees($record, $this->assigneeUserIdsFromData($data, $actor, $record), $actor);
            $this->attachArchiveFiles($record, $attachmentFileDocNums, $actor, $request);
            $this->crudAudit->clearCreationUpdateAudit($record);

            return [
                'record' => $record->refresh()->load(['assignees:id,name,doc_num,email', 'assignedTo:id,name,doc_num', 'assignedBy:id,name,doc_num', 'createdBy:id,name,doc_num', 'attachmentUsages.file']),
                'assignee_user_ids' => $assigneeUserIds,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: UserTask, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null, assignee_user_ids: list<int>, added_assignee_user_ids: list<int>}
     */
    public function update(UserTask $record, array $data, User $actor, array $attachmentFileDocNums = [], ?Request $request = null): array
    {
        abort_unless($this->access->canEdit($actor, $record), 403, __('user_tasks.messages.forbidden'));

        return DB::transaction(function () use ($record, $data, $actor, $attachmentFileDocNums, $request): array {
            $record = UserTask::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $oldAssigneeUserIds = $this->currentAssigneeUserIds($record);
            $newAssigneeUserIds = $this->assigneeUserIdsFromData($data, $actor, $record);
            $assignmentChanged = $oldAssigneeUserIds !== $newAssigneeUserIds;
            $newValues = $this->normalizedValues($data, $actor, $record);

            if ($canChangeDocumentNumber) {
                $newValues['doc_number'] = (int) $data['doc_number'];
                $newValues['doc_num'] = $this->documentNumberService->format('user_tasks', (int) $data['doc_number']);
            }

            $changes = $this->changedValues($record, $newValues);

            if ($assignmentChanged) {
                $changes['assignees'] = [
                    'old' => $this->userPublicLabels($oldAssigneeUserIds),
                    'new' => $this->userPublicLabels($newAssigneeUserIds),
                ];
            }

            $changedFields = collect(array_keys($changes))
                ->map(fn (string $field): string => $field === 'doc_num' ? 'doc_number' : $field)
                ->unique()
                ->values()
                ->all();

            if ($changedFields === [] && ! $assignmentChanged) {
                $attached = $this->attachArchiveFiles($record, $attachmentFileDocNums, $actor, $request);

                if ($attached > 0) {
                    $this->crudAudit->touchUpdateAudit($record, (int) $actor->getKey());

                    return [
                        'record' => $record->refresh(),
                        'changed' => true,
                        'changed_fields' => ['attachments'],
                        'changes' => [
                            'attachments' => [
                                'old' => null,
                                'new' => $attached,
                            ],
                        ],
                        'old_doc_number' => $oldDocNumber,
                        'old_doc_num' => $oldDocNum,
                        'assignee_user_ids' => $oldAssigneeUserIds,
                        'added_assignee_user_ids' => [],
                    ];
                }

                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                    'assignee_user_ids' => $oldAssigneeUserIds,
                    'added_assignee_user_ids' => [],
                ];
            }

            if (collect(array_keys($changes))->diff(['assignees'])->isNotEmpty()) {
                $this->crudAudit->saveUpdate($record, $newValues, (int) $actor->getKey());
            } else {
                $record->forceFill([
                    'updated_by' => $actor->getKey(),
                    'updated_at' => now(),
                ])->save();
            }

            $assigneeUserIds = $assignmentChanged
                ? $this->syncAssignees($record, $newAssigneeUserIds, $actor)
                : $oldAssigneeUserIds;
            $this->attachArchiveFiles($record, $attachmentFileDocNums, $actor, $request);

            return [
                'record' => $record->refresh()->load(['assignees:id,name,doc_num,email', 'assignedTo:id,name,doc_num', 'assignedBy:id,name,doc_num', 'createdBy:id,name,doc_num', 'attachmentUsages.file']),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
                'assignee_user_ids' => $assigneeUserIds,
                'added_assignee_user_ids' => array_values(array_diff($assigneeUserIds, $oldAssigneeUserIds)),
            ];
        });
    }

    public function delete(UserTask $record, User $actor): void
    {
        abort_unless($this->access->canDelete($actor, $record), 403, __('user_tasks.messages.forbidden'));

        DB::transaction(function () use ($record, $actor): void {
            $record = UserTask::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            $this->crudAudit->softDelete($record, (int) $actor->getKey());
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums, User $actor): int
    {
        return DB::transaction(function () use ($docNums, $actor): int {
            $docNums = $this->normalizedDocNums($docNums);
            $records = UserTask::query()
                ->whereIn('doc_num', $docNums)
                ->lockForUpdate()
                ->get();

            $this->assertAllSelectedRecordsFound($docNums, $records);

            foreach ($records as $record) {
                abort_unless($this->access->canDelete($actor, $record), 403, __('user_tasks.messages.forbidden'));
            }

            $deleted = 0;

            foreach ($records as $record) {
                $this->crudAudit->softDelete($record, (int) $actor->getKey());
                $deleted++;
            }

            return $deleted;
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkSetActive(array $docNums, string $type, bool $active, User $actor): int
    {
        return DB::transaction(function () use ($docNums, $type, $active, $actor): int {
            $docNums = $this->normalizedDocNums($docNums);
            $records = UserTask::query()
                ->whereIn('doc_num', $docNums)
                ->where('type', $this->normalizeType($type))
                ->lockForUpdate()
                ->get();

            $this->assertAllSelectedRecordsFound($docNums, $records);

            $updated = 0;

            foreach ($records as $record) {
                abort_unless($this->access->canEdit($actor, $record), 403, __('user_tasks.messages.forbidden'));

                if ((bool) $record->is_active === $active) {
                    continue;
                }

                $this->crudAudit->saveUpdate($record, ['is_active' => $active], (int) $actor->getKey());
                $updated++;
            }

            return $updated;
        });
    }

    public function restore(UserTask $record, User $actor): UserTask
    {
        return DB::transaction(function () use ($record, $actor): UserTask {
            $record = UserTask::withTrashed()
                ->lockForUpdate()
                ->whereKey($record->getKey())
                ->firstOrFail();

            abort_unless($this->access->canRestore($actor, $record), 403, __('user_tasks.messages.forbidden'));

            if (! $record->trashed()) {
                throw new DomainException(__('user_tasks.messages.restore_not_allowed'));
            }

            $this->crudAudit->restore($record, (int) $actor->getKey());

            return $record->refresh()->load(['assignees:id,name,doc_num,email']);
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkRestore(array $docNums, string $type, User $actor): int
    {
        return DB::transaction(function () use ($docNums, $type, $actor): int {
            $docNums = $this->normalizedDocNums($docNums);
            $records = UserTask::onlyTrashed()
                ->whereIn('doc_num', $docNums)
                ->where('type', $this->normalizeType($type))
                ->lockForUpdate()
                ->get();

            $this->assertAllSelectedRecordsFound($docNums, $records);

            $restored = 0;

            foreach ($records as $record) {
                abort_unless($this->access->canRestore($actor, $record), 403, __('user_tasks.messages.forbidden'));
                $this->crudAudit->restore($record, (int) $actor->getKey());
                $restored++;
            }

            return $restored;
        });
    }

    /**
     * @return array{record: UserTask}
     */
    public function clone(UserTask $source, User $actor): array
    {
        abort_unless($this->access->canView($actor, $source), 403, __('user_tasks.messages.forbidden'));

        return $this->create([
            'title' => $source->title,
            'description' => $source->description,
            'type' => $source->type,
            'status' => UserTask::StatusTodo,
            'priority' => $source->priority,
            'color' => $source->color,
            'assigned_to_doc_num' => $source->assignedTo?->doc_num,
            'assignee_doc_nums' => $source->assignees->pluck('doc_num')->values()->all(),
            'start_at' => $this->storageDateTime($source->start_at),
            'due_at' => $this->storageDateTime($source->due_at),
            'completed_at' => null,
        ], $actor);
    }

    /**
     * @return array{record: UserTask, assignee_user_ids: list<int>}
     */
    public function duplicateForTable(UserTask $source, User $actor): array
    {
        abort_unless($this->access->canClone($actor, $source), 403, __('user_tasks.messages.forbidden'));

        $source->loadMissing(['assignees:id,doc_num', 'boardList:id,doc_num']);
        $actorDocNum = trim((string) $actor->doc_num);
        $assigneeDocNums = $source->type === UserTask::TypeNote || ! $this->access->canAssign($actor)
            ? [$actorDocNum]
            : collect([$actorDocNum, ...$source->assignees->pluck('doc_num')->all()])
                ->map(fn (mixed $docNum): string => trim((string) $docNum))
                ->filter()
                ->unique()
                ->values()
                ->all();

        return $this->create([
            'title' => $source->title,
            'description' => $source->description,
            'type' => $source->type,
            'status' => $source->status,
            'is_active' => true,
            'board_list_doc_num' => $source->boardList?->doc_num,
            'priority' => $source->priority,
            'color' => $source->color,
            'assigned_to_doc_num' => $actorDocNum,
            'assignee_doc_nums' => $assigneeDocNums,
            'start_at' => $this->storageDateTime($source->start_at),
            'due_at' => $this->storageDateTime($source->due_at),
            'completed_at' => null,
        ], $actor);
    }

    /**
     * @param  array{status?: string, board_list_doc_num?: string, ordered_doc_nums?: list<string>}  $data
     * @return array{record: UserTask, changed: bool, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function move(UserTask $record, array $data, User $actor): array
    {
        abort_unless($this->access->canMove($actor, $record), 403, __('user_tasks.messages.move_forbidden'));

        return DB::transaction(function () use ($record, $data, $actor): array {
            $record = UserTask::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $boardList = $this->boardListFromData($data, $record->type, (string) ($data['status'] ?? $record->status));
            $status = $boardList instanceof BoardList ? (string) $boardList->status : $this->normalizeStatus($data['status'] ?? $record->status);
            $position = $this->positionFromOrder($record, $data['ordered_doc_nums'] ?? []);
            $values = [
                'status' => $status,
                'board_list_id' => $boardList?->getKey(),
                'position' => $position,
                'completed_at' => $status === UserTask::StatusDone
                    ? ($record->completed_at ?? now())
                    : null,
            ];
            $changes = $this->changedValues($record, $values);

            if ($changes === []) {
                $this->syncOrderedPositions($data['ordered_doc_nums'] ?? [], $actor, $status, $boardList?->getKey());

                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changes' => [],
                ];
            }

            $this->crudAudit->saveUpdate($record, $values, (int) $actor->getKey());
            $this->syncOrderedPositions($data['ordered_doc_nums'] ?? [], $actor, $status, $boardList?->getKey());

            return [
                'record' => $record->refresh()->load(['assignees:id,name,doc_num,email', 'assignedTo:id,name,doc_num', 'assignedBy:id,name,doc_num', 'createdBy:id,name,doc_num']),
                'changed' => true,
                'changes' => $changes,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function publicProperties(UserTask $record): array
    {
        $record->loadMissing(['assignees:id,name,doc_num,email', 'assignedTo:id,name,doc_num', 'assignedBy:id,name,doc_num', 'createdBy:id,name,doc_num', 'boardList:id,doc_num,name']);

        return [
            'task_doc_num' => $record->doc_num,
            'title' => $record->title,
            'type' => $record->type,
            'status' => $record->status,
            'board_list_doc_num' => $record->boardList?->doc_num,
            'board_list_name' => $record->boardList?->name,
            'priority' => $record->priority,
            'assigned_to_doc_num' => $record->assignedTo?->doc_num,
            'assigned_to_name' => $record->assignedTo?->name,
            'assignees' => $record->assignees
                ->map(fn (User $user): array => [
                    'doc_num' => $user->doc_num,
                    'name' => $user->name,
                ])
                ->values()
                ->all(),
            'assigned_by_doc_num' => $record->assignedBy?->doc_num,
            'created_by_doc_num' => $record->createdBy?->doc_num,
            'due_at' => $record->due_at?->toJSON(),
        ];
    }

    public function sanitizeRichTextContent(mixed $value): ?string
    {
        return $this->sanitizeRichText($value);
    }

    public function deleteAttachment(UserTask $record, ArchiveFile $file, User $actor): bool
    {
        abort_unless($this->access->canEdit($actor, $record), 403, __('user_tasks.messages.forbidden'));

        return DB::transaction(function () use ($record, $file, $actor): bool {
            $record = UserTask::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $deleted = $this->fileUsages->detachFileFromRecord($record, $file, UserTask::AttachmentCollection, userId: (int) $actor->getKey());

            if ($deleted) {
                $this->crudAudit->touchUpdateAudit($record, (int) $actor->getKey());
            }

            return $deleted;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data, User $actor, ?UserTask $existing = null): array
    {
        $values = [];

        foreach ($this->fillableFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            if ($field === 'assigned_to_doc_num') {
                $values['assigned_to'] = $this->assignedUserId($data[$field], $actor, $existing);
                $values['assigned_by'] = $values['assigned_to'] === (int) $actor->getKey() ? null : (int) $actor->getKey();

                continue;
            }

            if ($field === 'assignee_doc_nums') {
                $assigneeUserIds = $this->assigneeUserIdsFromData($data, $actor, $existing);
                $values['assigned_to'] = $assigneeUserIds[0] ?? (int) ($existing?->assigned_to ?: $actor->getKey());
                $values['assigned_by'] = $values['assigned_to'] === (int) $actor->getKey() ? null : (int) $actor->getKey();

                continue;
            }

            if ($field === 'board_list_doc_num') {
                $boardList = $this->boardListFromData($data, (string) ($data['type'] ?? $existing?->type ?? UserTask::TypeTask), (string) ($data['status'] ?? $existing?->status ?? UserTask::StatusTodo));
                $values['board_list_id'] = $boardList?->getKey();
                $values['status'] = $boardList instanceof BoardList ? (string) $boardList->status : ($values['status'] ?? $existing?->status ?? UserTask::StatusTodo);

                continue;
            }

            $values[$field] = $this->normalizeValue($field, $data[$field]);
        }

        $values['type'] ??= $existing?->type ?? UserTask::TypeTask;
        $values['status'] ??= $existing?->status ?? UserTask::StatusTodo;
        $values['board_list_id'] ??= $existing?->board_list_id ?? $this->defaultBoardListId((string) $values['type'], (string) $values['status']);
        $values['priority'] ??= $existing?->priority ?? UserTask::PriorityNormal;
        $values['assigned_to'] ??= $existing?->assigned_to ?? (int) $actor->getKey();
        $values['assigned_by'] ??= $existing?->assigned_by;

        if (($values['status'] ?? null) === UserTask::StatusDone && ! array_key_exists('completed_at', $values)) {
            $values['completed_at'] = $existing?->completed_at ?? now();
        }

        if (($values['status'] ?? null) !== UserTask::StatusDone && ! array_key_exists('completed_at', $values)) {
            $values['completed_at'] = null;
        }

        return $values;
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'title' => $this->normalizeString($value),
            'description' => $this->sanitizeRichText($value),
            'color' => $this->normalizeNullableString($value),
            'type' => $this->normalizeType($value),
            'status' => $this->normalizeStatus($value),
            'is_active' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'priority' => $this->normalizePriority($value),
            'start_at', 'due_at', 'completed_at' => $this->dates->normalizeDateTimeForStorage((string) $value),
            default => $value,
        };
    }

    private function assignedUserId(mixed $docNum, User $actor, ?UserTask $existing): int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return (int) ($existing?->assigned_to ?: $actor->getKey());
        }

        return (int) User::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where('doc_num', $docNum)
            ->value('id');
    }

    /**
     * @return list<int>
     */
    private function assigneeUserIdsFromData(array $data, User $actor, ?UserTask $existing = null): array
    {
        $docNums = $data['assignee_doc_nums'] ?? null;

        if (! is_array($docNums)) {
            $docNums = [];
        }

        if ($docNums === [] && array_key_exists('assigned_to_doc_num', $data)) {
            $docNums = [$data['assigned_to_doc_num']];
        }

        $docNums = collect($docNums)
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter()
            ->unique()
            ->values();

        if ($docNums->isEmpty()) {
            return $existing instanceof UserTask
                ? $this->currentAssigneeUserIds($existing)
                : [(int) $actor->getKey()];
        }

        return User::query()
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereIn('doc_num', $docNums->all())
            ->get(['id', 'doc_num'])
            ->sortBy(fn (User $user): int => $docNums->search((string) $user->doc_num))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function currentAssigneeUserIds(UserTask $record): array
    {
        $record->loadMissing('assignees:id');

        $ids = $record->assignees
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($ids->isEmpty() && $record->assigned_to !== null) {
            $ids->push((int) $record->assigned_to);
        }

        return $ids->unique()->values()->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function syncAssignees(UserTask $record, array $userIds, User $actor): array
    {
        $userIds = collect($userIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            $userIds = [(int) $actor->getKey()];
        }

        $syncPayload = collect($userIds)
            ->mapWithKeys(fn (int $userId): array => [
                $userId => ['assigned_by' => $actor->getKey()],
            ])
            ->all();

        $record->assignees()->sync($syncPayload);

        return $userIds;
    }

    private function nextPosition(int $assigneeId, int $boardListId, string $status, string $type): int
    {
        $query = UserTask::query()
            ->where('assigned_to', $assigneeId)
            ->where('type', $type);

        if ($boardListId > 0) {
            $query->where('board_list_id', $boardListId);
        } else {
            $query->where('status', $status);
        }

        return ((int) $query->max('position')) + 1;
    }

    /**
     * @param  list<string>  $orderedDocNums
     */
    private function positionFromOrder(UserTask $record, array $orderedDocNums): int
    {
        $position = array_search($record->doc_num, $orderedDocNums, true);

        return $position === false ? (int) $record->position : (int) $position;
    }

    /**
     * @param  list<string>  $orderedDocNums
     */
    private function syncOrderedPositions(array $orderedDocNums, User $actor, string $status, int|string|null $boardListId = null): void
    {
        foreach (array_values($orderedDocNums) as $position => $docNum) {
            $task = UserTask::query()
                ->where('doc_num', $docNum)
                ->when($boardListId !== null, fn ($query) => $query->where('board_list_id', $boardListId), fn ($query) => $query->where('status', $status))
                ->first();

            if (! $task instanceof UserTask || ! $this->access->canMove($actor, $task)) {
                continue;
            }

            if ((int) $task->position === $position) {
                continue;
            }

            $task->forceFill(['position' => $position])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(UserTask $record, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $value) {
            $current = $record->{$field};

            if ($this->comparableValue($current) === $this->comparableValue($value)) {
                continue;
            }

            $changes[$field] = [
                'old' => $this->activityValue($field, $current),
                'new' => $this->activityValue($field, $value),
            ];
        }

        return $changes;
    }

    private function comparableValue(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->format('Y-m-d H:i:s');
        }

        return $value === null ? null : (string) $value;
    }

    private function activityValue(string $field, mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        if (in_array($field, ['assigned_to', 'assigned_by', 'created_by', 'updated_by', 'deleted_by', 'restored_by'], true)) {
            return $this->userPublicLabel($value);
        }

        return $value;
    }

    private function userPublicLabel(mixed $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $user = User::query()
            ->select(['name', 'doc_num'])
            ->whereKey((int) $userId)
            ->first();

        return $user instanceof User
            ? trim(implode(' / ', array_filter([$user->name, $user->doc_num])))
            : null;
    }

    /**
     * @param  list<int>  $userIds
     */
    private function userPublicLabels(array $userIds): string
    {
        if ($userIds === []) {
            return '';
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->get(['name', 'doc_num'])
            ->map(fn (User $user): string => trim(implode(' / ', array_filter([$user->name, $user->doc_num]))))
            ->implode(', ');
    }

    private function manualDocumentNumber(int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format('user_tasks', $docNumber),
        ];
    }

    /**
     * @param  list<string>  $docNums
     * @return list<string>
     */
    private function normalizedDocNums(array $docNums): array
    {
        return collect($docNums)
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter(fn (string $docNum): bool => $docNum !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $docNums
     * @param  Collection<int, UserTask>  $records
     */
    private function assertAllSelectedRecordsFound(array $docNums, Collection $records): void
    {
        $found = $records
            ->pluck('doc_num')
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (count($found) !== count($docNums)) {
            throw ValidationException::withMessages([
                'doc_nums' => __('user_tasks.messages.selected_records_unavailable'),
            ]);
        }
    }

    private function normalizeString(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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

    private function normalizeType(mixed $value): string
    {
        $type = trim((string) $value);

        return in_array($type, UserTask::Types, true) ? $type : UserTask::TypeTask;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = trim((string) $value);

        return in_array($status, UserTask::Statuses, true) ? $status : UserTask::StatusTodo;
    }

    private function boardListFromData(array $data, string $type, string $status): ?BoardList
    {
        $docNum = trim((string) ($data['board_list_doc_num'] ?? ''));

        if ($docNum !== '') {
            $list = BoardList::query()
                ->where('doc_num', $docNum)
                ->where('type', $this->normalizeType($type))
                ->first();

            if ($list instanceof BoardList) {
                return $list;
            }
        }

        return BoardList::query()
            ->forType($this->normalizeType($type))
            ->where('status', $this->normalizeStatus($status))
            ->orderBy('position')
            ->first();
    }

    private function defaultBoardListId(string $type, string $status): ?int
    {
        return BoardList::query()
            ->forType($this->normalizeType($type))
            ->where('status', $this->normalizeStatus($status))
            ->orderBy('position')
            ->value('id');
    }

    private function normalizePriority(mixed $value): string
    {
        $priority = trim((string) $value);

        return in_array($priority, UserTask::Priorities, true) ? $priority : UserTask::PriorityNormal;
    }

    /**
     * @param  list<string>  $fileDocNums
     */
    private function attachArchiveFiles(UserTask $record, array $fileDocNums, User $actor, ?Request $request = null): int
    {
        $fileDocNums = collect($fileDocNums)
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($fileDocNums === []) {
            return 0;
        }

        $companyId = $this->companyContext->requireCompanyId($request);
        $attached = 0;

        foreach ($fileDocNums as $fileDocNum) {
            $file = $this->filePicker->selectableFileByPublicId($fileDocNum, $companyId, FilePickerService::AcceptDocument);

            if (! $file instanceof ArchiveFile) {
                throw ValidationException::withMessages([
                    'attachment_file_doc_nums' => __('user_tasks.validation.selected_file_unavailable'),
                ]);
            }

            if ($this->fileUsages->recordUsesFile($record, $file, UserTask::AttachmentCollection)) {
                continue;
            }

            $this->fileUsages->attachFileToRecord($file, $record, UserTask::AttachmentCollection, options: [
                'sort_order' => $attached,
            ]);
            $attached++;
        }

        return $attached;
    }

    private function storageDateTime(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return null;
    }
}
