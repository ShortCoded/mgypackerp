<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;
use Modules\HR\Models\HrLookupModel;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCreditLimit;

class CustomerService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly BusinessPartnerAccountService $accounts,
        private readonly CustomerAccountingSyncService $accountingSync,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $companyId = $this->companies->requireCompanyId();
            $parentAccount = $this->parentAccount($data);
            $linkedAccount = $this->accounts->createOrUpdateLinkedAccount(BusinessPartnerAccountService::Customer, null, $parentAccount, $data)['account'];
            $record = Customer::query()->create([
                ...$this->values($data, $companyId, $linkedAccount, $parentAccount),
                ...$this->document($data, $companyId),
                'created_by' => auth()->id(),
            ]);
            $this->syncCreditLimits($record, $data['credit_limits'] ?? [], $companyId);

            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()];
        });
    }

    public function update(Customer $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $parentAccount = $this->parentAccount($data);
            $this->assertLinkedAccountCanMove($record->account, $parentAccount);
            $chartResult = $this->syncsLinkedAccount($record, $data, $parentAccount)
                ? $this->accounts->createOrUpdateLinkedAccount(BusinessPartnerAccountService::Customer, $record->account, $parentAccount, $data)
                : ['account' => $record->account, 'changed' => false];
            $values = $this->values($data, (int) $record->company_id, $chartResult['account'], $parentAccount);
            $creditLimitsChanged = $this->creditLimitsChanged($record, $data['credit_limits'] ?? []);

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, (int) $record->company_id)];
            }

            $changes = $this->changes($record, $values);

            if ($changes === [] && ! $chartResult['changed'] && ! $creditLimitsChanged) {
                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                ];
            }

            if ($changes !== []) {
                $this->audit->saveUpdate($record, $values);
            }

            if ($creditLimitsChanged) {
                $this->syncCreditLimits($record->refresh(), $data['credit_limits'] ?? [], (int) $record->company_id);
            }

            return [
                'record' => $record->refresh(),
                'changed' => true,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(Customer $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->accountingSync->softDeleteLinkedAccountForCustomer($record);
            $this->audit->softDelete($record);
        });
    }

    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $deleted = 0;

            foreach (Customer::query()->forCompany($this->companies->requireCompanyId())->whereIn('doc_num', $docNums)->get() as $record) {
                $this->delete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(Customer $record): Customer
    {
        return DB::transaction(function () use ($record): Customer {
            if (Customer::query()->forCompany((int) $record->company_id)->where('account_id', $record->account_id)->whereKeyNot($record->getKey())->exists()) {
                throw new DomainException(__('customers.messages.restore_conflict'));
            }

            $this->accountingSync->restoreLinkedAccountForCustomer($record);
            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    private function document(array $data, int $companyId): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('customers', (int) $data['doc_number'])]
            : $this->documents->nextForCompany('customers', Customer::class, $companyId);
    }

    private function values(array $data, int $companyId, Account $linkedAccount, Account $parentAccount): array
    {
        $root = $this->accounts->rootAccount(BusinessPartnerAccountService::Customer);

        return [
            'company_id' => $companyId,
            'account_id' => $linkedAccount->getKey(),
            'account_group_id' => (int) $parentAccount->getKey() === (int) $root->getKey() ? null : $parentAccount->getKey(),
            'name' => $data['name'],
            'status' => $data['status'] ?? 'active',
            'phone' => $data['phone'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'commercial_register' => $data['commercial_register'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'address' => $data['address'] ?? null,
            'country_id' => $this->locationId(HrCountry::class, $data['country_doc_num'] ?? null),
            'governorate_id' => $this->locationId(HrGovernorate::class, $data['governorate_doc_num'] ?? null),
            'city_id' => $this->locationId(HrCity::class, $data['city_doc_num'] ?? null),
            'area_id' => $this->locationId(HrArea::class, $data['area_doc_num'] ?? null),
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function parentAccount(array $data): Account
    {
        return $this->accounts->parentAccount(BusinessPartnerAccountService::Customer, $data['account_group_doc_num'] ?? null);
    }

    private function assertLinkedAccountCanMove(?Account $linkedAccount, Account $parentAccount): void
    {
        if (! $linkedAccount instanceof Account || (int) $linkedAccount->parent_id === (int) $parentAccount->getKey()) {
            return;
        }

        if ($linkedAccount->children()->exists()) {
            throw new DomainException(__('customers.messages.account_move_blocked_children'));
        }
    }

    private function syncsLinkedAccount(Customer $record, array $data, Account $parentAccount): bool
    {
        if (! $record->account instanceof Account || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Customer, $record->account)) {
            return true;
        }

        return (int) $record->account->parent_id !== (int) $parentAccount->getKey()
            || trim((string) $record->account->name) !== trim((string) $data['name'])
            || trim((string) $record->account->status) !== trim((string) ($data['status'] ?? 'active'));
    }

    private function changes(object $record, array $values): array
    {
        $changes = [];

        foreach ($values as $field => $value) {
            if ((string) $record->{$field} !== (string) $value) {
                $changes[$field] = ['old' => $record->{$field}, 'new' => $value];
            }
        }

        return $changes;
    }

    /**
     * @param  class-string<HrLookupModel>  $model
     */
    private function locationId(string $model, ?string $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        $id = $model::query()
            ->where('doc_num', $docNum)
            ->whereNull('deleted_at')
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncCreditLimits(Customer $record, array $rows, int $companyId): void
    {
        $incoming = $this->normalizedCreditLimitRows($rows, $companyId);
        $existing = $record->creditLimits()->withTrashed()->get()->keyBy('currency_id');
        $keptCurrencyIds = [];

        foreach ($incoming as $row) {
            $keptCurrencyIds[] = $row['currency_id'];
            /** @var CustomerCreditLimit|null $limit */
            $limit = $existing->get($row['currency_id']);

            if ($limit instanceof CustomerCreditLimit) {
                if ($limit->trashed()) {
                    $limit->restore();
                }

                $values = [
                    'company_id' => $companyId,
                    'credit_limit' => $row['credit_limit'],
                    'notes' => $row['notes'],
                    'updated_by' => auth()->id(),
                ];

                if ($this->changes($limit, $values) !== []) {
                    $limit->forceFill($values)->save();
                }

                continue;
            }

            $record->creditLimits()->create([
                'company_id' => $companyId,
                'currency_id' => $row['currency_id'],
                'credit_limit' => $row['credit_limit'],
                'notes' => $row['notes'],
                'created_by' => auth()->id(),
            ]);
        }

        $record->creditLimits()
            ->whereNotIn('currency_id', $keptCurrencyIds === [] ? [0] : $keptCurrencyIds)
            ->get()
            ->each(function (CustomerCreditLimit $limit): void {
                $limit->forceFill(['deleted_by' => auth()->id(), 'updated_by' => auth()->id()])->save();
                $limit->delete();
            });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{currency_id: int, currency_doc_num: string, credit_limit: string, notes: string|null}>
     */
    private function normalizedCreditLimitRows(array $rows, int $companyId): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ($row['_delete'] ?? false)) {
                continue;
            }

            $currencyDocNum = trim((string) ($row['currency_doc_num'] ?? ''));
            $amount = trim((string) ($row['credit_limit'] ?? ''));

            if ($currencyDocNum === '' && $amount === '') {
                continue;
            }

            $currencyId = Currency::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $currencyDocNum)
                ->whereNull('deleted_at')
                ->value('id');

            if (! $currencyId) {
                continue;
            }

            $result[] = [
                'currency_id' => (int) $currencyId,
                'currency_doc_num' => $currencyDocNum,
                'credit_limit' => number_format((float) $amount, 4, '.', ''),
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function creditLimitsChanged(Customer $record, array $rows): bool
    {
        $incoming = collect($this->normalizedCreditLimitRows($rows, (int) $record->company_id))
            ->map(fn (array $row): array => [
                'currency_id' => $row['currency_id'],
                'credit_limit' => $row['credit_limit'],
                'notes' => $row['notes'],
            ])
            ->sortBy('currency_id')
            ->values()
            ->all();
        $existing = $record->creditLimits()
            ->get(['currency_id', 'credit_limit', 'notes'])
            ->map(fn (CustomerCreditLimit $limit): array => [
                'currency_id' => (int) $limit->currency_id,
                'credit_limit' => number_format((float) $limit->credit_limit, 4, '.', ''),
                'notes' => $limit->notes,
            ])
            ->sortBy('currency_id')
            ->values()
            ->all();

        return json_encode($incoming) !== json_encode($existing);
    }
}
