<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\FinancialPeriod;

class FinancialPeriodService
{
    /**
     * @var list<string>
     */
    private array $fillableFields = [
        'name',
        'from_date',
        'to_date',
        'is_closed',
        'notes',
    ];

    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    public function resolveOpenForPostingDate(
        int $companyId,
        Carbon|string $postingDate,
        ?int $expectedPeriodId = null,
        ?string $expectedPeriodDocNum = null,
        bool $lockForUpdate = false,
    ): FinancialPeriod {
        $date = Carbon::parse($postingDate)->startOfDay();
        $query = FinancialPeriod::query()
            ->forCompany($companyId)
            ->whereDate('from_date', '<=', $date->toDateString())
            ->whereDate('to_date', '>=', $date->toDateString())
            ->whereNull('deleted_at');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $period = $query->first();

        if (! $period instanceof FinancialPeriod
            || ($expectedPeriodId !== null && (int) $period->getKey() !== $expectedPeriodId)
            || ($expectedPeriodDocNum !== null && $period->doc_num !== $expectedPeriodDocNum)
        ) {
            throw new DomainException(__('journal_entries.messages.date_outside_period'));
        }

        if ($period->is_closed) {
            throw new DomainException(__('journal_entries.messages.period_closed'));
        }

        return $period;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: FinancialPeriod}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $companyId = $this->companyContext->requireCompanyId();
            $documentNumber = array_key_exists('doc_number', $data)
                ? $this->manualDocumentNumber((int) $data['doc_number'])
                : $this->documentNumberService->nextForCompany('financial_periods', FinancialPeriod::class, $companyId);
            $values = $this->normalizedValues($data);

            if ($values['is_closed'] ?? false) {
                throw new DomainException(__('financial_periods.messages.status_requires_workflow'));
            }

            $record = FinancialPeriod::query()->create([
                'company_id' => $companyId,
                ...$values,
                'doc_number' => $documentNumber['doc_number'],
                'doc_num' => $documentNumber['doc_num'],
                'created_by' => auth()->id(),
            ]);

            $this->crudAudit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: FinancialPeriod, changed: bool, changed_fields: list<string>, changes: array<string, array{old: mixed, new: mixed}>, old_doc_number: int|null, old_doc_num: string|null}
     */
    public function update(FinancialPeriod $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $this->assertRecordBelongsToCurrentCompany($record);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $canChangeDocumentNumber = array_key_exists('doc_number', $data);
            $newValues = $this->normalizedValues($data, $record);

            if (array_key_exists('is_closed', $newValues)
                && (bool) $newValues['is_closed'] !== (bool) $record->is_closed) {
                throw new DomainException(__('financial_periods.messages.status_requires_workflow'));
            }

            if ($canChangeDocumentNumber) {
                $newValues['doc_number'] = (int) $data['doc_number'];
                $newValues['doc_num'] = $this->documentNumberService->format('financial_periods', (int) $data['doc_number']);
            }

            $changes = $this->changedValues($record, $newValues);
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

    public function delete(FinancialPeriod $record): void
    {
        $this->assertRecordBelongsToCurrentCompany($record);
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
            $records = FinancialPeriod::query()
                ->forCompany($this->companyContext->requireCompanyId())
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

    public function restore(FinancialPeriod $record): FinancialPeriod
    {
        return DB::transaction(function () use ($record): FinancialPeriod {
            $record = FinancialPeriod::withTrashed()
                ->lockForUpdate()
                ->whereKey($record->getKey())
                ->firstOrFail();
            $this->assertRecordBelongsToCurrentCompany($record);

            $this->ensureCanRestore($record);
            $this->crudAudit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    protected function ensureCanDelete(FinancialPeriod $record): void
    {
        // TODO: Add resource-specific dependency checks before enabling deletes for referenced business records.
        // This default is only safe for independent resources. Throw DomainException with a translated message
        // when this record is protected, referenced by another table, or otherwise blocked by business rules.
    }

    protected function ensureCanRestore(FinancialPeriod $record): void
    {
        if (! $record->trashed()) {
            throw new DomainException(__('financial_periods.messages.restore_not_allowed'));
        }

        if (FinancialPeriod::query()
            ->forCompany((int) $record->company_id)
            ->where(function ($query) use ($record): void {
                $query->where('doc_num', $record->doc_num)
                    ->orWhere('doc_number', $record->doc_number);
            })
            ->whereKeyNot($record->getKey())
            ->exists()) {
            throw new DomainException(__('financial_periods.messages.restore_conflict'));
        }

        if (FinancialPeriod::query()
            ->forCompany((int) $record->company_id)
            ->where('name', $record->name)
            ->whereKeyNot($record->getKey())
            ->exists()) {
            throw new DomainException(__('financial_periods.messages.restore_conflict'));
        }

        if (FinancialPeriod::query()
            ->forCompany((int) $record->company_id)
            ->whereKeyNot($record->getKey())
            ->whereDate('from_date', '<=', $record->to_date?->toDateString())
            ->whereDate('to_date', '>=', $record->from_date?->toDateString())
            ->exists()) {
            throw new DomainException(__('financial_periods.validation.date_range_overlap'));
        }
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function manualDocumentNumber(int $docNumber): array
    {
        return [
            'doc_number' => $docNumber,
            'doc_num' => $this->documentNumberService->format('financial_periods', $docNumber),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedValues(array $data, ?FinancialPeriod $existing = null): array
    {
        $values = [];

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
            'from_date' => $this->normalizeNullableString($value),
            'to_date' => $this->normalizeNullableString($value),
            'is_closed' => (bool) $value,
            'notes' => $this->normalizeNullableString($value),
            default => $this->normalizeNullableString($value),
        };
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(FinancialPeriod $record, array $newValues): array
    {
        $changes = [];

        foreach ($newValues as $field => $value) {
            $current = $record->{$field};

            if ($current instanceof \DateTimeInterface) {
                $current = in_array($field, ['from_date', 'to_date'], true)
                    ? $current->format('Y-m-d')
                    : $current->format('Y-m-d H:i:s');
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

    private function assertRecordBelongsToCurrentCompany(FinancialPeriod $record): void
    {
        abort_unless((int) $record->company_id === $this->companyContext->requireCompanyId(), 404);
    }
}
