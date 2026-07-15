<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;

class CashboxService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly CashboxChartAccountService $chartAccounts,
        private readonly CashboxAccountingSyncService $accountingSync,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $companyId = $this->companies->requireCompanyId();
            $parentAccount = $this->parentAccount($data);
            $linkedAccount = $this->chartAccounts->createOrUpdateLinkedAccount(null, $parentAccount, $data)['account'];
            $record = Cashbox::query()->create([...$this->values($data, $companyId, $linkedAccount), ...$this->document($data, $companyId), 'created_by' => auth()->id()]);
            $this->syncCurrencies($record, $data);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()];
        });
    }

    public function update(Cashbox $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $parentAccount = $this->parentAccount($data);
            $this->assertLinkedAccountCanMove($record->account, $parentAccount);
            $chartResult = $this->syncsLinkedAccount($record, $data, $parentAccount)
                ? $this->chartAccounts->createOrUpdateLinkedAccount($record->account, $parentAccount, $data)
                : ['account' => $record->account, 'changed' => false];
            $values = $this->values($data, (int) $record->company_id, $chartResult['account']);
            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, (int) $record->company_id)];
            }
            $changes = $this->changes($record, $values);
            $currencyChanged = $this->currencySnapshot($record) !== $this->requestedCurrencySnapshot($data);
            if ($changes === [] && ! $currencyChanged && ! $chartResult['changed']) {
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
            $this->syncCurrencies($record, $data);

            return [
                'record' => $record->refresh(),
                'changed' => true,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(Cashbox $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->accountingSync->softDeleteLinkedAccountForCashbox($record);
            $record->currencies()->delete();
            $this->audit->softDelete($record);
        });
    }

    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $deleted = 0;
            foreach (Cashbox::query()->forCompany($this->companies->requireCompanyId())->whereIn('doc_num', $docNums)->get() as $record) {
                $this->delete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(Cashbox $record): Cashbox
    {
        return DB::transaction(function () use ($record): Cashbox {
            if (Cashbox::query()->forCompany((int) $record->company_id)->where('account_id', $record->account_id)->whereKeyNot($record->getKey())->exists()) {
                throw new DomainException(__('cashboxes.messages.restore_conflict'));
            }
            $this->accountingSync->restoreLinkedAccountForCashbox($record);
            $record->currencies()->withTrashed()->restore();
            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    private function document(array $data, int $companyId): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('cashboxes', (int) $data['doc_number'])]
            : $this->documents->nextForCompany('cashboxes', Cashbox::class, $companyId);
    }

    private function values(array $data, int $companyId, Account $linkedAccount): array
    {
        return [
            'company_id' => $companyId,
            'name' => $data['name'],
            'account_id' => $linkedAccount->getKey(),
            'branch_id' => ! empty($data['branch_doc_num']) ? Branch::query()->where('company_id', $companyId)->where('doc_num', $data['branch_doc_num'])->value('id') : null,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function parentAccount(array $data): Account
    {
        if (empty($data['parent_account_doc_num'])) {
            return $this->chartAccounts->mainCashboxesAccount();
        }

        return Account::query()
            ->with('classification')
            ->forCompany($this->companies->requireCompanyId())
            ->where('doc_num', $data['parent_account_doc_num'])
            ->firstOrFail();
    }

    private function assertLinkedAccountCanMove(?Account $linkedAccount, Account $parentAccount): void
    {
        if (! $linkedAccount instanceof Account || (int) $linkedAccount->parent_id === (int) $parentAccount->getKey()) {
            return;
        }

        if ($linkedAccount->children()->exists()) {
            throw new DomainException(__('cashboxes.messages.account_move_blocked_children'));
        }
    }

    private function syncCurrencies(Cashbox $record, array $data): void
    {
        $currencyIds = Currency::query()
            ->forCompany((int) $record->company_id)
            ->whereIn('doc_num', (array) ($data['currency_doc_nums'] ?? []))
            ->pluck('id', 'doc_num');
        $record->currencies()->withTrashed()->forceDelete();
        foreach ($currencyIds as $id) {
            CashboxCurrency::query()->create([
                'cashbox_id' => $record->getKey(),
                'currency_id' => $id,
                'is_default' => false,
                'status' => 'active',
            ]);
        }
    }

    private function currencySnapshot(Cashbox $record): string
    {
        return $record->currencies()->with('currency')->get()->map(fn (CashboxCurrency $row): string => (string) $row->currency?->doc_num)->sort()->implode('|');
    }

    private function requestedCurrencySnapshot(array $data): string
    {
        return collect((array) ($data['currency_doc_nums'] ?? []))->map(fn (string $docNum): string => $docNum)->sort()->implode('|');
    }

    private function syncsLinkedAccount(Cashbox $record, array $data, Account $parentAccount): bool
    {
        if (! $record->account instanceof Account || ! $this->chartAccounts->isManagedLinkedAccount($record->account)) {
            return true;
        }

        return (int) $record->account->parent_id !== (int) $parentAccount->getKey()
            || trim((string) $record->account->name) !== trim((string) $data['name']);
    }

    private function changes(Cashbox $record, array $values): array
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
