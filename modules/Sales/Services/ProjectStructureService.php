<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\ProjectStructure;

class ProjectStructureService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ProjectStructure
    {
        return DB::transaction(function () use ($data): ProjectStructure {
            $companyId = $this->companies->requireCompanyId();
            $record = ProjectStructure::query()->create([
                ...$this->values($data, $companyId),
                ...$this->document($data, $companyId),
                'created_by' => auth()->id(),
            ]);

            $this->audit->clearCreationUpdateAudit($record);

            return $record->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: ProjectStructure, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null}
     */
    public function update(ProjectStructure $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $companyId = $this->companies->requireCompanyId();
            $this->assertBelongsToCompany($record, $companyId);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $oldParentDocNum = $record->parent?->doc_num;
            $values = $this->values($data, $companyId, $record);

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, $companyId)];
            }

            $changes = $this->changes($record, $values, $oldParentDocNum);
            $changedFields = array_keys($changes);

            if ($changes === []) {
                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                ];
            }

            $this->audit->saveUpdate($record, $values);

            return [
                'record' => $record->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(ProjectStructure $record): void
    {
        $this->assertBelongsToCompany($record, $this->companies->requireCompanyId());

        if ($record->children()->exists()) {
            throw new DomainException(__('project_structures.messages.delete_blocked_children'));
        }

        DB::transaction(function () use ($record): void {
            $this->audit->softDelete($record);
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $deleted = 0;
            $companyId = $this->companies->requireCompanyId();

            foreach (ProjectStructure::query()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $record) {
                $this->delete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(ProjectStructure $record): ProjectStructure
    {
        return DB::transaction(function () use ($record): ProjectStructure {
            $companyId = $this->companies->requireCompanyId();
            $record = ProjectStructure::withTrashed()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertBelongsToCompany($record, $companyId);

            if (! $record->trashed()) {
                throw new DomainException(__('project_structures.messages.restore_not_allowed'));
            }

            if ($record->parent_id !== null && ! ProjectStructure::query()->forCompany($companyId)->active()->whereKey($record->parent_id)->exists()) {
                throw new DomainException(__('project_structures.messages.parent_restore_unavailable'));
            }

            if ($this->hasActiveRestoreConflict($record, $companyId)) {
                throw new DomainException(__('project_structures.messages.restore_conflict'));
            }

            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    public function resolve(string $docNum, bool $withTrashed = false): ProjectStructure
    {
        $query = $withTrashed ? ProjectStructure::withTrashed() : ProjectStructure::query();

        return $query
            ->forCompany($this->companies->requireCompanyId())
            ->where('doc_num', $docNum)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{doc_number: int, doc_num: string}
     */
    private function document(array $data, int $companyId): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documentNumbers->format('project_structures', (int) $data['doc_number'])]
            : $this->documentNumbers->nextForCompany('project_structures', ProjectStructure::class, $companyId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data, int $companyId, ?ProjectStructure $current = null): array
    {
        $parent = $this->parentFromData($data, $companyId);

        return [
            'company_id' => $companyId,
            'parent_id' => $parent?->getKey(),
            'name' => trim((string) $data['name']),
            'code' => trim((string) $data['code']),
            'status' => $data['status'] ?? 'active',
            'notes' => $this->blankToNull($data['notes'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? $current?->sort_order ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parentFromData(array $data, int $companyId): ?ProjectStructure
    {
        $docNum = trim((string) ($data['parent_doc_num'] ?? ''));

        if ($docNum === '') {
            return null;
        }

        return ProjectStructure::query()
            ->forCompany($companyId)
            ->active()
            ->where('doc_num', $docNum)
            ->first();
    }

    private function hasActiveRestoreConflict(ProjectStructure $record, int $companyId): bool
    {
        return ProjectStructure::query()
            ->forCompany($companyId)
            ->whereKeyNot($record->getKey())
            ->where(function ($query) use ($record): void {
                $query->where('doc_num', $record->doc_num)
                    ->orWhere('doc_number', $record->doc_number)
                    ->orWhere('code', $record->code);
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(ProjectStructure $record, array $values, ?string $oldParentDocNum): array
    {
        $changes = [];

        foreach (['name', 'code', 'status', 'notes', 'sort_order', 'doc_number', 'doc_num'] as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            if ((string) ($record->{$field} ?? '') !== (string) ($values[$field] ?? '')) {
                $changes[$field] = ['old' => $record->{$field}, 'new' => $values[$field]];
            }
        }

        if ((int) ($record->parent_id ?? 0) !== (int) ($values['parent_id'] ?? 0)) {
            $newParentDocNum = isset($values['parent_id'])
                ? ProjectStructure::query()->whereKey($values['parent_id'])->value('doc_num')
                : null;
            $changes['parent_doc_num'] = ['old' => $oldParentDocNum, 'new' => $newParentDocNum];
        }

        return $changes;
    }

    private function assertBelongsToCompany(ProjectStructure $record, int $companyId): void
    {
        abort_unless((int) $record->company_id === $companyId, 404);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
