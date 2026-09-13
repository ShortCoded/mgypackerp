<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;

class BranchService
{
    /**
     * @var list<string>
     */
    private array $fillableFields = [
        'name',
        'company_id',
        'type',
        'address',
        'attendance_latitude',
        'attendance_longitude',
        'attendance_radius_meters',
        'attendance_max_accuracy_meters',
        'attendance_location_policy',
        'camera_url',
        'phone',
        'mobile',
        'email',
        'hotline',
        'contact_person',
        'notes',
        'status',
    ];

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: Branch}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber((int) $data['doc_number'])
                : $this->documentNumberService->next('branches', Branch::class);
            $values = $this->normalizedValues($data);

            $record = Branch::query()->create([
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);

            $this->syncStationHalls($record, $data);
            $this->syncBranchStores($record, $data);
            $this->crudAudit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load('halls', 'stores')];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: Branch, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null}
     */
    public function update(Branch $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newValues = $this->normalizedValues($data, $record);
            $hallChanges = $this->hallChanges($record, $data);
            $storeChanges = $this->storeChanges($record, $data);

            if ($canChangeDocumentNumber) {
                $newValues['doc_number'] = (int) $data['doc_number'];
                $newValues['doc_num'] = $this->documentNumberService->format('branches', (int) $data['doc_number']);
            }

            $changes = $this->changedValues($record, $newValues);
            if ($hallChanges !== null) {
                $changes['station_halls'] = $hallChanges;
            }
            if ($storeChanges !== null) {
                $changes['branch_stores'] = $storeChanges;
            }
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
                ];
            }

            $this->crudAudit->saveUpdate($record, [
                ...$newValues,
            ]);
            $this->syncStationHalls($record, $data);
            $this->syncBranchStores($record, $data);

            return [
                'record' => $record->refresh()->load('halls', 'stores'),
                'changed' => true,
                'changed_fields' => $changedFields,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(Branch $record): void
    {
        $this->ensureCanDelete($record);

        DB::transaction(function () use ($record): void {
            $this->crudAudit->softDelete($record);
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $records = Branch::query()
                ->whereIn('doc_num', $docNums)
                ->get();

            // Validate the complete selection before deleting anything so bulk delete stays all-or-nothing.
            foreach ($records as $record) {
                $this->ensureCanDelete($record);
            }

            $deleted = 0;

            foreach ($records as $record) {
                $this->crudAudit->softDelete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(Branch $record): Branch
    {
        return DB::transaction(function () use ($record): Branch {
            $record = Branch::withTrashed()
                ->lockForUpdate()
                ->whereKey($record->getKey())
                ->firstOrFail();

            $this->ensureCanRestore($record);
            $this->crudAudit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    protected function ensureCanDelete(Branch $record): void
    {
        // TODO: Add resource-specific dependency checks before enabling deletes for referenced business records.
        // This default is only safe for independent resources. Throw DomainException with a translated message
        // when this record is protected, referenced by another table, or otherwise blocked by business rules.
    }

    protected function ensureCanRestore(Branch $record): void
    {
        if (! $record->trashed()) {
            throw new DomainException(__('branches.messages.restore_not_allowed'));
        }

        if (Branch::query()
            ->where('company_id', $record->company_id)
            ->where('name', $record->name)
            ->whereKeyNot($record->getKey())
            ->exists()) {
            throw new DomainException(__('branches.messages.restore_conflict'));
        }
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function manualDocumentNumber(int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format('branches', $docNumber),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data, ?Branch $existing = null): array
    {
        $values = [];

        if (array_key_exists('company_doc_num', $data)) {
            $values['company_id'] = $this->companyIdForDocNum((string) $data['company_doc_num']);
        }

        foreach ($this->fillableFields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $values[$field] = $this->normalizeValue($field, $data[$field]);
        }

        return $values;
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'name' => $this->normalizeString((string) $value),
            'company_id' => (int) $value,
            'type' => $this->normalizeString((string) $value),
            'address' => $this->normalizeNullableString($value),
            'attendance_latitude', 'attendance_longitude' => $value === null || $value === '' ? null : (float) $value,
            'attendance_radius_meters', 'attendance_max_accuracy_meters' => (int) $value,
            'attendance_location_policy' => $this->normalizeString((string) $value),
            'camera_url' => $this->normalizeNullableString($value),
            'phone' => $this->normalizeNullableString($value),
            'mobile' => $this->normalizeNullableString($value),
            'email' => $this->normalizeNullableString($value),
            'hotline' => $this->normalizeNullableString($value),
            'contact_person' => $this->normalizeNullableString($value),
            'notes' => $this->normalizeNullableString($value),
            'status' => $this->normalizeString((string) $value),
            default => $this->normalizeNullableString($value),
        };
    }

    private function companyIdForDocNum(string $docNum): int
    {
        return (int) Company::query()
            ->active()
            ->where('doc_num', trim($docNum))
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(Branch $record, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $value) {
            $current = $record->{$field};

            if ($current instanceof \DateTimeInterface) {
                $current = $current->format('Y-m-d H:i:s');
            }

            if ((string) ($current ?? '') !== (string) ($value ?? '')) {
                $changes[$field] = [
                    'old' => $current,
                    'new' => $value,
                ];
            }
        }

        return $changes;
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

    /**
     * @param  array<string, mixed>  $data
     * @return array{old: list<array<string, mixed>>, new: list<array<string, mixed>>}|null
     */
    private function hallChanges(Branch $record, array $data): ?array
    {
        if (! array_key_exists('station_halls', $data)) {
            return null;
        }

        $old = $this->currentHalls($record);
        $new = $this->normalizedHalls($data);

        if ($old === $new) {
            return null;
        }

        return [
            'old' => $old,
            'new' => $new,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncStationHalls(Branch $record, array $data): void
    {
        if (! array_key_exists('station_halls', $data)) {
            return;
        }

        $halls = $this->normalizedHalls($data);
        $existing = $record->halls()
            ->get()
            ->keyBy('public_uuid');
        $existingByName = $existing->keyBy(fn (BranchHall $hall): string => $this->normalizedDetailName($hall->name));
        $submittedKeys = [];

        foreach ($halls as $index => $hallData) {
            $key = (string) ($hallData['key'] ?? '');
            $hall = $key !== ''
                ? $existing->get($key)
                : $existingByName->get($this->normalizedDetailName($hallData['name']));

            if ($hall instanceof BranchHall) {
                $submittedKeys[] = (string) $hall->public_uuid;
                $hall->fill([
                    'name' => $hallData['name'],
                    'position' => $index + 1,
                ]);

                if ($hall->isDirty()) {
                    $hall->updated_by = auth()->id();
                    $hall->save();
                }

                continue;
            }

            BranchHall::query()->create([
                'branch_id' => $record->getKey(),
                'name' => $hallData['name'],
                'position' => $index + 1,
                'created_by' => auth()->id(),
            ]);
        }

        $this->softDeleteMissingHalls($existing, $submittedKeys);
    }

    /**
     * @param  Collection<string, BranchHall>  $existing
     * @param  list<string>  $submittedKeys
     */
    private function softDeleteMissingHalls(Collection $existing, array $submittedKeys): void
    {
        $existing
            ->reject(fn (BranchHall $hall, string $key): bool => in_array($key, $submittedKeys, true))
            ->each(function (BranchHall $hall): void {
                $hall->forceFill(['deleted_by' => auth()->id()])->save();
                $hall->delete();
            });
    }

    private function normalizedDetailName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{key: string|null, name: string}>
     */
    private function normalizedHalls(array $data): array
    {
        if (($data['type'] ?? null) !== Branch::TypeFactory) {
            return [];
        }

        $halls = $data['station_halls'] ?? [];

        if (! is_array($halls)) {
            return [];
        }

        $payload = [];

        foreach ($halls as $hall) {
            $row = is_array($hall) ? $hall : ['name' => $hall];
            $name = $this->normalizeString((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $payload[] = [
                'key' => $this->normalizeNullableString($row['key'] ?? null),
                'name' => $name,
            ];
        }

        return $payload;
    }

    /**
     * @return list<array{key: string|null, name: string}>
     */
    private function currentHalls(Branch $record): array
    {
        return $record->halls()
            ->get()
            ->map(fn (BranchHall $hall): array => [
                'key' => $hall->public_uuid,
                'name' => trim($hall->name),
            ])
            ->filter(fn (array $hall): bool => $hall['name'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{old: list<array<string, mixed>>, new: list<array<string, mixed>>}|null
     */
    private function storeChanges(Branch $record, array $data): ?array
    {
        if (! array_key_exists('branch_stores', $data)) {
            return null;
        }

        $old = $this->currentStores($record);
        $new = $this->normalizedStores($data);

        if ($old === $new) {
            return null;
        }

        return [
            'old' => $old,
            'new' => $new,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncBranchStores(Branch $record, array $data): void
    {
        if (! array_key_exists('branch_stores', $data)) {
            return;
        }

        $stores = $this->normalizedStores($data);
        $existing = $record->stores()
            ->get()
            ->keyBy('public_uuid');
        $existingByName = $existing->keyBy(fn (BranchStore $store): string => $this->normalizedDetailName($store->name));
        $submittedKeys = [];

        foreach ($stores as $index => $storeData) {
            $key = (string) ($storeData['key'] ?? '');
            $store = $key !== ''
                ? $existing->get($key)
                : $existingByName->get($this->normalizedDetailName($storeData['name']));

            if ($store instanceof BranchStore) {
                $submittedKeys[] = (string) $store->public_uuid;
                $store->fill([
                    'name' => $storeData['name'],
                    'classification' => $storeData['classification'],
                    'position' => $index + 1,
                ]);

                if ($store->isDirty()) {
                    $store->updated_by = auth()->id();
                    $store->save();
                }

                continue;
            }

            BranchStore::query()->create([
                'branch_id' => $record->getKey(),
                'name' => $storeData['name'],
                'classification' => $storeData['classification'],
                'position' => $index + 1,
                'created_by' => auth()->id(),
            ]);
        }

        $this->softDeleteMissingStores($existing, $submittedKeys);
    }

    /**
     * @param  Collection<string, BranchStore>  $existing
     * @param  list<string>  $submittedKeys
     */
    private function softDeleteMissingStores(Collection $existing, array $submittedKeys): void
    {
        $existing
            ->reject(fn (BranchStore $store, string $key): bool => in_array($key, $submittedKeys, true))
            ->each(function (BranchStore $store): void {
                $store->forceFill(['deleted_by' => auth()->id()])->save();
                $store->delete();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{key: string|null, name: string, classification: string}>
     */
    private function normalizedStores(array $data): array
    {
        $stores = $data['branch_stores'] ?? [];

        if (! is_array($stores)) {
            return [];
        }

        $payload = [];

        foreach ($stores as $store) {
            $row = is_array($store) ? $store : ['name' => $store];
            $name = $this->normalizeString((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $payload[] = [
                'key' => $this->normalizeNullableString($row['key'] ?? null),
                'name' => $name,
                'classification' => $this->normalizeString((string) ($row['classification'] ?? BranchStore::ClassificationGeneral)),
            ];
        }

        return $payload;
    }

    /**
     * @return list<array{key: string|null, name: string, classification: string}>
     */
    private function currentStores(Branch $record): array
    {
        return $record->stores()
            ->get()
            ->map(fn (BranchStore $store): array => [
                'key' => $store->public_uuid,
                'name' => trim($store->name),
                'classification' => $store->classification,
            ])
            ->filter(fn (array $store): bool => $store['name'] !== '')
            ->values()
            ->all();
    }
}
