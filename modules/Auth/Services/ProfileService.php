<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProfileService
{
    /**
     * @param  array{name: string, username: string, email?: string|null, phone?: string|null, notes?: string|null}  $data
     * @return array{user: User, changed: bool, changed_fields: list<string>}
     */
    public function update(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $values = [
                'name' => $this->normalizeString($data['name']),
                'username' => $this->normalizeString($data['username']),
                'email' => $this->normalizeEmail($data['email'] ?? null),
                'phone' => $this->normalizeNullableString($data['phone'] ?? null),
            ];

            if (Schema::hasColumn($user->getTable(), 'notes')) {
                $values['notes'] = $this->normalizeNullableString($data['notes'] ?? null);
            }

            $changedFields = [];

            foreach ($values as $field => $value) {
                if ((string) ($user->{$field} ?? '') !== (string) ($value ?? '')) {
                    $changedFields[] = $field;
                }
            }

            if ($changedFields === []) {
                return [
                    'user' => $user->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                ];
            }

            if (Schema::hasColumn($user->getTable(), 'updated_by')) {
                $values['updated_by'] = auth()->id();
            }

            $user->forceFill($values)->save();

            return [
                'user' => $user->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
            ];
        });
    }

    private function normalizeString(string $value): string
    {
        return trim($value);
    }

    private function normalizeNullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $value = $this->normalizeNullableString($value);

        return $value === null ? null : mb_strtolower($value);
    }
}
