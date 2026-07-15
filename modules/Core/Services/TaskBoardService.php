<?php

namespace Modules\Core\Services;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\Role;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Company;
use Modules\Core\Models\QuickTask;
use Modules\Core\Models\QuickTaskAttachment;
use Modules\Core\Models\TaskBoard;
use Modules\Core\Models\UserTask;

class TaskBoardService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly OperatingContextService $operatingContext,
        private readonly SettingService $settings,
        private readonly BrandingService $branding,
        private readonly TaskBoardAccessService $access,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: TaskBoard}
     */
    public function create(array $data, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($data, $actor, $request): array {
            $companyId = $this->companyContext->requireCompanyId($request);
            $documentNumber = $this->documentNumberService->nextForCompany('task_boards', TaskBoard::class, $companyId);
            abort_if($this->publicSettingsRequested($data) && ! $actor->can('task_boards.public_settings'), 403);

            $values = $this->normalizedValues($data);

            $record = TaskBoard::query()->create([
                'company_id' => $companyId,
                'branch_id' => $this->currentBranchId($request),
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'public_token' => $this->generatePublicToken(),
                'created_by' => $actor->getKey(),
            ]);

            $this->syncAssignments($record, $data);
            $this->crudAudit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load(['users:id,name,doc_num', 'roles:id,name,doc_num'])];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: TaskBoard, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function update(TaskBoard $record, array $data, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($record, $data, $actor, $request): array {
            $record = TaskBoard::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentScope($record, $request);
            $this->abortUnlessCanAccess($request, $record, 'task_boards.update');

            $oldUsers = $record->users()->pluck('users.doc_num')->sort()->values()->all();
            $oldRoles = $record->roles()->pluck('roles.doc_num')->sort()->values()->all();
            $newValues = $this->normalizedValues($data, $record);
            $changes = $this->changedValues($record, $newValues);
            abort_if($this->publicSettingsChanged($changes) && ! $actor->can('task_boards.public_settings'), 403);

            $this->syncAssignments($record, $data);

            $newUsers = $record->users()->pluck('users.doc_num')->sort()->values()->all();
            $newRoles = $record->roles()->pluck('roles.doc_num')->sort()->values()->all();

            if ($oldUsers !== $newUsers) {
                $changes['users'] = ['old' => implode(', ', $oldUsers), 'new' => implode(', ', $newUsers)];
            }

            if ($oldRoles !== $newRoles) {
                $changes['roles'] = ['old' => implode(', ', $oldRoles), 'new' => implode(', ', $newRoles)];
            }

            $changedFields = array_values(array_keys($changes));

            if ($changedFields === []) {
                return [
                    'record' => $record->refresh()->load(['users:id,name,doc_num', 'roles:id,name,doc_num']),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                ];
            }

            $modelChanges = array_intersect_key($newValues, $changes);

            if ($modelChanges !== []) {
                $this->crudAudit->saveUpdate($record, $modelChanges, (int) $actor->getKey());
            } else {
                $this->crudAudit->touchUpdateAudit($record, (int) $actor->getKey());
            }

            return [
                'record' => $record->refresh()->load(['users:id,name,doc_num', 'roles:id,name,doc_num']),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
            ];
        });
    }

    public function delete(TaskBoard $record, User $actor, Request $request): void
    {
        DB::transaction(function () use ($record, $actor, $request): void {
            $record = TaskBoard::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentScope($record, $request);
            $this->abortUnlessCanAccess($request, $record, 'task_boards.delete');

            $this->crudAudit->softDelete($record, (int) $actor->getKey());
        });
    }

    public function restore(TaskBoard $record, User $actor, Request $request): TaskBoard
    {
        return DB::transaction(function () use ($record, $actor, $request): TaskBoard {
            $record = TaskBoard::onlyTrashed()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentScope($record, $request, allowTrashed: true);
            $this->abortUnlessCanAccess($request, $record, 'task_boards.restore');
            $this->ensureCanBeRestored($record);

            $this->crudAudit->restore($record, (int) $actor->getKey());

            return $record->refresh();
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums, User $actor, Request $request): int
    {
        return DB::transaction(function () use ($docNums, $actor, $request): int {
            $deleted = 0;

            foreach ($this->bulkRecords($docNums, $actor, $request, 'task_boards.delete') as $record) {
                $this->crudAudit->softDelete($record, (int) $actor->getKey());
                $deleted++;
            }

            return $deleted;
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkSetActive(array $docNums, bool $active, User $actor, Request $request, string $permission): int
    {
        return DB::transaction(function () use ($docNums, $active, $actor, $request, $permission): int {
            $updated = 0;

            foreach ($this->bulkRecords($docNums, $actor, $request, $permission) as $record) {
                if ((bool) $record->is_active === $active) {
                    continue;
                }

                $this->crudAudit->saveUpdate($record, ['is_active' => $active], (int) $actor->getKey());
                $updated++;
            }

            return $updated;
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkRestore(array $docNums, User $actor, Request $request): int
    {
        return DB::transaction(function () use ($docNums, $actor, $request): int {
            $restored = 0;

            foreach ($this->bulkRecords($docNums, $actor, $request, 'task_boards.restore', onlyTrashed: true) as $record) {
                $this->ensureCanBeRestored($record);
                $this->crudAudit->restore($record, (int) $actor->getKey());
                $restored++;
            }

            return $restored;
        });
    }

    public function regeneratePublicToken(TaskBoard $record, User $actor, Request $request): TaskBoard
    {
        return DB::transaction(function () use ($record, $actor, $request): TaskBoard {
            $record = TaskBoard::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRecordBelongsToCurrentScope($record, $request);
            $this->abortUnlessCanAccess($request, $record, 'task_boards.regenerate_public_url');

            $this->crudAudit->saveUpdate($record, [
                'public_token' => $this->generatePublicToken(),
                'last_public_access_at' => null,
            ], (int) $actor->getKey());

            return $record->refresh();
        });
    }

    /**
     * @return Collection<int, TaskBoard>
     */
    public function accessibleBoardOptions(Request $request, User $actor): Collection
    {
        return $this->scopeToCurrentContext(TaskBoard::query(), $request)
            ->where('task_boards.is_active', true)
            ->tap(fn (Builder $query): Builder => $this->access->scopeVisibleBoards($query, $actor, 'task_boards.view'))
            ->orderBy('task_boards.name')
            ->orderBy('task_boards.doc_number')
            ->get(['id', 'doc_num', 'name']);
    }

    public function resolveAssignableBoardId(mixed $docNum, Request $request, User $actor): ?int
    {
        $docNum = trim((string) ($docNum ?? ''));

        if ($docNum === '') {
            return null;
        }

        $board = $this->scopeToCurrentContext(TaskBoard::query(), $request)
            ->where('task_boards.doc_num', $docNum)
            ->where('task_boards.is_active', true)
            ->tap(fn (Builder $query): Builder => $this->access->scopeVisibleBoards($query, $actor, 'task_boards.view'))
            ->first();

        if (! $board instanceof TaskBoard) {
            throw ValidationException::withMessages([
                'task_board_doc_num' => __('task_boards.validation.unavailable'),
            ]);
        }

        return (int) $board->getKey();
    }

    public function publicBoardByToken(string $publicToken): ?TaskBoard
    {
        return TaskBoard::query()
            ->where('public_token', $publicToken)
            ->where('is_public', true)
            ->where('is_active', true)
            ->first();
    }

    public function recordPublicAccess(TaskBoard $board): void
    {
        if ($board->last_public_access_at && $board->last_public_access_at->greaterThan(now()->subMinute())) {
            return;
        }

        $board->forceFill(['last_public_access_at' => now()])->saveQuietly();
    }

    /**
     * @return array{name: string, logo_url: string|null}
     */
    public function publicDisplayBranding(TaskBoard $board): array
    {
        $board->loadMissing('company:id,name,logo');

        $company = $board->company;

        if (! $company instanceof Company) {
            $company = Company::query()
                ->main()
                ->first(['id', 'name', 'logo']);
        }

        if ($company instanceof Company) {
            return [
                'name' => (string) $company->name,
                'logo_url' => $this->companyLogoUrl($company),
            ];
        }

        $branding = $this->branding->current();

        return [
            'name' => (string) $branding['name'],
            'logo_url' => null,
        ];
    }

    /**
     * @return array{
     *     board: array{name: string, description: string|null, display_theme: string},
     *     statuses: array<int, array{key: string, label: string, count: int, tasks: list<array<string, mixed>>}>,
     *     users: list<array<string, mixed>>,
     *     last_updated_at: string,
     *     checksum: string
     * }
     */
    public function publicDisplayPayload(TaskBoard $board, ?Request $request = null): array
    {
        $user = $request?->user();
        $publicToken = (string) $board->public_token;
        $displayUsers = $this->publicDisplayUsers($board);
        $displayUserIds = $displayUsers->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $tasks = $board->tasks()
            ->activeForBoard()
            ->with(['assignedTo:id,name', 'attachments:id,public_uuid,quick_task_id,original_name,mime_type,size'])
            ->withCount('attachments')
            ->latest('created_at')
            ->latest('doc_number')
            ->limit(150)
            ->get([
                'id',
                'task_board_id',
                'doc_num',
                'title',
                'summary',
                'status',
                'priority',
                'assigned_to',
                'created_at',
                'updated_at',
            ]);

        $userTasks = UserTask::query()
            ->where('type', UserTask::TypeTask)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('assigned_to', $displayUserIds)
            ->whereIn('status', [UserTask::StatusTodo, UserTask::StatusInProgress, UserTask::StatusWaiting])
            ->select([
                'id',
                'doc_num',
                'title',
                'description',
                'status',
                'priority',
                'assigned_to',
                'created_at',
                'updated_at',
            ])
            ->with(['assignedTo:id,name', 'attachmentUsages.file:id,doc_num,original_name,disk,path,mime_type,extension,size_bytes'])
            ->withCount('attachmentUsages')
            ->latest('created_at')
            ->latest('doc_number')
            ->limit(150)
            ->get();

        $statuses = collect(QuickTask::ActiveStatuses)
            ->map(function (string $status) use ($tasks, $user, $publicToken): array {
                $statusTasks = $tasks
                    ->where('status', $status)
                    ->values()
                    ->map(fn (QuickTask $task): array => $this->publicTaskPayload($task, $user, $publicToken))
                    ->all();

                return [
                    'key' => $status,
                    'label' => __("quick_tasks.statuses.{$status}"),
                    'count' => count($statusTasks),
                    'tasks' => $statusTasks,
                ];
            })
            ->values()
            ->all();

        $allTaskModels = collect()
            ->merge($tasks)
            ->merge($userTasks)
            ->sortByDesc('created_at')
            ->values();

        $allTasks = $allTaskModels
            ->map(fn (QuickTask|UserTask $task): array => $this->publicDisplayTaskPayload($task, $user, $publicToken))
            ->all();

        $quickTasksByUser = $tasks->groupBy('assigned_to');
        $userTasksByUser = $userTasks->groupBy('assigned_to');

        $users = collect([
            [
                'key' => 'all',
                'name' => __('task_boards.public.all_tasks'),
                'initials' => $this->userInitials(__('task_boards.public.all_tasks')),
                'task_count' => count($allTasks),
                'tasks' => $allTasks,
                'is_all' => true,
            ],
        ])->merge($displayUsers->map(function (User $displayUser) use ($quickTasksByUser, $userTasksByUser, $user, $publicToken): array {
            $userTaskModels = collect()
                ->merge($quickTasksByUser->get((int) $displayUser->getKey(), collect()))
                ->merge($userTasksByUser->get((int) $displayUser->getKey(), collect()))
                ->sortByDesc('created_at')
                ->values();

            $displayTasks = $userTaskModels
                ->map(fn (QuickTask|UserTask $task): array => $this->publicDisplayTaskPayload($task, $user, $publicToken))
                ->all();

            return [
                'key' => $this->publicUserDisplayKey($publicToken, (int) $displayUser->getKey()),
                'name' => (string) $displayUser->name,
                'initials' => $this->userInitials((string) $displayUser->name),
                'task_count' => count($displayTasks),
                'tasks' => $displayTasks,
                'is_all' => false,
            ];
        }))->values()->all();

        return [
            'board' => [
                'name' => (string) $board->name,
                'description' => $board->description,
                'display_theme' => $this->displayTheme($board->display_theme),
            ],
            'statuses' => $statuses,
            'users' => $users,
            'last_updated_at' => $this->settings->formatDateTime(now(), ''),
            'checksum' => hash('sha256', json_encode([
                'board' => [$board->doc_num, $board->updated_at?->timestamp],
                'tasks' => $tasks->map(fn (QuickTask $task): array => [
                    $task->doc_num,
                    $task->status,
                    $task->priority,
                    $task->updated_at?->timestamp,
                    $task->attachments_count ?? 0,
                ])->all(),
                'user_tasks' => $userTasks->map(fn (UserTask $task): array => [
                    $task->doc_num,
                    $task->status,
                    $task->priority,
                    $task->assigned_to,
                    $task->updated_at?->timestamp,
                    (int) ($task->attachment_usages_count ?? 0),
                ])->all(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return array{
     *     board: array{name: string, description: string|null, display_theme: string},
     *     users: list<array<string, mixed>>,
     *     last_updated_at: string,
     *     checksum: string
     * }
     */
    public function publicUserDisplayPayload(TaskBoard $board): array
    {
        $boardUsers = $this->publicDisplayUsers($board);
        $publicToken = (string) $board->public_token;

        $userIds = $boardUsers->pluck('id')->all();

        $quickTasks = $board->tasks()
            ->activeForBoard()
            ->whereIn('assigned_to', $userIds)
            ->with(['assignedTo:id,name', 'attachments:id,public_uuid,quick_task_id,original_name,mime_type,size'])
            ->withCount('attachments')
            ->latest('created_at')
            ->latest('doc_number')
            ->limit(150)
            ->get([
                'id',
                'task_board_id',
                'doc_num',
                'title',
                'summary',
                'status',
                'priority',
                'assigned_to',
                'created_at',
                'updated_at',
            ]);

        $userTasks = UserTask::query()
            ->where('type', UserTask::TypeTask)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereIn('assigned_to', $userIds)
            ->whereIn('status', [UserTask::StatusTodo, UserTask::StatusInProgress, UserTask::StatusWaiting])
            ->select([
                'id',
                'doc_num',
                'title',
                'description',
                'status',
                'priority',
                'assigned_to',
                'created_at',
                'updated_at',
            ])
            ->with(['assignedTo:id,name', 'attachmentUsages.file:id,doc_num,original_name,disk,path,mime_type,extension,size_bytes'])
            ->withCount('attachmentUsages')
            ->latest('created_at')
            ->latest('doc_number')
            ->limit(150)
            ->get();

        $quickTasksByUser = $quickTasks->groupBy('assigned_to');
        $userTasksByUser = $userTasks->groupBy('assigned_to');

        $users = $boardUsers
            ->map(function (User $user) use ($publicToken, $quickTasksByUser, $userTasksByUser): array {
                $qt = $quickTasksByUser->get((int) $user->getKey(), collect());
                $ut = $userTasksByUser->get((int) $user->getKey(), collect());

                $allTaskModels = collect()
                    ->merge($qt)
                    ->merge($ut)
                    ->sortByDesc('created_at')
                    ->values();

                $tasks = $allTaskModels->map(function (QuickTask|UserTask $task) use ($publicToken): array {
                    if ($task instanceof QuickTask) {
                        return $this->publicUserDisplayTaskPayload($task, $publicToken);
                    }

                    return $this->publicUserDisplayUserTaskPayload($task, $publicToken);
                })->all();

                return [
                    'key' => $this->publicUserDisplayKey($publicToken, (int) $user->getKey()),
                    'name' => (string) $user->name,
                    'initials' => $this->userInitials((string) $user->name),
                    'task_count' => count($tasks),
                    'tasks' => $tasks,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'board' => [
                'name' => (string) $board->name,
                'description' => $board->description,
                'display_theme' => $this->displayTheme($board->display_theme),
            ],
            'users' => $users,
            'last_updated_at' => $this->settings->formatDateTime(now(), ''),
            'checksum' => hash('sha256', json_encode([
                'board' => [$board->doc_num, $board->updated_at?->timestamp],
                'quick_tasks' => $quickTasks->map(fn (QuickTask $task): array => [
                    $task->doc_num,
                    $task->status,
                    $task->priority,
                    $task->assigned_to,
                    $task->updated_at?->timestamp,
                    $task->attachments_count ?? 0,
                ])->all(),
                'user_tasks' => $userTasks->map(fn (UserTask $task): array => [
                    $task->doc_num,
                    $task->status,
                    $task->priority,
                    $task->assigned_to,
                    $task->updated_at?->timestamp,
                    (int) ($task->attachment_usages_count ?? 0),
                ])->all(),
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicUserDisplayUserTaskPayload(UserTask $task, string $publicToken): array
    {
        $attachments = $this->publicUserTaskAttachmentPayload($task, $publicToken);

        return [
            'key' => (string) $task->doc_num,
            'type' => 'user_task',
            'doc_num' => (string) $task->doc_num,
            'title' => (string) $task->title,
            'summary' => $this->plainTextSummary($task->description),
            'status' => (string) $task->status,
            'status_label' => __("user_tasks.statuses.{$task->status}"),
            'priority' => (string) $task->priority,
            'priority_label' => __("user_tasks.priorities.{$task->priority}"),
            'assigned_to' => $task->assignedTo?->name,
            'assigned_to_initials' => $task->assignedTo?->name ? $this->userInitials((string) $task->assignedTo->name) : null,
            'created_at' => $this->settings->formatDateTime($task->created_at, ''),
            'elapsed_time' => $task->created_at?->diffForHumans(null, true),
            'attachments_count' => count($attachments),
            'attachments' => $attachments,
        ];
    }

    private function publicTaskPayload(QuickTask $task, ?User $user = null, ?string $publicToken = null): array
    {
        $actions = [];

        if ($user instanceof User) {
            $status = (string) $task->status;

            $permissionMap = [
                QuickTask::StatusNew.':'.QuickTask::StatusInProgress => 'quick_tasks.start',
                QuickTask::StatusInProgress.':'.QuickTask::StatusReady => 'quick_tasks.mark_ready',
                QuickTask::StatusReady.':'.QuickTask::StatusDone => 'quick_tasks.mark_done',
            ];

            $actionMap = [
                QuickTask::StatusNew => [
                    ['status' => QuickTask::StatusInProgress, 'label' => __('quick_tasks.actions.start'), 'icon' => 'play', 'color' => 'primary'],
                ],
                QuickTask::StatusInProgress => [
                    ['status' => QuickTask::StatusReady, 'label' => __('quick_tasks.actions.mark_ready'), 'icon' => 'check', 'color' => 'info'],
                ],
                QuickTask::StatusReady => [
                    ['status' => QuickTask::StatusDone, 'label' => __('quick_tasks.actions.mark_done'), 'icon' => 'flag-checkered', 'color' => 'success'],
                ],
            ];

            foreach ($actionMap[$status] ?? [] as $action) {
                $permission = $permissionMap[$status.':'.$action['status']] ?? null;

                if ($permission === null || $user->can($permission)) {
                    $actions[] = $action;
                }
            }
        }

        $attachments = $this->publicAttachmentPayload($task->attachments ?? collect(), $publicToken);

        return [
            'key' => (string) $task->doc_num,
            'type' => 'quick_task',
            'doc_num' => (string) $task->doc_num,
            'title' => (string) $task->title,
            'summary' => $this->plainTextSummary($task->summary),
            'status' => (string) $task->status,
            'status_label' => __("quick_tasks.statuses.{$task->status}"),
            'priority' => (string) $task->priority,
            'priority_label' => __("quick_tasks.priorities.{$task->priority}"),
            'assigned_to' => $task->assignedTo?->name,
            'assigned_to_initials' => $task->assignedTo?->name ? $this->userInitials((string) $task->assignedTo->name) : null,
            'created_at' => $this->settings->formatDateTime($task->created_at, ''),
            'elapsed_time' => $task->created_at?->diffForHumans(null, true),
            'attachments_count' => count($attachments),
            'attachments' => $attachments,
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicUserDisplayTaskPayload(QuickTask $task, string $publicToken): array
    {
        return $this->publicTaskPayload($task, null, $publicToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicDisplayTaskPayload(QuickTask|UserTask $task, ?User $user, string $publicToken): array
    {
        if ($task instanceof QuickTask) {
            return $this->publicTaskPayload($task, $user, $publicToken);
        }

        return $this->publicUserDisplayUserTaskPayload($task, $publicToken);
    }

    private function publicUserDisplayKey(string $publicToken, int $userId): string
    {
        return substr(hash('sha256', 'task-board-user|'.$publicToken.'|'.$userId), 0, 16);
    }

    private function userInitials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr((string) $parts[0], 0, 2));
        }

        $initials = collect($parts)
            ->take(2)
            ->map(fn (string $part): string => mb_substr($part, 0, 1))
            ->implode('');

        return $initials !== '' ? mb_strtoupper($initials) : 'U';
    }

    /**
     * @return list<int>
     */
    public function publicDisplayUserIds(TaskBoard $board): array
    {
        return $this->publicDisplayUsers($board)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function publicDisplayUsers(TaskBoard $board): Collection
    {
        $board->loadMissing('users');

        $users = $board->users
            ->filter(fn (User $user): bool => (string) $user->status === 'active' && $user->deleted_at === null)
            ->values();

        if ($users->isEmpty()) {
            $users = User::query()
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->get();
        }

        return $users
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function plainTextSummary(?string $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (str_contains($text, '<')) {
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\n{3,}/', "\n\n", $text);
        }

        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, QuickTaskAttachment>  $attachments
     * @return list<array{public_uuid: string, original_name: string, mime_type: string|null, size: int, is_previewable: bool, preview_url: string|null, download_url: string|null}>
     */
    private function publicAttachmentPayload($attachments, ?string $publicToken): array
    {
        return $attachments->map(function (QuickTaskAttachment $attachment) use ($publicToken): array {
            $publicUuid = (string) $attachment->public_uuid;
            $isPreviewable = $attachment->isPreviewable();

            return [
                'public_uuid' => $publicUuid,
                'original_name' => (string) $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => (int) $attachment->size,
                'is_previewable' => $isPreviewable,
                'preview_url' => $publicToken !== null
                    ? route('public.task-boards.display.attachment', [$publicToken, $publicUuid], false)
                    : null,
                'download_url' => $publicToken !== null
                    ? route('public.task-boards.display.attachment.download', [$publicToken, $publicUuid], false)
                    : null,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{public_uuid: string, original_name: string, mime_type: string|null, size: int, is_previewable: bool, preview_url: string, download_url: string}>
     */
    private function publicUserTaskAttachmentPayload(UserTask $task, string $publicToken): array
    {
        if (! $task->relationLoaded('attachmentUsages')) {
            return [];
        }

        return $task->attachmentUsages
            ->map(fn (ArchiveFileUsage $usage): ?ArchiveFile => $usage->file)
            ->filter(fn (?ArchiveFile $file): bool => $file instanceof ArchiveFile)
            ->values()
            ->map(function (ArchiveFile $file) use ($task, $publicToken): array {
                return [
                    'public_uuid' => (string) $file->doc_num,
                    'original_name' => (string) $file->original_name,
                    'mime_type' => $file->mime_type,
                    'size' => (int) $file->size_bytes,
                    'is_previewable' => $file->isPreviewable(),
                    'preview_url' => route('public.task-boards.display.user-task-attachment', [$publicToken, $task->doc_num, $file->doc_num], false),
                    'download_url' => route('public.task-boards.display.user-task-attachment.download', [$publicToken, $task->doc_num, $file->doc_num], false),
                ];
            })
            ->all();
    }

    /**
     * @return array{changed: bool, record: QuickTask}
     */
    public function changeTaskStatus(TaskBoard $board, string $taskDocNum, string $status, User $actor, Request $request): array
    {
        if (! $board->is_active) {
            abort(404);
        }

        $task = QuickTask::query()
            ->where('task_board_id', $board->getKey())
            ->where('doc_num', $taskDocNum)
            ->firstOrFail();

        if ($task->trashed()) {
            abort(404);
        }

        $from = (string) $task->status;

        $allowed = in_array("{$from}:{$status}", [
            QuickTask::StatusNew.':'.QuickTask::StatusInProgress,
            QuickTask::StatusInProgress.':'.QuickTask::StatusReady,
            QuickTask::StatusReady.':'.QuickTask::StatusDone,
        ], true);

        if (! $allowed) {
            throw new DomainException(__('quick_tasks.messages.status_transition_not_allowed'));
        }

        if ($from === $status) {
            return ['changed' => false, 'record' => $task];
        }

        $permissionMap = [
            QuickTask::StatusNew.':'.QuickTask::StatusInProgress => 'quick_tasks.start',
            QuickTask::StatusInProgress.':'.QuickTask::StatusReady => 'quick_tasks.mark_ready',
            QuickTask::StatusReady.':'.QuickTask::StatusDone => 'quick_tasks.mark_done',
        ];

        $permission = $permissionMap["{$from}:{$status}"] ?? null;

        abort_unless($permission === null || $actor->can($permission), 403, __('quick_tasks.messages.status_permission_denied'));

        $task->update([
            'status' => $status,
            'updated_by' => $actor->getKey(),
        ]);

        $task->refresh();

        return ['changed' => true, 'record' => $task];
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

    public function assertRecordBelongsToCurrentScope(TaskBoard $record, Request $request, bool $allowTrashed = false): void
    {
        $companyId = $this->companyContext->requireCompanyId($request);

        abort_if((int) $record->company_id !== $companyId, 404);
        abort_if($record->trashed() && ! $allowTrashed, 404);

        $branchId = $this->currentBranchId($request);

        abort_if($branchId !== null && $record->branch_id !== null && (int) $record->branch_id !== $branchId, 404);
    }

    public function abortUnlessCanAccess(Request $request, TaskBoard $board, string $permission): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $this->access->canAccess($user, $board, $permission), 403);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicProperties(TaskBoard $record): array
    {
        return [
            'task_board_doc_num' => $record->doc_num,
            'name' => $record->name,
            'is_active' => (bool) $record->is_active,
            'is_public' => (bool) $record->is_public,
            'requires_password' => (bool) $record->requires_password,
            'display_theme' => $this->displayTheme($record->display_theme),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data, ?TaskBoard $record = null): array
    {
        $isUpdate = $record instanceof TaskBoard;
        $requiresPassword = array_key_exists('requires_password', $data)
            ? (bool) $data['requires_password']
            : (bool) ($record?->requires_password ?? false);
        $accessCode = trim((string) ($data['access_code'] ?? ''));
        $clearAccessCode = (bool) ($data['clear_access_code'] ?? false);

        $values = [
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => $this->normalizeNullableString($data['description'] ?? null),
        ];

        foreach (['is_active', 'is_public'] as $field) {
            if (! $isUpdate || array_key_exists($field, $data)) {
                $values[$field] = (bool) ($data[$field] ?? false);
            }
        }

        if (! $isUpdate || array_key_exists('display_theme', $data)) {
            $values['display_theme'] = $this->displayTheme($data['display_theme'] ?? $record?->display_theme);
        }

        if (! $isUpdate || array_key_exists('requires_password', $data)) {
            $values['requires_password'] = $requiresPassword;
        }

        if ($clearAccessCode) {
            $requiresPassword = false;
            $values['requires_password'] = false;
            $values['public_password_hash'] = null;
        } elseif (! $requiresPassword && (! $isUpdate || array_key_exists('requires_password', $data))) {
            $values['public_password_hash'] = null;
        }

        if ($requiresPassword && $accessCode !== '') {
            $values['public_password_hash'] = Hash::make($accessCode);
        } elseif ($requiresPassword && $record instanceof TaskBoard && $record->public_password_hash !== null && ! $clearAccessCode) {
            unset($values['public_password_hash']);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncAssignments(TaskBoard $record, array $data): void
    {
        $record->users()->sync($this->userIds($data['user_doc_nums'] ?? []));
        $record->roles()->sync($this->roleIds($data['role_doc_nums'] ?? []));
    }

    /**
     * @param  array<int, mixed>|mixed  $docNums
     * @return list<int>
     */
    private function userIds(mixed $docNums): array
    {
        return User::query()
            ->whereIn('doc_num', $this->normalizedDocNums($docNums))
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>|mixed  $docNums
     * @return list<int>
     */
    private function roleIds(mixed $docNums): array
    {
        return Role::query()
            ->whereIn('doc_num', $this->normalizedDocNums($docNums))
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>|mixed  $docNums
     * @return list<string>
     */
    private function normalizedDocNums(mixed $docNums): array
    {
        if (! is_array($docNums)) {
            return [];
        }

        return collect($docNums)
            ->map(fn (mixed $docNum): string => trim((string) $docNum))
            ->filter(fn (string $docNum): bool => $docNum !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(TaskBoard $record, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $value) {
            $current = $record->{$field};

            if (in_array($field, ['is_active', 'is_public', 'requires_password'], true)) {
                $current = (bool) $current;
                $value = (bool) $value;
            }

            if ($field === 'display_theme') {
                $current = $this->displayTheme($current);
                $value = $this->displayTheme($value);
            }

            if ($field === 'public_password_hash') {
                if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                    $changes[$field] = [
                        'old' => $current === null ? null : '[hashed]',
                        'new' => $value === null ? null : '[hashed]',
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

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function displayTheme(mixed $value): string
    {
        $theme = trim((string) ($value ?? ''));

        return in_array($theme, TaskBoard::DisplayThemes, true) ? $theme : TaskBoard::DisplayThemeLight;
    }

    private function companyLogoUrl(Company $company): ?string
    {
        $logo = trim((string) ($company->logo ?? ''));

        if ($logo === '') {
            return null;
        }

        $path = Storage::disk('public')->path($logo);

        if (! is_file($path)) {
            return null;
        }

        return Storage::disk('public')->url($logo);
    }

    private function currentBranchId(Request $request): ?int
    {
        $snapshot = $this->operatingContext->snapshot($request);
        $branchId = $snapshot['branch_id'] ?? null;

        return is_numeric($branchId) ? (int) $branchId : null;
    }

    private function generatePublicToken(): string
    {
        do {
            $token = Str::random(64);
        } while (TaskBoard::query()->where('public_token', $token)->exists());

        return $token;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function publicSettingsRequested(array $data): bool
    {
        return (bool) ($data['is_public'] ?? false)
            || (bool) ($data['requires_password'] ?? false)
            || array_key_exists('display_theme', $data)
            || trim((string) ($data['access_code'] ?? '')) !== ''
            || (bool) ($data['clear_access_code'] ?? false);
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function publicSettingsChanged(array $changes): bool
    {
        return array_intersect(array_keys($changes), [
            'is_public',
            'requires_password',
            'public_password_hash',
            'display_theme',
        ]) !== [];
    }

    /**
     * @param  list<string>  $docNums
     * @return Collection<int, TaskBoard>
     */
    private function bulkRecords(array $docNums, User $actor, Request $request, string $permission, bool $onlyTrashed = false): Collection
    {
        $query = $onlyTrashed ? TaskBoard::onlyTrashed() : TaskBoard::query();

        return $this->scopeToCurrentContext($query, $request)
            ->whereIn('task_boards.doc_num', $this->normalizedDocNums($docNums))
            ->tap(fn (Builder $query): Builder => $this->access->scopeVisibleBoards($query, $actor, $permission))
            ->orderBy('task_boards.doc_number')
            ->lockForUpdate()
            ->get();
    }

    private function ensureCanBeRestored(TaskBoard $record): void
    {
        if (! $record->trashed()) {
            throw ValidationException::withMessages([
                'restore' => __('task_boards.messages.restore_not_allowed'),
            ]);
        }

        if ($this->restoreConflictFields($record) !== []) {
            throw ValidationException::withMessages([
                'restore' => __('task_boards.messages.restore_conflict'),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function restoreConflictFields(TaskBoard $record): array
    {
        $conflictFields = [];

        foreach (['doc_number', 'doc_num'] as $field) {
            if (trim((string) ($record->{$field} ?? '')) === '') {
                continue;
            }

            $exists = TaskBoard::query()
                ->where('company_id', $record->company_id)
                ->where($field, $record->{$field})
                ->whereKeyNot($record->getKey())
                ->exists();

            if ($exists) {
                $conflictFields[] = $field;
            }
        }

        return $conflictFields;
    }
}
