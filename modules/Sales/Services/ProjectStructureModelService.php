<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\ProjectStructureModel;

class ProjectStructureModelService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ProjectStructureModel
    {
        return DB::transaction(function () use ($data): ProjectStructureModel {
            $companyId = $this->companies->requireCompanyId();
            $record = ProjectStructureModel::query()->create([
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
     * @return array{record: ProjectStructureModel, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null}
     */
    public function update(ProjectStructureModel $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $companyId = $this->companies->requireCompanyId();
            $this->assertBelongsToCompany($record, $companyId);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = $this->values($data, $companyId);

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, $companyId)];
            }

            $changes = $this->changes($record, $values);
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

    public function delete(ProjectStructureModel $record): void
    {
        $this->assertBelongsToCompany($record, $this->companies->requireCompanyId());

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

            foreach (ProjectStructureModel::query()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $record) {
                $this->delete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(ProjectStructureModel $record): ProjectStructureModel
    {
        return DB::transaction(function () use ($record): ProjectStructureModel {
            $companyId = $this->companies->requireCompanyId();
            $record = ProjectStructureModel::withTrashed()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $this->assertBelongsToCompany($record, $companyId);

            if (! $record->trashed()) {
                throw new DomainException(__('project_structure_models.messages.restore_not_allowed'));
            }

            if ($this->hasActiveRestoreConflict($record, $companyId)) {
                throw new DomainException(__('project_structure_models.messages.restore_conflict'));
            }

            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    public function resolve(string $docNum, bool $withTrashed = false): ProjectStructureModel
    {
        $query = $withTrashed ? ProjectStructureModel::withTrashed() : ProjectStructureModel::query();

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
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documentNumbers->format('project_structure_models', (int) $data['doc_number'])]
            : $this->documentNumbers->nextForCompany('project_structure_models', ProjectStructureModel::class, $companyId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data, int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'name' => trim((string) $data['name']),
            'code' => trim((string) $data['code']),
            'short_name' => trim((string) $data['short_name']),
            'status' => $data['status'] ?? 'active',
            'notes' => $this->blankToNull($data['notes'] ?? null),
        ];
    }

    private function hasActiveRestoreConflict(ProjectStructureModel $record, int $companyId): bool
    {
        return ProjectStructureModel::query()
            ->forCompany($companyId)
            ->whereKeyNot($record->getKey())
            ->where(function ($query) use ($record): void {
                $query->where('doc_num', $record->doc_num)
                    ->orWhere('doc_number', $record->doc_number)
                    ->orWhere('code', $record->code)
                    ->orWhere('short_name', $record->short_name);
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(ProjectStructureModel $record, array $values): array
    {
        $changes = [];

        foreach (['name', 'code', 'short_name', 'status', 'notes', 'doc_number', 'doc_num'] as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            if ((string) ($record->{$field} ?? '') !== (string) ($values[$field] ?? '')) {
                $changes[$field] = ['old' => $record->{$field}, 'new' => $values[$field]];
            }
        }

        return $changes;
    }

    private function assertBelongsToCompany(ProjectStructureModel $record, int $companyId): void
    {
        abort_unless((int) $record->company_id === $companyId, 404);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
