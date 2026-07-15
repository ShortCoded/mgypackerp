<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;

class ActivityLogProperties
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function crudCreated(string $resource, mixed $recordLabel, mixed $docNum, array $meta = []): array
    {
        return self::base($resource, 'create', $recordLabel, $docNum, meta: $meta);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function crudUpdated(string $resource, mixed $recordLabel, mixed $docNum, array $changes, array $meta = []): array
    {
        return self::base($resource, 'update', $recordLabel, $docNum, self::changes($changes), $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function crudDeleted(string $resource, mixed $recordLabel, mixed $docNum, array $meta = []): array
    {
        return self::base($resource, 'delete', $recordLabel, $docNum, meta: $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function crudRestored(string $resource, mixed $recordLabel, mixed $docNum, array $meta = []): array
    {
        return self::base($resource, 'restore', $recordLabel, $docNum, meta: $meta);
    }

    /**
     * @param  array{type?: string, label?: mixed, doc_num?: mixed}|Model  $sourceRecord
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function crudCloned(string $resource, array|Model $sourceRecord, mixed $newRecordLabel, mixed $newDocNum, array $meta = []): array
    {
        return self::base($resource, 'clone', $newRecordLabel, $newDocNum, related: [
            'source' => self::normalizeRecord($sourceRecord, $resource),
        ], meta: $meta);
    }

    /**
     * @param  list<mixed>  $docNums
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function bulkDeleted(string $resource, int $count, array $docNums, array $meta = []): array
    {
        return self::clean([
            'action' => self::action($resource, 'bulk_delete'),
            'bulk' => [
                'count' => $count,
                'doc_nums' => self::stringList($docNums),
            ],
            'meta' => self::safeAssoc($meta),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function settingsUpdated(string $resource, array $changes, array $meta = []): array
    {
        return self::clean([
            'action' => self::action($resource, 'settings_update'),
            'changes' => self::changes($changes),
            'meta' => self::safeAssoc($meta),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function statusChanged(string $resource, mixed $recordLabel, mixed $docNum, mixed $oldStatus, mixed $newStatus, array $meta = []): array
    {
        return self::base($resource, 'status_change', $recordLabel, $docNum, [
            'status' => [
                'old' => self::safeScalar($oldStatus),
                'new' => self::safeScalar($newStatus),
            ],
        ], $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function documentNumberChanged(
        string $resource,
        mixed $recordLabel,
        mixed $oldDocNumber,
        mixed $oldDocNum,
        mixed $newDocNumber,
        mixed $newDocNum,
        array $meta = []
    ): array {
        return self::base($resource, 'doc_number_change', $recordLabel, $newDocNum, [
            'doc_number' => [
                'old' => self::safeScalar($oldDocNumber),
                'new' => self::safeScalar($newDocNumber),
            ],
            'doc_num' => [
                'old' => self::safeScalar($oldDocNum),
                'new' => self::safeScalar($newDocNum),
            ],
        ], $meta);
    }

    /**
     * @return array{type: string, label?: string, doc_num?: string}
     */
    public static function record(string $type, mixed $label, mixed $docNum): array
    {
        return self::clean([
            'type' => $type,
            'label' => self::safeScalar($label),
            'doc_num' => self::safeScalar($docNum),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function changes(array $changes): array
    {
        $normalized = [];

        foreach ($changes as $field => $change) {
            if (! is_string($field) || self::isSensitiveKey($field)) {
                continue;
            }

            if (is_array($change) && (array_key_exists('old', $change) || array_key_exists('new', $change))) {
                $normalized[$field] = [
                    'old' => self::safeValue($change['old'] ?? null),
                    'new' => self::safeValue($change['new'] ?? null),
                ];

                continue;
            }

            if (is_array($change) && array_is_list($change) && count($change) >= 2) {
                $normalized[$field] = [
                    'old' => self::safeValue($change[0]),
                    'new' => self::safeValue($change[1]),
                ];
            }
        }

        return self::clean($normalized);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $related
     * @return array<string, mixed>
     */
    private static function base(
        string $resource,
        string $actionType,
        mixed $recordLabel,
        mixed $docNum,
        array $changes = [],
        array $meta = [],
        array $related = [],
    ): array {
        return self::clean([
            'record' => self::record($resource, $recordLabel, $docNum),
            'action' => self::action($resource, $actionType),
            'changes' => self::changes($changes),
            'related' => self::safeAssoc($related),
            'meta' => self::safeAssoc($meta),
        ]);
    }

    /**
     * @return array{type: string, label_key: string}
     */
    private static function action(string $resource, string $type): array
    {
        return [
            'type' => $type,
            'label_key' => "{$resource}.actions.{$type}",
        ];
    }

    /**
     * @param  array{type?: string, label?: mixed, doc_num?: mixed}|Model  $record
     * @return array<string, mixed>
     */
    private static function normalizeRecord(array|Model $record, string $fallbackType): array
    {
        if ($record instanceof Model) {
            return self::record(
                $fallbackType,
                $record->getAttribute('name') ?: $record->getAttribute('title') ?: $record->getAttribute('label'),
                $record->getAttribute('doc_num'),
            );
        }

        return self::record(
            (string) ($record['type'] ?? $fallbackType),
            $record['label'] ?? null,
            $record['doc_num'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function safeAssoc(array $values): array
    {
        $safe = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || self::isSensitiveKey($key)) {
                continue;
            }

            $safe[$key] = self::safeValue($value);
        }

        return self::clean($safe);
    }

    private static function safeValue(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return self::normalizeRecord($value, $value->getTable());
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? self::stringList($value)
                : self::safeAssoc($value);
        }

        return self::safeScalar($value);
    }

    private static function safeScalar(mixed $value): string|int|float|bool|null
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value) || is_numeric($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): ?string => is_string($value) || is_numeric($value) ? trim((string) $value) : null)
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function clean(array $values): array
    {
        return collect($values)
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->all();
    }

    private static function isSensitiveKey(string $key): bool
    {
        return $key === 'id'
            || $key === 'internal_id'
            || str_ends_with($key, '_id')
            || str_contains($key, 'password')
            || str_contains($key, 'token')
            || str_contains($key, 'secret')
            || str_contains($key, 'session');
    }
}
