<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;

class BankAccountService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly BankAccountChartAccountService $chartAccounts,
        private readonly BankAccountAccountingSyncService $accountingSync,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $companyId = $this->companies->requireCompanyId();
            $bankGroup = $this->bankGroup($data);
            $currency = $this->currency($data);
            $linkedAccount = $this->chartAccounts->createOrUpdateLinkedAccount(null, $bankGroup, $data, $currency)['account'];

            $record = BankAccount::query()->create([...$this->values($data, $companyId, $bankGroup, $linkedAccount, $currency), ...$this->document($data, $companyId), 'created_by' => auth()->id()]);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()];
        });
    }

    public function update(BankAccount $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $bankGroup = $this->bankGroup($data);
            $currency = $this->currency($data);
            $chartResult = $this->syncsLinkedAccount($record, $data, $bankGroup, $currency)
                ? $this->chartAccounts->createOrUpdateLinkedAccount($record->account, $bankGroup, $data, $currency)
                : ['account' => $record->account, 'changed' => false];
            $values = $this->values($data, (int) $record->company_id, $bankGroup, $chartResult['account'], $currency);
            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, (int) $record->company_id)];
            }
            $changes = $this->changes($record, $values);
            if ($changes === [] && ! $chartResult['changed']) {
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

            return [
                'record' => $record->refresh(),
                'changed' => true,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(BankAccount $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->accountingSync->softDeleteLinkedAccountForBankAccount($record);
            $this->audit->softDelete($record);
        });
    }

    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $deleted = 0;
            foreach (BankAccount::query()->forCompany($this->companies->requireCompanyId())->whereIn('doc_num', $docNums)->get() as $record) {
                $this->delete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(BankAccount $record): BankAccount
    {
        return DB::transaction(function () use ($record): BankAccount {
            if (BankAccount::query()->forCompany((int) $record->company_id)->where('account_id', $record->account_id)->where('status', 'active')->whereKeyNot($record->getKey())->exists()) {
                throw new DomainException(__('bank_accounts.messages.restore_conflict'));
            }
            $this->accountingSync->restoreLinkedAccountForBankAccount($record);
            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    private function document(array $data, int $companyId): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('bank_accounts', (int) $data['doc_number'])]
            : $this->documents->nextForCompany('bank_accounts', BankAccount::class, $companyId);
    }

    private function values(array $data, int $companyId, Account $bankGroup, Account $linkedAccount, Currency $currency): array
    {
        return [
            'company_id' => $companyId,
            'bank_id' => $bankGroup->getKey(),
            'account_id' => $linkedAccount->getKey(),
            'currency_id' => $currency->getKey(),
            'account_name' => $data['account_name'],
            'account_number' => ($data['account_number'] ?? null) ?: null,
            'iban' => ($data['iban'] ?? null) ?: null,
            'swift_code' => ($data['swift_code'] ?? null) ?: null,
            'owner_name' => ($data['owner_name'] ?? null) ?: null,
            'bank_branch_name' => ($data['bank_branch_name'] ?? null) ?: null,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function bankGroup(array $data): Account
    {
        return Account::query()
            ->with('classification')
            ->forCompany($this->companies->requireCompanyId())
            ->where('doc_num', $data['bank_doc_num'] ?? $data['account_doc_num'] ?? null)
            ->firstOrFail();
    }

    private function currency(array $data): Currency
    {
        return Currency::query()
            ->forCompany($this->companies->requireCompanyId())
            ->where('doc_num', $data['currency_doc_num'])
            ->firstOrFail();
    }

    private function syncsLinkedAccount(BankAccount $record, array $data, Account $bankGroup, Currency $currency): bool
    {
        if (! $record->account instanceof Account || ! $this->chartAccounts->isManagedLinkedAccount($record->account)) {
            return true;
        }

        return (int) $record->bank_id !== (int) $bankGroup->getKey()
            || (int) $record->currency_id !== (int) $currency->getKey()
            || trim((string) $record->account_name) !== trim((string) $data['account_name']);
    }

    private function changes(BankAccount $record, array $values): array
    {
        $changes = [];
        foreach ($values as $field => $value) {
            if ((string) $record->{$field} !== (string) $value) {
                $changes[$field] = ['old' => $record->{$field}, 'new' => $value];
            }
        }

        return $changes;
    }
}
