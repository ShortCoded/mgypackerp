<?php

namespace Modules\Auth\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\DataTables\UsersDataTable;
use Modules\Auth\Exceptions\UserDeleteBlockedException;
use Modules\Auth\Exceptions\UserRestoreBlockedException;
use Modules\Auth\Http\Requests\BulkDeleteUsersRequest;
use Modules\Auth\Http\Requests\StoreUserRequest;
use Modules\Auth\Http\Requests\UpdateUserDocumentNumberSettingsRequest;
use Modules\Auth\Http\Requests\UpdateUserRequest;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\UserDocumentNumberSettingsService;
use Modules\Auth\Services\UserService;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\SettingService;
use Throwable;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
    ) {}

    public function index(Request $request, UserDocumentNumberSettingsService $documentNumberSettings): View
    {
        return view('modules.auth.users.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.users.index'),
            'documentNumberSettings' => $documentNumberSettings->current(),
        ]);
    }

    public function data(Request $request, UsersDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(): View
    {
        return $this->formView('create');
    }

    public function show(Request $request, User $user): View
    {
        $this->abortIfTrashedUserIsNotViewable($request, $user);

        $this->logActivity($request, 'users.view', $this->userPublicProperties($user));

        return $this->formView('view', $user);
    }

    public function edit(User $user): View
    {
        return $this->formView('edit', $user);
    }

    public function clone(User $user): View
    {
        $cloneSourceToken = (string) Str::uuid();
        session()->put($this->cloneSourceSessionKey($cloneSourceToken), $user->doc_num);

        return $this->formView('clone', $user, $cloneSourceToken);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $submitAction = $this->submitAction($request, creating: true);
        $this->authorizeSubmitAction($request, $submitAction, cloning: $request->filled('clone_source_token'));
        $cloneSource = $this->cloneSourceFromRequest($request);

        try {
            $user = $this->users->create($request->validated());
        } catch (QueryException $exception) {
            $this->throwUserValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        if ($cloneSource instanceof User) {
            $this->logActivity($request, 'users.clone', ActivityLogProperties::crudCloned(
                'users',
                ActivityLogProperties::record('users', $cloneSource->name, $cloneSource->doc_num),
                $user->name,
                $user->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        } else {
            $this->logActivity($request, 'users.create', ActivityLogProperties::crudCreated(
                'users',
                $user->name,
                $user->doc_num,
                $this->submitActionProperties($request, creating: true),
            ));
        }

        return response()->json([
            'success' => true,
            'message' => $cloneSource instanceof User ? __('users.messages.cloned') : __('users.messages.created'),
            ...$this->saveActionResponse($request, $user, 'store'),
            'data' => [
                'doc_num' => $user->doc_num,
                'doc_number' => $user->doc_number,
                'urls' => $this->userUrls($user),
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorizeSubmitAction($request, $this->submitAction($request));

        try {
            $result = $this->users->update($user, $request->validated());
        } catch (QueryException $exception) {
            $this->throwUserValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        $user = $result['user'];

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        if ($result['changed_fields'] !== []) {
            $this->logActivity($request, 'users.update', ActivityLogProperties::crudUpdated(
                'users',
                $user->name,
                $user->doc_num,
                $result['changes'],
                $this->submitActionProperties($request),
            ));
        }

        if (in_array('doc_number', $result['changed_fields'], true)) {
            $this->logActivity($request, 'users.doc_number.changed', ActivityLogProperties::documentNumberChanged(
                'users',
                $user->name,
                $result['old_doc_number'],
                $result['old_doc_num'],
                $user->doc_number,
                $user->doc_num,
            ));
        }

        return response()->json([
            'success' => true,
            'message' => __('users.messages.updated'),
            ...$this->saveActionResponse($request, $user, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_number' => $user->doc_number,
                'doc_num' => $user->doc_num,
                'urls' => $this->userUrls($user),
            ],
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        try {
            $this->users->delete($user);
        } catch (DomainException $exception) {
            if ($exception instanceof UserDeleteBlockedException) {
                $this->logDeleteBlocked($request, $exception);
            }

            return $this->domainError($exception);
        }

        $this->logActivity($request, 'users.delete', ActivityLogProperties::crudDeleted(
            'users',
            $user->name,
            $user->doc_num,
        ));

        return response()->json([
            'success' => true,
            'message' => __('users.messages.deleted'),
        ]);
    }

    public function bulkDelete(BulkDeleteUsersRequest $request): JsonResponse
    {
        try {
            $docNums = $request->validated()['doc_nums'];
            $deleted = $this->users->bulkDelete($docNums);
        } catch (DomainException $exception) {
            if ($exception instanceof UserDeleteBlockedException) {
                $this->logDeleteBlocked($request, $exception);
            }

            return $this->domainError($exception);
        }

        $this->logActivity($request, 'users.bulk_delete', ActivityLogProperties::bulkDeleted(
            'users',
            $deleted,
            $docNums,
        ));

        return response()->json([
            'success' => true,
            'message' => __('users.messages.bulk_deleted', ['count' => $deleted]),
            'data' => [
                'deleted' => $deleted,
            ],
        ]);
    }

    public function restore(Request $request, string $user): JsonResponse
    {
        $user = $this->restoreUserByDocNum($user);

        try {
            $user = $this->users->restore($user);
        } catch (UserRestoreBlockedException $exception) {
            if ($exception->isConflict()) {
                $this->logRestoreBlocked($request, $user, $exception);
            }

            return $this->restoreError($exception);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        $restoredAt = $user->restored_at?->toJSON() ?? now()->toJSON();

        $this->logActivity($request, 'users.restore', ActivityLogProperties::crudRestored(
            'users',
            $user->name,
            $user->doc_num,
            $this->userRestoreProperties($request, $user, $restoredAt),
        ));

        return response()->json([
            'success' => true,
            'message' => __('users.messages.restored_successfully'),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateUserDocumentNumberSettingsRequest $request,
        UserDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $result = $documentNumberSettings->update(
            $request->validated('prefix'),
            (int) $request->validated('padding'),
        );

        $this->logActivity($request, 'users.document_number_settings.update', ActivityLogProperties::settingsUpdated(
            'users',
            [
                'prefix' => [
                    'old' => $result['old']['prefix'],
                    'new' => $result['new']['prefix'],
                ],
                'padding' => [
                    'old' => $result['old']['padding'],
                    'new' => $result['new']['padding'],
                ],
            ],
        ));

        return response()->json([
            'success' => true,
            'message' => __('users.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function formView(string $mode, ?User $user = null, ?string $cloneSourceToken = null): View
    {
        $documentNumberSettings = app(UserDocumentNumberSettingsService::class)->current();
        $canControlDocumentNumber = (bool) auth()->user()?->can('users.document_number.control');
        $canManageUserRoles = (bool) auth()->user()?->can('users.roles.manage');

        return view('modules.auth.users.form', [
            'mode' => $mode,
            'user' => $user,
            'action' => in_array($mode, ['create', 'clone'], true) ? route('admin.users.store') : route('admin.users.update', $user?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'documentNumberPrefix' => $documentNumberSettings['prefix'],
            'documentNumberPadding' => $documentNumberSettings['padding'],
            'canControlDocumentNumber' => $canControlDocumentNumber,
            'canManageUserRoles' => $canManageUserRoles,
            'assignedRoleDocNums' => $this->assignedRoleDocNums($mode, $user, $canManageUserRoles),
            'assignedRoleOptions' => $this->assignedRoleOptions($mode, $user, $canManageUserRoles),
            'metadata' => $this->userMetadata($user),
            'breadcrumbs' => $this->userBreadcrumbs($mode, $user),
            'cloneSourceToken' => $cloneSourceToken,
        ]);
    }

    /**
     * @return list<string>
     */
    private function assignedRoleDocNums(string $mode, ?User $user, bool $canManageUserRoles): array
    {
        if (! $canManageUserRoles || ! $user || ! in_array($mode, ['edit', 'view'], true)) {
            return [];
        }

        return $user->roles()
            ->pluck('roles.doc_num')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, text: string}>
     */
    private function assignedRoleOptions(string $mode, ?User $user, bool $canManageUserRoles): array
    {
        if (! $canManageUserRoles || ! $user || ! in_array($mode, ['edit', 'view'], true)) {
            return [];
        }

        return $user->roles()
            ->select(['roles.doc_num', 'roles.name'])
            ->orderBy('roles.name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => (string) $role->doc_num,
                'text' => trim(implode(' / ', array_filter([
                    $role->name,
                    $role->doc_num,
                ]))),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function userUrls(User $user): array
    {
        return [
            'show' => route('admin.users.show', $user->doc_num),
            'clone' => route('admin.users.clone', $user->doc_num),
            'edit' => route('admin.users.edit', $user->doc_num),
            'update' => route('admin.users.update', $user->doc_num),
            'destroy' => route('admin.users.destroy', $user->doc_num),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saveActionResponse(Request $request, User $user, string $operation): array
    {
        $action = $this->submitAction($request, creating: $operation === 'store');
        $response = ['submit_action' => $action];

        $redirect = match ($action) {
            'save_view' => route('admin.users.show', $user->doc_num),
            'save_edit' => route('admin.users.edit', $user->doc_num),
            'save_back' => route('admin.users.index'),
            'save_new' => $operation === 'store' ? null : route('admin.users.create'),
            'save_clone' => route('admin.users.clone', $user->doc_num),
            default => $operation === 'store' ? $this->redirectAfterStore($request, $user) : null,
        };

        if ($redirect) {
            $response['redirect'] = $redirect;
        }

        if ($action === 'save_new') {
            $response['reset_form'] = $operation === 'store';
        }

        return $response;
    }

    private function authorizeSubmitAction(Request $request, string $action, bool $cloning = false): void
    {
        $permission = match ($action) {
            'save_view' => 'users.view',
            'save_edit' => 'users.edit',
            'save_back' => 'users.view',
            'save_new' => $cloning ? 'users.clone' : 'users.create',
            'save_clone' => 'users.clone',
            default => null,
        };

        abort_if($permission !== null && ! $request->user()?->can($permission), 403, __('users.messages.action_forbidden'));
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

        return $action;
    }

    /**
     * @return array{submit_action?: string}
     */
    private function submitActionProperties(Request $request, bool $creating = false): array
    {
        $rawAction = $request->string('submit_action')->trim()->toString();

        if ($rawAction === '' && ! $creating) {
            return [];
        }

        return ['submit_action' => $this->submitAction($request, creating: $creating)];
    }

    private function redirectAfterStore(Request $request, User $user): string
    {
        if ($request->user()?->can('users.edit')) {
            return route('admin.users.edit', $user->doc_num);
        }

        if ($request->user()?->can('users.view')) {
            return route('admin.users.show', $user->doc_num);
        }

        return route('admin.users.index');
    }

    private function cloneSourceFromRequest(StoreUserRequest $request): ?User
    {
        $cloneSourceToken = $request->string('clone_source_token')->trim()->toString();

        if ($cloneSourceToken === '') {
            return null;
        }

        abort_unless((bool) $request->user()?->can('users.clone'), 403);

        $sourceDocNum = (string) $request->session()->pull($this->cloneSourceSessionKey($cloneSourceToken), '');

        if ($sourceDocNum === '') {
            throw ValidationException::withMessages([
                'name' => __('users.messages.clone_not_allowed'),
            ]);
        }

        $sourceUser = User::query()
            ->where('doc_num', $sourceDocNum)
            ->first();

        if (! $sourceUser instanceof User) {
            throw ValidationException::withMessages([
                'name' => __('users.messages.clone_not_allowed'),
            ]);
        }

        return $sourceUser;
    }

    private function cloneSourceSessionKey(string $token): string
    {
        return 'users.clone_sources.'.$token;
    }

    /**
     * @return array<string, string|null>
     */
    private function userMetadata(?User $user): array
    {
        if (! $user) {
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
            ->whereIn('id', array_filter([$user->created_by, $user->updated_by, $user->deleted_by, $user->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($user->created_by)),
            'created_at' => $settings->formatDateTime($user->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($user->updated_by)),
            'updated_at' => $settings->formatDateTime($user->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($user->deleted_by)),
            'deleted_at' => $settings->formatDateTime($user->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($user->restored_by)),
            'restored_at' => $settings->formatDateTime($user->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }

    /**
     * @return array<int, array{label: string, url: string|null, active: bool}>
     */
    private function userBreadcrumbs(string $mode, ?User $user): array
    {
        $extra = match ($mode) {
            'create' => [
                ['label' => __('breadcrumb.create')],
            ],
            'clone' => [
                [
                    'label' => (string) $user?->doc_num,
                    'url' => $user ? route('admin.users.show', $user->doc_num) : null,
                ],
                ['label' => __('users.titles.clone')],
            ],
            'edit' => [
                [
                    'label' => (string) $user?->doc_num,
                    'url' => $user ? route('admin.users.show', $user->doc_num) : null,
                ],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [
                ['label' => (string) $user?->doc_num],
            ],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.users.index', $extra);
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $exception->getMessage(),
        ];

        if ($exception instanceof UserDeleteBlockedException && $exception->blockedRecords !== []) {
            $payload['data'] = [
                'blocked_records' => $exception->blockedRecords,
            ];
        }

        return response()->json($payload, 422);
    }

    private function restoreError(UserRestoreBlockedException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'errors' => [
                'restore' => [$exception->getMessage()],
            ],
            'data' => [
                'conflict_type' => $exception->conflictType,
                'conflict_fields' => $exception->conflictFields,
            ],
        ], 422);
    }

    private function throwUserValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();
        $map = [
            'doc_number' => ['users_doc_number_unique_active', 'users_doc_num_unique_active', 'users.doc_number', 'users.doc_num'],
            'username' => ['users_username_unique_active', 'users_username_unique', 'users.username'],
            'email' => ['users_email_unique_active', 'users_email_unique', 'users.email'],
            'phone' => ['users_phone_unique_active', 'users_phone_unique', 'users.phone'],
        ];

        foreach ($map as $field => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    throw ValidationException::withMessages([
                        $field => __("users.validation.{$field}_unique"),
                    ]);
                }
            }
        }
    }

    private function logDeleteBlocked(Request $request, UserDeleteBlockedException $exception): void
    {
        foreach ($exception->blockedRecords as $record) {
            $this->logActivity($request, 'users.delete_blocked', [
                'doc_num' => $record['doc_num'] ?? null,
                'user_name' => $record['user_name'] ?? null,
                'reason' => $record['reason'] ?? null,
                'related_records_count' => $record['related_records_count'] ?? null,
            ], 'blocked');
        }
    }

    private function logRestoreBlocked(Request $request, User $user, UserRestoreBlockedException $exception): void
    {
        $this->logActivity($request, 'users.restore_blocked', [
            'doc_num' => $user->doc_num,
            'name' => $user->name,
            'conflict_type' => $exception->conflictType,
            'conflict_fields' => $exception->conflictFields,
        ], 'blocked');
    }

    /**
     * @return array{doc_num: string|null, user_name: string, username: string|null, email: string|null, phone: string|null, status: string|null}
     */
    private function userPublicProperties(User $user): array
    {
        return [
            'doc_num' => $user->doc_num,
            'user_name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
        ];
    }

    private function restoreUserByDocNum(string $docNum): User
    {
        return User::onlyTrashed()
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? User::query()
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function abortIfTrashedUserIsNotViewable(Request $request, User $user): void
    {
        abort_if(
            $user->trashed() && ! $request->user()?->can('users.view_trashed'),
            404,
            __('users.trash.view_forbidden'),
        );
    }

    /**
     * @return array{doc_num: string|null, name: string, username: string|null, email: string|null, phone: string|null, restored_by_user_doc_num: string|null, restored_at: string}
     */
    private function userRestoreProperties(Request $request, User $user, string $restoredAt): array
    {
        $actor = $request->user();

        return [
            'doc_num' => $user->doc_num,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'restored_by_user_doc_num' => $actor instanceof User ? $actor->doc_num : null,
            'restored_at' => $restoredAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'auth', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
