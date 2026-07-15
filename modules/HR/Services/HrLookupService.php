<?php

namespace Modules\HR\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\HR\Exceptions\HrLookupRestoreBlockedException;
use Modules\HR\Models\HrLookupModel;

class HrLookupService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
    ) {}

    /**
     * @param  array{name: string, doc_number?: int, notes?: string|null, country_id?: int|null, governorate_id?: int|null, city_id?: int|null}  $data
     */
    public function create(HrLookupDefinition $definition, array $data): HrLookupModel
    {
        return DB::transaction(function () use ($definition, $data): HrLookupModel {
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber($definition, (int) $data['doc_number'])
                : $this->documentNumberService->next($definition->documentKey, $definition->modelClass);

            /** @var HrLookupModel $record */
            $record = $definition->modelClass::query()->create([
                'name' => trim((string) $data['name']),
                'notes' => $this->normalizeNotes($data['notes'] ?? null),
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                ...$this->locationParentPayload($data),
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($record);

            return $record->refresh();
        });
    }

    /**
     * @param  array{name: string, doc_number?: int, notes?: string|null}  $data
     * @return array{record: HrLookupModel, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null, old_name: string}
     */
    public function update(HrLookupDefinition $definition, HrLookupModel $record, array $data): array
    {
        return DB::transaction(function () use ($definition, $record, $data): array {
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $oldName = $record->name;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newDocNumber = $canChangeDocumentNumber ? (int) $data['doc_number'] : $oldDocNumber;
            $newDocNum = $canChangeDocumentNumber ? $this->documentNumberService->format($definition->documentKey, $newDocNumber) : $oldDocNum;
            $newName = trim((string) $data['name']);
            $newNotes = $this->normalizeNotes($data['notes'] ?? null);
            $changedFields = [];
            $changes = [];

            if ($record->name !== $newName) {
                $changedFields[] = 'name';
                $changes['name'] = [
                    'old' => $record->name,
                    'new' => $newName,
                ];
            }

            if ($canChangeDocumentNumber && ($oldDocNumber !== $newDocNumber || $oldDocNum !== $newDocNum)) {
                $changedFields[] = 'doc_number';
                $changes['doc_number'] = [
                    'old' => $oldDocNumber,
                    'new' => $newDocNumber,
                ];
                $changes['doc_num'] = [
                    'old' => $oldDocNum,
                    'new' => $newDocNum,
                ];
            }

            if (($record->notes ?? null) !== $newNotes) {
                $changedFields[] = 'notes';
                $changes['notes'] = [
                    'old' => $record->notes,
                    'new' => $newNotes,
                ];
            }

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

            $this->crudAudit->saveUpdate($record, [
                'name' => $newName,
                'notes' => $newNotes,
                'doc_number' => $canChangeDocumentNumber ? $newDocNumber : $record->doc_number,
                'doc_num' => $canChangeDocumentNumber ? $newDocNum : $record->doc_num,
            ]);

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

    public function delete(HrLookupModel $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->crudAudit->softDelete($record);
        });
    }

    public function restore(HrLookupDefinition $definition, HrLookupModel $record): HrLookupModel
    {
        return DB::transaction(function () use ($definition, $record): HrLookupModel {
            $this->ensureRecordCanBeRestored($definition, $record);

            $this->crudAudit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(HrLookupDefinition $definition, array $docNums): int
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

    private function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : $notes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function locationParentPayload(array $data): array
    {
        $payload = [];

        foreach (['country_id', 'governorate_id', 'city_id'] as $field) {
            if (array_key_exists($field, $data) && $data[$field]) {
                $payload[$field] = (int) $data[$field];
            }
        }

        return $payload;
    }

    private function ensureRecordCanBeRestored(HrLookupDefinition $definition, HrLookupModel $record): void
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
    private function restoreConflictFields(HrLookupDefinition $definition, HrLookupModel $record): array
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

        return array_values(array_unique($conflictFields));
    }

    private function activeRecordExists(HrLookupDefinition $definition, callable $constraint): bool
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
    private function manualDocumentNumber(HrLookupDefinition $definition, int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format($definition->documentKey, $docNumber),
        ];
    }

    private function hasComparableValue(?string $value): bool
    {
        return trim((string) $value) !== '';
    }
}
