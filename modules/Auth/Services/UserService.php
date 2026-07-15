<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Exceptions\UserDeleteBlockedException;
use Modules\Auth\Exceptions\UserRestoreBlockedException;
use Modules\Auth\Models\Role;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Spatie\Permission\PermissionRegistrar;

class UserService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
    ) {}

    /**
     * @param  array{name: string, username: string, email?: string|null, phone?: string|null, status: string, password: string, doc_number?: int, notes?: string|null, roles?: list<string>}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber((int) $data['doc_number'])
                : $this->documentNumberService->next('users', User::class);

            $user = User::query()->create([
                'name' => $this->normalizeString($data['name']),
                'username' => $this->normalizeString($data['username']),
                'email' => $this->normalizeEmail($data['email'] ?? null),
                'phone' => $this->normalizeNullableString($data['phone'] ?? null),
                'status' => $data['status'],
                'password' => Hash::make((string) $data['password']),
                'notes' => $this->normalizeNullableString($data['notes'] ?? null),
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($user);

            if (array_key_exists('roles', $data)) {
                $this->syncRolesByDocNum($user, $data['roles']);
            }

            return $user->refresh();
        });
    }

    /**
     * @param  array{name: string, username: string, email?: string|null, phone?: string|null, status: string, password?: string|null, doc_number?: int, notes?: string|null, roles?: list<string>}  $data
     * @return array{user: User, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null, old_user_name: string}
     */
    public function update(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $oldDocNumber = $user->doc_number === null ? null : (int) $user->doc_number;
            $oldDocNum = $user->doc_num;
            $oldUserName = $user->name;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newDocNumber = $canChangeDocumentNumber ? (int) $data['doc_number'] : $oldDocNumber;
            $newDocNum = $canChangeDocumentNumber ? $this->documentNumberService->format('users', $newDocNumber) : $oldDocNum;

            $newValues = [
                'name' => $this->normalizeString($data['name']),
                'username' => $this->normalizeString($data['username']),
                'phone' => $this->normalizeNullableString($data['phone'] ?? null),
                'status' => $data['status'],
                'notes' => $this->normalizeNullableString($data['notes'] ?? null),
            ];

            if (array_key_exists('email', $data)) {
                $newValues['email'] = $this->normalizeEmail($data['email']);
            }

            if ($canChangeDocumentNumber) {
                $newValues['doc_number'] = $newDocNumber;
                $newValues['doc_num'] = $newDocNum;
            }

            if (array_key_exists('password', $data) && trim((string) $data['password']) !== '') {
                $newValues['password'] = Hash::make((string) $data['password']);
            }

            $changedFields = [];
            $changes = [];

            foreach ($newValues as $field => $value) {
                if ($field === 'password') {
                    $changedFields[] = 'password_changed';

                    continue;
                }

                if ((string) ($user->{$field} ?? '') !== (string) ($value ?? '')) {
                    $changedFields[] = $field === 'doc_num' ? 'doc_number' : $field;
                    $changes[$field] = [
                        'old' => $user->{$field},
                        'new' => $value,
                    ];
                }
            }

            $changedFields = array_values(array_unique($changedFields));
            $rolesChanged = false;

            if (array_key_exists('roles', $data)) {
                $currentRoleDocNums = $user->roles()
                    ->pluck('roles.doc_num')
                    ->filter()
                    ->sort()
                    ->values()
                    ->all();
                $newRoleDocNums = collect($data['roles'])
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $rolesChanged = $currentRoleDocNums !== $newRoleDocNums;

                if ($rolesChanged) {
                    $changedFields[] = 'roles';
                    $changes['roles'] = [
                        'old' => $currentRoleDocNums,
                        'new' => $newRoleDocNums,
                    ];
                }
            }

            if ($changedFields === []) {
                return [
                    'user' => $user->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                    'old_user_name' => $oldUserName,
                ];
            }

            $this->crudAudit->saveUpdate($user, [
                ...$newValues,
            ]);

            if (array_key_exists('roles', $data) && $rolesChanged) {
                $this->syncRolesByDocNum($user, $data['roles']);
            }

            return [
                'user' => $user->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
                'old_user_name' => $oldUserName,
            ];
        });
    }

    public function delete(User $user): void
    {
        $this->ensureUserCanBeDeleted($user, single: true);

        DB::transaction(function () use ($user): void {
            $this->crudAudit->softDelete($user);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    public function restore(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $this->ensureUserCanBeRestored($user);

            $this->crudAudit->restore($user, auth()->id());
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $user->refresh();
        });
    }

    /**
     * @param  array<int, string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $users = User::query()->whereIn('doc_num', $docNums)->get();
            $this->ensureUsersCanBeBulkDeleted($users);

            $deleted = 0;

            foreach ($users as $user) {
                $this->crudAudit->softDelete($user);
                $deleted++;
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $deleted;
        });
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function manualDocumentNumber(int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format('users', $docNumber),
        ];
    }

    /**
     * @param  list<string>  $roleDocNums
     */
    private function syncRolesByDocNum(User $user, array $roleDocNums): void
    {
        $roles = Role::query()
            ->whereIn('doc_num', $roleDocNums)
            ->orderBy('name')
            ->get();

        $user->syncRoles($roles);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureUserCanBeDeleted(User $user, bool $single = false): void
    {
        if (auth()->id() !== null && (int) auth()->id() === (int) $user->getKey()) {
            throw new UserDeleteBlockedException(
                $single ? __('users.messages.cannot_delete_self') : __('users.messages.bulk_delete_blocked', [
                    'records' => $this->blockedRecordsLabel([$this->blockedRecord($user, 'current_user')]),
                ]),
                [$this->blockedRecord($user, 'current_user')],
            );
        }

        if ($user->hasRole('admin') && $this->activeAdminUsersCount() <= 1) {
            throw new UserDeleteBlockedException(
                $single ? __('users.messages.cannot_delete_last_admin') : __('users.messages.bulk_delete_blocked', [
                    'records' => $this->blockedRecordsLabel([$this->blockedRecord($user, 'last_admin')]),
                ]),
                [$this->blockedRecord($user, 'last_admin')],
            );
        }
    }

    private function ensureUserCanBeRestored(User $user): void
    {
        if (! $user->trashed()) {
            throw new UserRestoreBlockedException(
                __('users.messages.restore_not_allowed'),
                'already_active',
            );
        }

        $conflictFields = $this->restoreConflictFields($user);

        if ($conflictFields !== []) {
            throw new UserRestoreBlockedException(
                __('users.messages.restore_conflict'),
                $this->restoreConflictType($conflictFields),
                $conflictFields,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function restoreConflictFields(User $user): array
    {
        $conflictFields = [];

        if ($this->hasComparableValue($user->username) && $this->activeUserExists(function (Builder $query) use ($user): void {
            $query->where('username', $user->username);
        })) {
            $conflictFields[] = 'username';
        }

        if ($this->hasComparableValue($user->email) && $this->activeUserExists(function (Builder $query) use ($user): void {
            $query->where('email', $user->email);
        })) {
            $conflictFields[] = 'email';
        }

        if ($this->hasComparableValue($user->phone) && $this->activeUserExists(function (Builder $query) use ($user): void {
            $query->where('phone', $user->phone);
        })) {
            $conflictFields[] = 'phone';
        }

        if ($user->doc_number !== null && $this->activeUserExists(function (Builder $query) use ($user): void {
            $query->where('doc_number', $user->doc_number);
        })) {
            $conflictFields[] = 'doc_number';
        }

        if ($this->hasComparableValue($user->doc_num) && $this->activeUserExists(function (Builder $query) use ($user): void {
            $query->where('doc_num', $user->doc_num);
        })) {
            $conflictFields[] = 'doc_num';
        }

        return array_values(array_unique($conflictFields));
    }

    private function activeUserExists(callable $constraint): bool
    {
        $query = User::query()->whereNull('deleted_at');
        $constraint($query);

        return $query->exists();
    }

    /**
     * @param  list<string>  $conflictFields
     */
    private function restoreConflictType(array $conflictFields): string
    {
        if (in_array('doc_num', $conflictFields, true)) {
            return 'document_code_conflict';
        }

        if (in_array('doc_number', $conflictFields, true)) {
            return 'document_number_conflict';
        }

        if (in_array('phone', $conflictFields, true)) {
            return 'phone_conflict';
        }

        if (in_array('email', $conflictFields, true)) {
            return 'email_conflict';
        }

        return 'username_conflict';
    }

    /**
     * @param  iterable<int, User>  $users
     */
    private function ensureUsersCanBeBulkDeleted(iterable $users): void
    {
        $blockedRecords = [];

        foreach ($users as $user) {
            try {
                $this->ensureUserCanBeDeleted($user);
            } catch (UserDeleteBlockedException $exception) {
                array_push($blockedRecords, ...$exception->blockedRecords);
            }
        }

        if ($blockedRecords !== []) {
            throw new UserDeleteBlockedException(
                __('users.messages.bulk_delete_blocked', [
                    'records' => $this->blockedRecordsLabel($blockedRecords),
                ]),
                $blockedRecords,
            );
        }
    }

    private function activeAdminUsersCount(): int
    {
        return User::role('admin')
            ->whereNull('users.deleted_at')
            ->count();
    }

    /**
     * @return array{doc_num: string|null, user_name: string, reason: string, related_records_count: int}
     */
    private function blockedRecord(User $user, string $reason): array
    {
        return [
            'doc_num' => $user->doc_num,
            'user_name' => $user->name,
            'reason' => $reason,
            'related_records_count' => 0,
        ];
    }

    /**
     * @param  list<array{doc_num: string|null, user_name: string, reason: string, related_records_count: int}>  $blockedRecords
     */
    private function blockedRecordsLabel(array $blockedRecords): string
    {
        return collect($blockedRecords)
            ->map(fn (array $record): string => trim(($record['doc_num'] ? $record['doc_num'].' - ' : '').$record['user_name']))
            ->implode(', ');
    }

    private function normalizeString(string $value): string
    {
        return trim($value);
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : mb_strtolower($email);
    }

    private function normalizeNullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function hasComparableValue(?string $value): bool
    {
        return trim((string) $value) !== '';
    }
}
