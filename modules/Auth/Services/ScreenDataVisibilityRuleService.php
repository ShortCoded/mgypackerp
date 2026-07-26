<?php

namespace Modules\Auth\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\ScreenDataVisibilityRule;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ScreenDataVisibilityService;

class ScreenDataVisibilityRuleService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
        private readonly ScreenDataVisibilityService $visibility,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): ScreenDataVisibilityRule
    {
        return DB::transaction(function () use ($data): ScreenDataVisibilityRule {
            $companyId = $this->companies->requireCompanyId();
            $document = $this->documentNumbers->nextForCompany('screen_data_visibility_rules', ScreenDataVisibilityRule::class, $companyId);
            $record = ScreenDataVisibilityRule::query()->create([
                ...$this->values($data),
                ...$document,
                'company_id' => $companyId,
                'created_by' => auth()->id(),
            ]);
            $this->audit->clearCreationUpdateAudit($record);
            $this->visibility->forgetRule($record);

            return $record->refresh();
        });
    }

    /** @param array<string, mixed> $data @return array{record: ScreenDataVisibilityRule, changed: bool, changes: array<string, array{old: mixed, new: mixed}>} */
    public function update(ScreenDataVisibilityRule $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $values = $this->values($data);
            $oldRule = clone $record;
            $record->fill($values);
            $changes = [];

            foreach (array_keys($record->getDirty()) as $field) {
                $changes[$field] = [
                    'old' => $record->getRawOriginal($field),
                    'new' => $record->getAttributes()[$field] ?? null,
                ];
            }

            if ($changes === []) {
                return ['record' => $record->refresh(), 'changed' => false, 'changes' => []];
            }

            $this->audit->saveUpdate($record, $values);
            $this->visibility->forgetRule($oldRule);
            $this->visibility->forgetRule($record);

            return ['record' => $record->refresh(), 'changed' => true, 'changes' => $changes];
        });
    }

    public function delete(ScreenDataVisibilityRule $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->audit->softDelete($record);
            $this->visibility->forgetRule($record);
        });
    }

    /** @param list<string> $docNums @return Collection<int, ScreenDataVisibilityRule> */
    public function bulkDelete(array $docNums): Collection
    {
        return DB::transaction(function () use ($docNums): Collection {
            $records = ScreenDataVisibilityRule::query()
                ->forCompany($this->companies->requireCompanyId())
                ->whereIn('doc_num', $docNums)
                ->get();

            $records->each(function (ScreenDataVisibilityRule $record): void {
                $this->audit->softDelete($record);
                $this->visibility->forgetRule($record);
            });

            return $records;
        });
    }

    public function restore(ScreenDataVisibilityRule $record): ScreenDataVisibilityRule
    {
        return DB::transaction(function () use ($record): ScreenDataVisibilityRule {
            if ($record->is_active && ScreenDataVisibilityRule::query()
                ->forCompany((int) $record->company_id)
                ->where('user_id', $record->user_id)
                ->where('screen_key', $record->screen_key)
                ->where('is_active', true)
                ->whereKeyNot($record->getKey())
                ->exists()) {
                throw new DomainException(__('screen_data_visibility_rules.validation.active_rule_exists'));
            }

            $this->audit->restore($record, auth()->id());
            $this->visibility->forgetRule($record);

            return $record->refresh();
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function values(array $data): array
    {
        $user = User::query()->where('doc_num', $data['user_doc_num'])->firstOrFail();

        return [
            'user_id' => $user->getKey(),
            'screen_key' => $data['screen_key'],
            'record_scope' => $data['record_scope'],
            'max_visible_records' => $data['max_visible_records'] ?? null,
            'duration_value' => $data['duration_value'] ?? null,
            'duration_unit' => ($data['duration_value'] ?? null) === null ? null : ($data['duration_unit'] ?? null),
            'is_active' => (bool) $data['is_active'],
            'notes' => $data['notes'] ?? null,
        ];
    }
}
