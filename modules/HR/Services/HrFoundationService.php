<?php

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\HR\Exceptions\HrLookupRestoreBlockedException;
use Modules\HR\Models\HrFoundationModel;

class HrFoundationService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(HrFoundationDefinition $definition, array $data): HrFoundationModel
    {
        return DB::transaction(function () use ($definition, $data): HrFoundationModel {
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber($definition, (int) $data['doc_number'])
                : $this->documentNumberService->next($definition->documentKey, $definition->modelClass);
            $values = $this->normalizedValues($definition, $data);

            /** @var HrFoundationModel $record */
            $record = $definition->modelClass::query()->create([
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($record);

            return $record->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: HrFoundationModel, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null, old_name: string}
     */
    public function update(HrFoundationDefinition $definition, HrFoundationModel $record, array $data): array
    {
        return DB::transaction(function () use ($definition, $record, $data): array {
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $oldName = $record->name;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newDocNumber = $canChangeDocumentNumber ? (int) $data['doc_number'] : $oldDocNumber;
            $newDocNum = $canChangeDocumentNumber ? $this->documentNumberService->format($definition->documentKey, $newDocNumber) : $oldDocNum;
            $newValues = $this->normalizedValues($definition, $data, $record);

            if ($canChangeDocumentNumber) {
                $newValues['doc_number'] = $newDocNumber;
                $newValues['doc_num'] = $newDocNum;
            }

            $changes = $this->changedValues($record, $newValues, $definition);
            $changedFields = collect(array_keys($changes))
                ->map(fn (string $field): string => $field === 'doc_num' ? 'doc_number' : $field)
                ->unique()
                ->values()
                ->all();

            if ($changedFields === []) {
                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                    'old_name' => $oldName,
                ];
            }

            $this->crudAudit->saveUpdate($record, $newValues);

            return [
                'record' => $record->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
                'old_name' => $oldName,
            ];
        });
    }

    public function delete(HrFoundationModel $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->crudAudit->softDelete($record);
        });
    }

    public function restore(HrFoundationDefinition $definition, HrFoundationModel $record): HrFoundationModel
    {
        return DB::transaction(function () use ($definition, $record): HrFoundationModel {
            $this->ensureRecordCanBeRestored($definition, $record);

            $this->crudAudit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(HrFoundationDefinition $definition, array $docNums): int
    {
        return DB::transaction(function () use ($definition, $docNums): int {
            $records = $definition->modelClass::query()
                ->whereIn('doc_num', $docNums)
                ->get();
            $deleted = 0;

            foreach ($records as $record) {
                $this->crudAudit->softDelete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(HrFoundationDefinition $definition, array $data, ?HrFoundationModel $existing = null): array
    {
        $values = [
            'name' => $this->normalizeString((string) ($data['name'] ?? $existing?->name ?? '')),
            'status' => (string) ($data['status'] ?? $existing?->status ?? 'active'),
            'notes' => $this->normalizeNullableString($data['notes'] ?? $existing?->notes),
        ];

        foreach ($definition->fields as $field) {
            $type = (string) ($field['type'] ?? 'text');
            $requestName = (string) $field['name'];
            $column = (string) ($field['column'] ?? $requestName);

            if (! array_key_exists($requestName, $data)) {
                if ($existing === null && array_key_exists('default', $field)) {
                    $values[$column] = $field['default'];
                }

                continue;
            }

            $values[$column] = match ($type) {
                'checkbox' => (bool) $data[$requestName],
                'number' => $this->normalizeNullableNumber($data[$requestName] ?? null),
                'decimal' => $this->normalizeNullableDecimal($data[$requestName] ?? null),
                'relation' => $this->resolveRelationId($field, $data[$requestName] ?? null),
                'time', 'date' => $this->normalizeNullableString($data[$requestName] ?? null),
                'weekdays' => $this->normalizeWeekdays($data[$requestName] ?? []),
                default => $this->normalizeNullableString($data[$requestName] ?? null),
            };
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function resolveRelationId(array $field, mixed $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        /** @var class-string<HrFoundationModel> $model */
        $model = $field['model'];

        return $model::query()
            ->where('doc_num', $docNum)
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(HrFoundationModel $record, array $newValues, HrFoundationDefinition $definition): array
    {
        $relationDocNums = $this->relationDocNums($definition, $record, $newValues);
        $changes = [];

        foreach ($newValues as $field => $newValue) {
            $oldValue = $record->getAttribute($field);

            if ($this->comparable($oldValue) === $this->comparable($newValue)) {
                continue;
            }

            if (str_ends_with($field, '_id')) {
                $docNums = $relationDocNums[$field] ?? null;

                if ($docNums !== null) {
                    $changes[str_replace('_id', '_doc_num', $field)] = $docNums;
                }

                continue;
            }

            $changes[$field] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: string|null, new: string|null}>
     */
    private function relationDocNums(HrFoundationDefinition $definition, HrFoundationModel $record, array $newValues): array
    {
        $docNums = [];

        foreach ($definition->fields as $field) {
            if (($field['type'] ?? null) !== 'relation') {
                continue;
            }

            $column = (string) $field['column'];

            if (! array_key_exists($column, $newValues)) {
                continue;
            }

            /** @var class-string<HrFoundationModel> $model */
            $model = $field['model'];
            $ids = array_values(array_filter([
                $record->getAttribute($column),
                $newValues[$column],
            ]));
            $records = $ids === []
                ? collect()
                : $model::withTrashed()->whereIn('id', $ids)->get(['id', 'doc_num'])->keyBy('id');

            $docNums[$column] = [
                'old' => $records->get($record->getAttribute($column))?->doc_num,
                'new' => $records->get($newValues[$column])?->doc_num,
            ];
        }

        return $docNums;
    }

    private function comparable(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode(array_values($value)) ?: '[]';
        }

        if (is_numeric($value)) {
            return (string) (float) $value;
        }

        return trim((string) $value);
    }

    private function normalizeString(string $value): string
    {
        return trim($value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeNullableNumber(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeNullableDecimal(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @return list<string>
     */
    private function normalizeWeekdays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $allowed = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

        return array_values(array_intersect($allowed, array_map('strval', $value)));
    }

    private function ensureRecordCanBeRestored(HrFoundationDefinition $definition, HrFoundationModel $record): void
    {
        if (! $record->trashed()) {
            throw new HrLookupRestoreBlockedException(
                __('hr.messages.restore_not_allowed'),
                'already_active',
            );
        }

        $conflictFields = $this->restoreConflictFields($definition, $record);

        if ($conflictFields !== []) {
            throw new HrLookupRestoreBlockedException(
                __('hr.messages.restore_conflict'),
                $this->restoreConflictType($conflictFields),
                $conflictFields,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function restoreConflictFields(HrFoundationDefinition $definition, HrFoundationModel $record): array
    {
        $conflictFields = [];

        if ($this->hasComparableValue($record->name) && $this->activeRecordExists($definition, function (Builder $query) use ($record): void {
            $query->where('name', $record->name);
        })) {
            $conflictFields[] = 'name';
        }

        if ($record->doc_number !== null && $this->activeRecordExists($definition, function (Builder $query) use ($record): void {
            $query->where('doc_number', $record->doc_number);
        })) {
            $conflictFields[] = 'doc_number';
        }

        if ($this->hasComparableValue($record->doc_num) && $this->activeRecordExists($definition, function (Builder $query) use ($record): void {
            $query->where('doc_num', $record->doc_num);
        })) {
            $conflictFields[] = 'doc_num';
        }

        foreach ($definition->fields as $field) {
            if (($field['unique'] ?? false) !== true) {
                continue;
            }

            $column = (string) ($field['column'] ?? $field['name']);
            $value = $record->getAttribute($column);

            if (! $this->hasComparableValue($value)) {
                continue;
            }

            if ($this->activeRecordExists($definition, function (Builder $query) use ($column, $value): void {
                $query->where($column, $value);
            })) {
                $conflictFields[] = $column;
            }
        }

        return array_values(array_unique($conflictFields));
    }

    private function activeRecordExists(HrFoundationDefinition $definition, callable $constraint): bool
    {
        $query = $definition->modelClass::query()->whereNull('deleted_at');
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

        return 'name_conflict';
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function manualDocumentNumber(HrFoundationDefinition $definition, int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format($definition->documentKey, $docNumber),
        ];
    }

    private function hasComparableValue(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }
}
