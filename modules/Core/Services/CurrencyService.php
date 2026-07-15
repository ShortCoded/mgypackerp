<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Currency;

class CurrencyService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $companyId = $this->companies->requireCompanyId();
            $document = $this->document($data, $companyId);
            $values = $this->values($data, $companyId);

            if ($values['status'] === 'active' && ! Currency::query()->forCompany($companyId)->active()->where('is_main', true)->exists()) {
                $values['is_main'] = true;
            }

            if (($values['is_main'] ?? false) && $values['status'] === 'active') {
                Currency::query()->forCompany($companyId)->update(['is_main' => false]);
            }

            $record = Currency::query()->create([...$values, ...$document, 'created_by' => auth()->id()]);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()];
        });
    }

    public function update(Currency $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = $this->values($data, (int) $record->company_id);
            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, (int) $record->company_id)];
            }

            if (($values['is_main'] ?? false) && $values['status'] === 'active') {
                Currency::query()->forCompany((int) $record->company_id)->whereKeyNot($record->getKey())->update(['is_main' => false]);
            }

            $changes = $this->changes($record, $values);
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
                'changed_fields' => array_keys($changes),
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(Currency $record): void
    {
        if ($record->is_main && $record->status === 'active') {
            throw new DomainException(__('currencies.messages.delete_blocked_main'));
        }

        DB::transaction(fn () => $this->audit->softDelete($record));
    }

    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $deleted = 0;
            Currency::query()
                ->forCompany($this->companies->requireCompanyId())
                ->whereIn('doc_num', $docNums)
                ->each(function (Currency $record) use (&$deleted): void {
                    $this->delete($record);
                    $deleted++;
                });

            return $deleted;
        });
    }

    public function restore(Currency $record): Currency
    {
        return DB::transaction(function () use ($record): Currency {
            if (Currency::query()->forCompany((int) $record->company_id)->where('code', $record->code)->whereKeyNot($record->getKey())->exists()) {
                throw new DomainException(__('currencies.messages.restore_conflict'));
            }

            if ($record->is_main && Currency::query()->forCompany((int) $record->company_id)->whereKeyNot($record->getKey())->active()->where('is_main', true)->exists()) {
                throw new DomainException(__('currencies.messages.restore_main_conflict'));
            }

            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    private function document(array $data, int $companyId): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documentNumbers->format('currencies', (int) $data['doc_number'])]
            : $this->documentNumbers->nextForCompany('currencies', Currency::class, $companyId);
    }

    private function values(array $data, int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'name' => trim((string) $data['name']),
            'code' => strtoupper(trim((string) $data['code'])),
            'minor_unit_name' => $this->nullableString($data['minor_unit_name'] ?? null),
            'minor_unit_factor' => (int) ($data['minor_unit_factor'] ?? 100),
            'is_main' => ($data['status'] ?? 'active') === 'active' && (bool) ($data['is_main'] ?? false),
            'status' => $data['status'] ?? 'active',
            'notes' => $this->nullableString($data['notes'] ?? null),
        ];
    }

    private function changes(Currency $record, array $values): array
    {
        $changes = [];
        foreach ($values as $field => $value) {
            if ((string) $record->{$field} !== (string) $value) {
                $changes[$field] = ['old' => $record->{$field}, 'new' => $value];
            }
        }

        return $changes;
    }

    private function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
