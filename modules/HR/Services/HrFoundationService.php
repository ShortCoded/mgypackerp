<?php

namespace Modules\HR\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Company;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Exceptions\HrLookupRestoreBlockedException;
use Modules\HR\Models\HrEmploymentTaxPolicy;
use Modules\HR\Models\HrFoundationModel;
use Modules\HR\Models\HrSocialInsurancePolicy;

class HrFoundationService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companies,
        private readonly NumericFormatService $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(HrFoundationDefinition $definition, array $data): HrFoundationModel
    {
        return DB::transaction(function () use ($definition, $data): HrFoundationModel {
            if ($this->isStatutoryPolicy($definition)) {
                $companyId = $this->companies->requireCompanyId();
                $this->lockPolicyCompany($companyId);
                $this->ensurePolicyPeriodAvailable($definition, $data, null, $companyId);
            }

            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber($definition, (int) $data['doc_number'])
                : $this->documentNumberService->next($definition->documentKey, $definition->modelClass);
            $values = $this->normalizedValues($definition, $data);

            if ($definition->companyScoped) {
                $values['company_id'] = $this->companies->requireCompanyId();
            }

            /** @var HrFoundationModel $record */
            $record = $definition->modelClass::query()->create([
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($record);

            if ($definition->hasTaxBrackets && $record instanceof HrEmploymentTaxPolicy) {
                $this->syncTaxBrackets($record, $data['tax_brackets'] ?? []);
            }

            if ($definition->hasInsuranceComponents && $record instanceof HrSocialInsurancePolicy) {
                $this->syncInsuranceComponents($record, $data['insurance_components'] ?? []);
            }

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
            if ($this->isStatutoryPolicy($definition)) {
                $companyId = (int) $record->getAttribute('company_id');
                $this->lockPolicyCompany($companyId);
                $this->ensurePolicyPeriodAvailable($definition, $data, $record, $companyId);
            }

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
            $taxBracketChanges = $definition->hasTaxBrackets && $record instanceof HrEmploymentTaxPolicy
                ? $this->taxBracketChanges($record, $data['tax_brackets'] ?? [])
                : null;
            $insuranceComponentChanges = $definition->hasInsuranceComponents && $record instanceof HrSocialInsurancePolicy
                ? $this->insuranceComponentChanges($record, $data['insurance_components'] ?? [])
                : null;

            if ($taxBracketChanges !== null) {
                $changes['tax_brackets'] = $taxBracketChanges;
            }
            if ($insuranceComponentChanges !== null) {
                $changes['insurance_components'] = $insuranceComponentChanges;
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
                    'old_name' => $oldName,
                ];
            }

            if (array_diff($changedFields, ['tax_brackets', 'insurance_components']) !== []) {
                $this->crudAudit->saveUpdate($record, $newValues);
            }

            if ($taxBracketChanges !== null && $record instanceof HrEmploymentTaxPolicy) {
                $this->syncTaxBrackets($record, $data['tax_brackets'] ?? []);

                if (array_diff($changedFields, ['tax_brackets', 'insurance_components']) === []) {
                    $this->crudAudit->touchUpdateAudit($record);
                }
            }

            if ($insuranceComponentChanges !== null && $record instanceof HrSocialInsurancePolicy) {
                $this->syncInsuranceComponents($record, $data['insurance_components'] ?? []);

                if ($taxBracketChanges === null && array_diff($changedFields, ['tax_brackets', 'insurance_components']) === []) {
                    $this->crudAudit->touchUpdateAudit($record);
                }
            }

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
            if ($this->isStatutoryPolicy($definition)) {
                $this->lockPolicyCompany((int) $record->getAttribute('company_id'));
            }

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
                ->when($definition->companyScoped, fn (Builder $query): Builder => $this->companies->applyCompanyScope($query, $definition->table))
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
                'decimal' => $this->normalizeNullableDecimal($data[$requestName] ?? null, (int) ($field['scale'] ?? 2)),
                'relation' => $this->resolveRelationId($field, $data[$requestName] ?? null),
                'time', 'date' => $this->normalizeNullableString($data[$requestName] ?? null),
                'weekdays' => $this->normalizeWeekdays($data[$requestName] ?? []),
                default => $this->normalizeNullableString($data[$requestName] ?? null),
            };
        }

        if ($definition->hasInsuranceComponents) {
            $totals = $this->insuranceComponentTotals($data['insurance_components'] ?? []);
            $values['employee_contribution_rate'] = $totals['employee_rate'];
            $values['employer_contribution_rate'] = $totals['employer_rate'];
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
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode(array_values($value)) ?: '[]';
        }

        if ((is_int($value) || is_float($value))
            || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/D', trim($value)) === 1)) {
            return $this->numbers->normalize($value);
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

    private function normalizeNullableDecimal(mixed $value, int $scale = 2): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->numbers->normalizeToScale($value, $scale);
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
        }, companyScoped: false)) {
            $conflictFields[] = 'doc_number';
        }

        if ($this->hasComparableValue($record->doc_num) && $this->activeRecordExists($definition, function (Builder $query) use ($record): void {
            $query->where('doc_num', $record->doc_num);
        }, companyScoped: false)) {
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

        if (in_array($definition->key, ['social-insurance-policies', 'employment-tax-policies'], true)
            && $record->status === 'active'
            && $this->activePolicyPeriodOverlaps($definition, $record)) {
            $conflictFields[] = 'effective_period';
        }

        return array_values(array_unique($conflictFields));
    }

    private function activePolicyPeriodOverlaps(HrFoundationDefinition $definition, HrFoundationModel $record): bool
    {
        $effectiveFrom = $record->getAttribute('effective_from');
        $effectiveTo = $record->getAttribute('effective_to');

        if (! $effectiveFrom instanceof DateTimeInterface) {
            return false;
        }

        $query = $definition->modelClass::query()
            ->where('company_id', $record->getAttribute('company_id'))
            ->where('status', 'active')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $effectiveFrom->format('Y-m-d')));

        if ($effectiveTo instanceof DateTimeInterface) {
            $query->whereDate('effective_from', '<=', $effectiveTo->format('Y-m-d'));
        }

        return $query->exists();
    }

    private function activeRecordExists(HrFoundationDefinition $definition, callable $constraint, bool $companyScoped = true): bool
    {
        $query = $definition->modelClass::query()->whereNull('deleted_at');

        if ($definition->companyScoped && $companyScoped) {
            $this->companies->applyCompanyScope($query, $definition->table);
        }

        $constraint($query);

        return $query->exists();
    }

    /**
     * @param  list<string>  $conflictFields
     */
    private function restoreConflictType(array $conflictFields): string
    {
        if (in_array('effective_period', $conflictFields, true)) {
            return 'effective_period_conflict';
        }

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

    private function isStatutoryPolicy(HrFoundationDefinition $definition): bool
    {
        return in_array($definition->key, ['social-insurance-policies', 'employment-tax-policies'], true);
    }

    private function lockPolicyCompany(int $companyId): void
    {
        Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail(['id']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensurePolicyPeriodAvailable(
        HrFoundationDefinition $definition,
        array $data,
        ?HrFoundationModel $record,
        int $companyId,
    ): void {
        $status = (string) ($data['status'] ?? $record?->getAttribute('status') ?? 'active');

        if ($status !== 'active') {
            return;
        }

        $effectiveFrom = (string) ($data['effective_from'] ?? $record?->getAttribute('effective_from')?->format('Y-m-d') ?? '');
        $effectiveTo = (string) ($data['effective_to'] ?? $record?->getAttribute('effective_to')?->format('Y-m-d') ?? '');
        $query = $definition->modelClass::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->when($record !== null, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $effectiveFrom));

        if ($effectiveTo !== '') {
            $query->whereDate('effective_from', '<=', $effectiveTo);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => __('hr.validation.policy_period_overlap'),
            ]);
        }
    }

    /**
     * @return array{old: array<string, array{name: string, employee_rate: string, employer_rate: string, calculation_basis: string, is_active: bool, notes: string|null, sort_order: int}>, new: array<string, array{name: string, employee_rate: string, employer_rate: string, calculation_basis: string, is_active: bool, notes: string|null, sort_order: int}>}|null
     */
    private function insuranceComponentChanges(HrSocialInsurancePolicy $policy, mixed $rows): ?array
    {
        $oldRows = $policy->components()
            ->get()
            ->map(fn ($component): array => [
                'name' => (string) $component->name,
                'employee_rate' => (string) $component->employee_rate,
                'employer_rate' => (string) $component->employer_rate,
                'calculation_basis' => (string) $component->calculation_basis,
                'is_active' => (bool) $component->is_active,
                'notes' => $component->notes,
                'sort_order' => (int) $component->sort_order,
            ])
            ->values()
            ->all();
        $newRows = $this->normalizedInsuranceComponentRows($rows);

        return $oldRows === $newRows ? null : [
            'old' => $this->insuranceComponentAuditRows($oldRows),
            'new' => $this->insuranceComponentAuditRows($newRows),
        ];
    }

    private function syncInsuranceComponents(HrSocialInsurancePolicy $policy, mixed $rows): void
    {
        $submittedRows = collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();
        $existing = $policy->components()->get()->keyBy('public_uuid');
        $kept = [];

        foreach ($submittedRows as $index => $row) {
            $publicUuid = trim((string) ($row['public_uuid'] ?? ''));
            $values = [
                'name' => $this->normalizeString((string) ($row['name'] ?? '')),
                'employee_rate' => $this->normalizeNullableDecimal($row['employee_rate'] ?? null, 4),
                'employer_rate' => $this->normalizeNullableDecimal($row['employer_rate'] ?? null, 4),
                'calculation_basis' => $this->normalizeString((string) ($row['calculation_basis'] ?? 'contribution_wage')),
                'is_active' => filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOL),
                'notes' => $this->normalizeNullableString($row['notes'] ?? null),
                'sort_order' => $index,
            ];
            $component = $publicUuid !== '' ? $existing->get($publicUuid) : null;

            if ($component) {
                $component->fill($values);

                if ($component->isDirty()) {
                    $component->save();
                }

                $kept[] = (string) $component->public_uuid;

                continue;
            }

            $component = $policy->components()->create([
                ...$values,
                'public_uuid' => (string) Str::uuid(),
            ]);
            $kept[] = (string) $component->public_uuid;
        }

        $policy->components()
            ->when($kept !== [], fn (Builder $query): Builder => $query->whereNotIn('public_uuid', $kept))
            ->delete();
    }

    /**
     * @return list<array{name: string, employee_rate: string, employer_rate: string, calculation_basis: string, is_active: bool, notes: string|null, sort_order: int}>
     */
    private function normalizedInsuranceComponentRows(mixed $rows): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->map(fn (array $row, int $index): array => [
                'name' => $this->normalizeString((string) ($row['name'] ?? '')),
                'employee_rate' => (string) $this->normalizeNullableDecimal($row['employee_rate'] ?? null, 4),
                'employer_rate' => (string) $this->normalizeNullableDecimal($row['employer_rate'] ?? null, 4),
                'calculation_basis' => $this->normalizeString((string) ($row['calculation_basis'] ?? 'contribution_wage')),
                'is_active' => filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOL),
                'notes' => $this->normalizeNullableString($row['notes'] ?? null),
                'sort_order' => $index,
            ])
            ->all();
    }

    /**
     * @param  list<array{name: string, employee_rate: string, employer_rate: string, calculation_basis: string, is_active: bool, notes: string|null, sort_order: int}>  $rows
     * @return array<string, array{name: string, employee_rate: string, employer_rate: string, calculation_basis: string, is_active: bool, notes: string|null, sort_order: int}>
     */
    private function insuranceComponentAuditRows(array $rows): array
    {
        return collect($rows)
            ->mapWithKeys(fn (array $row, int $index): array => ['component_'.($index + 1) => $row])
            ->all();
    }

    /**
     * @return array{employee_rate: string, employer_rate: string, combined_rate: string}
     */
    private function insuranceComponentTotals(mixed $rows): array
    {
        $employeeRate = '0.0000';
        $employerRate = '0.0000';

        foreach ($this->normalizedInsuranceComponentRows($rows) as $row) {
            if (! $row['is_active']) {
                continue;
            }

            $employeeRate = bcadd($employeeRate, $row['employee_rate'], 4);
            $employerRate = bcadd($employerRate, $row['employer_rate'], 4);
        }

        return [
            'employee_rate' => $employeeRate,
            'employer_rate' => $employerRate,
            'combined_rate' => bcadd($employeeRate, $employerRate, 4),
        ];
    }

    /**
     * @return array{old: array<string, array{from_amount: string, to_amount: string|null, rate: string, notes: string|null, sort_order: int}>, new: array<string, array{from_amount: string, to_amount: string|null, rate: string, notes: string|null, sort_order: int}>}|null
     */
    private function taxBracketChanges(HrEmploymentTaxPolicy $policy, mixed $rows): ?array
    {
        $oldRows = $policy->brackets()
            ->get()
            ->map(fn ($bracket): array => [
                'from_amount' => (string) $bracket->from_amount,
                'to_amount' => $bracket->to_amount === null ? null : (string) $bracket->to_amount,
                'rate' => (string) $bracket->rate,
                'notes' => $bracket->notes,
                'sort_order' => (int) $bracket->sort_order,
            ])
            ->values()
            ->all();
        $newRows = $this->normalizedTaxBracketRows($rows);

        return $oldRows === $newRows ? null : [
            'old' => $this->taxBracketAuditRows($oldRows),
            'new' => $this->taxBracketAuditRows($newRows),
        ];
    }

    private function syncTaxBrackets(HrEmploymentTaxPolicy $policy, mixed $rows): void
    {
        $submittedRows = collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();
        $existing = $policy->brackets()->get()->keyBy('public_uuid');
        $kept = [];

        foreach ($submittedRows as $index => $row) {
            $publicUuid = trim((string) ($row['public_uuid'] ?? ''));
            $values = [
                'from_amount' => $this->normalizeNullableDecimal($row['from_amount'] ?? null),
                'to_amount' => $this->normalizeNullableDecimal($row['to_amount'] ?? null),
                'rate' => $this->normalizeNullableDecimal($row['rate'] ?? null, 4),
                'notes' => $this->normalizeNullableString($row['notes'] ?? null),
                'sort_order' => $index,
            ];
            $bracket = $publicUuid !== '' ? $existing->get($publicUuid) : null;

            if ($bracket) {
                $bracket->fill($values);

                if ($bracket->isDirty()) {
                    $bracket->save();
                }

                $kept[] = (string) $bracket->public_uuid;

                continue;
            }

            $bracket = $policy->brackets()->create([
                ...$values,
                'public_uuid' => (string) Str::uuid(),
            ]);
            $kept[] = (string) $bracket->public_uuid;
        }

        $policy->brackets()
            ->when($kept !== [], fn (Builder $query): Builder => $query->whereNotIn('public_uuid', $kept))
            ->delete();
    }

    /**
     * @return list<array{from_amount: string, to_amount: string|null, rate: string, notes: string|null, sort_order: int}>
     */
    private function normalizedTaxBracketRows(mixed $rows): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->map(fn (array $row, int $index): array => [
                'from_amount' => (string) $this->normalizeNullableDecimal($row['from_amount'] ?? null),
                'to_amount' => $this->normalizeNullableDecimal($row['to_amount'] ?? null),
                'rate' => (string) $this->normalizeNullableDecimal($row['rate'] ?? null, 4),
                'notes' => $this->normalizeNullableString($row['notes'] ?? null),
                'sort_order' => $index,
            ])
            ->all();
    }

    /**
     * @param  list<array{from_amount: string, to_amount: string|null, rate: string, notes: string|null, sort_order: int}>  $rows
     * @return array<string, array{from_amount: string, to_amount: string|null, rate: string, notes: string|null, sort_order: int}>
     */
    private function taxBracketAuditRows(array $rows): array
    {
        return collect($rows)
            ->mapWithKeys(fn (array $row, int $index): array => ['bracket_'.($index + 1) => $row])
            ->all();
    }
}
