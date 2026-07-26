<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Exceptions\ItemLookupRestoreBlockedException;
use Modules\Core\Models\ItemLookup;
use Modules\Core\Models\ItemUnit;

class ItemLookupService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly NumericFormatService $numbers,
    ) {}

    /**
     * @param  array{name: string, status: string, doc_number?: int, notes?: string|null, equivalent_value?: string|null, equivalent_unit_id?: int|null}  $data
     */
    public function create(ItemLookupDefinition $definition, array $data): ItemLookup
    {
        return DB::transaction(function () use ($definition, $data): ItemLookup {
            $companyId = $this->companyContext->requireCompanyId();
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber($definition, (int) $data['doc_number'])
                : $this->documentNumberService->nextForCompany($definition->documentKey, $definition->modelClass, $companyId);

            /** @var ItemLookup $record */
            $record = $definition->modelClass::query()->create([
                'company_id' => $companyId,
                'name' => trim((string) $data['name']),
                'status' => $data['status'] ?? 'active',
                'notes' => $this->normalizeNullableString($data['notes'] ?? null),
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
                ...$this->itemUnitEquivalenceValues($definition, $data),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($record);

            return $record->refresh();
        });
    }

    /**
     * @param  array{name: string, status: string, doc_number?: int, notes?: string|null, equivalent_value?: string|null, equivalent_unit_id?: int|null}  $data
     * @return array{record: ItemLookup, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null, old_name: string}
     */
    public function update(ItemLookupDefinition $definition, ItemLookup $record, array $data): array
    {
        return DB::transaction(function () use ($definition, $record, $data): array {
            $this->assertRecordBelongsToCurrentCompany($record);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $oldName = $record->name;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newDocNumber = $canChangeDocumentNumber ? (int) $data['doc_number'] : $oldDocNumber;
            $newDocNum = $canChangeDocumentNumber ? $this->documentNumberService->format($definition->documentKey, $newDocNumber) : $oldDocNum;
            $newValues = [
                'name' => trim((string) $data['name']),
                'status' => $data['status'] ?? 'active',
                'notes' => $this->normalizeNullableString($data['notes'] ?? null),
                'doc_number' => $canChangeDocumentNumber ? $newDocNumber : $record->doc_number,
                'doc_num' => $canChangeDocumentNumber ? $newDocNum : $record->doc_num,
                ...$this->itemUnitEquivalenceValues($definition, $data),
            ];
            $changedFields = [];
            $changes = [];

            foreach ($this->trackedFields($definition) as $field) {
                if (! array_key_exists($field, $newValues)) {
                    continue;
                }

                if ($this->valuesAreDifferent($field, $record->{$field} ?? null, $newValues[$field])) {
                    $changedFields[] = $field;
                    $changes[$this->changeLogField($field)] = [
                        'old' => $this->changeLogValue($field, $record->{$field} ?? null),
                        'new' => $this->changeLogValue($field, $newValues[$field]),
                    ];
                }
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

    public function delete(ItemLookup $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertRecordBelongsToCurrentCompany($record);
            $this->crudAudit->softDelete($record);
        });
    }

    public function restore(ItemLookupDefinition $definition, ItemLookup $record): ItemLookup
    {
        return DB::transaction(function () use ($definition, $record): ItemLookup {
            $this->assertRecordBelongsToCurrentCompany($record);
            $this->ensureRecordCanBeRestored($definition, $record);

            $this->crudAudit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(ItemLookupDefinition $definition, array $docNums): int
    {
        return DB::transaction(function () use ($definition, $docNums): int {
            $records = $definition->modelClass::query()
                ->forCompany($this->companyContext->requireCompanyId())
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

    private function normalizeNullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function itemUnitEquivalenceValues(ItemLookupDefinition $definition, array $data): array
    {
        if ($definition->key !== 'item_units') {
            return [];
        }

        return [
            'equivalent_value' => $this->normalizeNullableDecimal($data['equivalent_value'] ?? null),
            'equivalent_unit_id' => $this->normalizeNullableInteger($data['equivalent_unit_id'] ?? null),
        ];
    }

    private function normalizeNullableDecimal(mixed $value): ?string
    {
        return $this->numbers->normalizeToScale($value, 6);
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private function trackedFields(ItemLookupDefinition $definition): array
    {
        $fields = ['name', 'status', 'notes'];

        if ($definition->key === 'item_units') {
            $fields[] = 'equivalent_value';
            $fields[] = 'equivalent_unit_id';
        }

        return $fields;
    }

    private function valuesAreDifferent(string $field, mixed $oldValue, mixed $newValue): bool
    {
        if ($field === 'equivalent_value') {
            return ! $this->numbers->equivalent($oldValue, $newValue);
        }

        return $this->comparableValue($oldValue) !== $this->comparableValue($newValue);
    }

    private function comparableValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function changeLogField(string $field): string
    {
        return $field === 'equivalent_unit_id' ? 'equivalent_unit' : $field;
    }

    private function changeLogValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'equivalent_unit_id' => $this->itemUnitLabelById($value),
            'equivalent_value' => $this->displayDecimal($value),
            default => $value,
        };
    }

    private function itemUnitLabelById(mixed $id): ?string
    {
        $id = $this->normalizeNullableInteger($id);

        if ($id === null) {
            return null;
        }

        $unit = ItemUnit::withTrashed()
            ->select(['id', 'doc_num', 'name'])
            ->whereKey($id)
            ->first();

        return $unit ? trim(implode(' / ', array_filter([$unit->doc_num, $unit->name]))) : null;
    }

    private function displayDecimal(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : $this->numbers->format($value);
    }

    private function ensureRecordCanBeRestored(ItemLookupDefinition $definition, ItemLookup $record): void
    {
        if (! $record->trashed()) {
            throw new ItemLookupRestoreBlockedException(
                __('item_lookups.messages.restore_not_allowed'),
                'already_active',
            );
        }

        $conflictFields = $this->restoreConflictFields($definition, $record);

        if ($conflictFields !== []) {
            throw new ItemLookupRestoreBlockedException(
                __('item_lookups.messages.restore_conflict'),
                $this->restoreConflictType($conflictFields),
                $conflictFields,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function restoreConflictFields(ItemLookupDefinition $definition, ItemLookup $record): array
    {
        $conflictFields = [];
        $companyId = (int) $record->company_id;

        foreach (['name', 'doc_number', 'doc_num'] as $field) {
            if (! $this->hasComparableValue($record->{$field} ?? null)) {
                continue;
            }

            if ($this->activeRecordExists($definition, function (Builder $query) use ($field, $record, $companyId): void {
                $query->where('company_id', $companyId);
                $query->where($field, $record->{$field});
            })) {
                $conflictFields[] = $field;
            }
        }

        return array_values(array_unique($conflictFields));
    }

    private function activeRecordExists(ItemLookupDefinition $definition, callable $constraint): bool
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
    private function manualDocumentNumber(ItemLookupDefinition $definition, int $docNumber): array
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

    private function assertRecordBelongsToCurrentCompany(ItemLookup $record): void
    {
        abort_unless((int) $record->company_id === $this->companyContext->requireCompanyId(), 404);
    }
}
