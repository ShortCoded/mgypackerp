<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\FundTransfer;

class FundTransferService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
        private readonly FinanceAmountService $amounts,
        private readonly FinancialPeriodService $financialPeriods,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $companyId = $this->companies->requireCompanyId();
            $record = FundTransfer::query()->create([
                ...$this->values($data, $companyId),
                ...$this->document($data, $companyId),
                'status' => FundTransfer::StatusDraft,
                'created_by' => auth()->id(),
            ]);

            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $this->load($record->refresh())];
        });
    }

    public function update(FundTransfer $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $companyId = $this->companies->requireCompanyId();
            $this->assertOwned($record, $companyId);
            $this->assertEditable($record);

            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = $this->values($data, $companyId);

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, $companyId, $record)];
            }

            $changes = $this->changes($record, $values);

            if ($changes === []) {
                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                ];
            }

            $this->audit->saveUpdate($record, $values);

            return [
                'record' => $this->load($record->refresh()),
                'changed' => true,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(FundTransfer $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertOwned($record, $this->companies->requireCompanyId());
            $this->assertDeletable($record);
            $this->audit->softDelete($record);
        });
    }

    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $companyId = $this->companies->requireCompanyId();
            $deleted = 0;

            foreach (FundTransfer::query()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $record) {
                try {
                    $this->delete($record);
                    $deleted++;
                } catch (DomainException) {
                    continue;
                }
            }

            return $deleted;
        });
    }

    public function restore(FundTransfer $record): FundTransfer
    {
        return DB::transaction(function () use ($record): FundTransfer {
            $companyId = $this->companies->requireCompanyId();
            $this->assertOwned($record, $companyId);

            if (! $record->isDeletable()) {
                throw new DomainException(__('fund_transfers.messages.restore_status_forbidden'));
            }

            if (FundTransfer::query()
                ->forCompany($companyId)
                ->whereKeyNot($record->getKey())
                ->where('doc_number', $record->doc_number)
                ->exists()) {
                throw new DomainException(__('fund_transfers.messages.restore_conflict'));
            }

            if (FundTransfer::query()
                ->forCompany($companyId)
                ->whereKeyNot($record->getKey())
                ->where('doc_num', $record->doc_num)
                ->exists()) {
                throw new DomainException(__('fund_transfers.messages.restore_conflict'));
            }

            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    public function approve(FundTransfer $record): FundTransfer
    {
        return DB::transaction(function () use ($record): FundTransfer {
            $companyId = $this->companies->requireCompanyId();

            /** @var FundTransfer $locked */
            $locked = FundTransfer::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertOwned($locked, $companyId);

            if ($locked->trashed()) {
                throw new DomainException(__('fund_transfers.messages.deleted_not_actionable'));
            }

            if (! $locked->isDraft()) {
                throw new DomainException(__('fund_transfers.messages.document_not_approvable'));
            }

            $this->financialPeriods->resolveOpenForPostingDate(
                $companyId,
                $locked->transfer_date,
                lockForUpdate: true,
            );

            $locked->forceFill([
                'status' => FundTransfer::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();

            return $this->load($locked->refresh());
        });
    }

    public function cancel(FundTransfer $record, string $reason): FundTransfer
    {
        return DB::transaction(function () use ($record, $reason): FundTransfer {
            $companyId = $this->companies->requireCompanyId();

            /** @var FundTransfer $locked */
            $locked = FundTransfer::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertOwned($locked, $companyId);

            if ($locked->trashed()) {
                throw new DomainException(__('fund_transfers.messages.deleted_not_actionable'));
            }

            if ($locked->isCancelled()) {
                throw new DomainException(__('fund_transfers.messages.document_already_cancelled'));
            }

            $locked->forceFill([
                'status' => FundTransfer::StatusCancelled,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'updated_by' => auth()->id(),
            ])->save();

            return $this->load($locked->refresh());
        });
    }

    private function values(array $data, int $companyId): array
    {
        $sourceCashbox = $this->cashbox($data, $companyId, 'source');
        $sourceBankAccount = $this->bankAccount($data, $companyId, 'source');
        $targetCashbox = $this->cashbox($data, $companyId, 'target');
        $targetBankAccount = $this->bankAccount($data, $companyId, 'target');
        $sourceCurrency = Currency::query()->forCompany($companyId)->where('doc_num', $data['source_currency_doc_num'])->first();
        $targetCurrency = Currency::query()->forCompany($companyId)->where('doc_num', $data['target_currency_doc_num'])->first();
        $sourceAmount = $this->amounts->normalize($data['source_amount'] ?? 0, 4);
        $exchangeRate = $this->amounts->normalize($data['exchange_rate'] ?? 1, 6);
        $targetAmount = $this->amounts->normalize($data['target_amount'] ?? 0, 4);

        return [
            'company_id' => $companyId,
            'transfer_date' => $data['transfer_date'],
            'source_type' => $data['source_type'],
            'source_cashbox_id' => $sourceCashbox?->getKey(),
            'source_bank_account_id' => $sourceBankAccount?->getKey(),
            'target_type' => $data['target_type'],
            'target_cashbox_id' => $targetCashbox?->getKey(),
            'target_bank_account_id' => $targetBankAccount?->getKey(),
            'source_currency_id' => $sourceCurrency?->getKey(),
            'target_currency_id' => $targetCurrency?->getKey(),
            'source_amount' => $sourceAmount,
            'exchange_rate' => $exchangeRate,
            'target_amount' => $targetAmount,
            'source_amount_base' => $this->baseAmount($sourceCurrency, $targetCurrency, $sourceAmount, $targetAmount),
            'target_amount_base' => $this->baseAmount($targetCurrency, $sourceCurrency, $targetAmount, $sourceAmount),
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
        ];
    }

    private function cashbox(array $data, int $companyId, string $side): ?Cashbox
    {
        if (($data["{$side}_type"] ?? null) !== FundTransfer::HolderCashbox) {
            return null;
        }

        return Cashbox::query()->forCompany($companyId)->where('doc_num', $data["{$side}_cashbox_doc_num"] ?? null)->first();
    }

    private function bankAccount(array $data, int $companyId, string $side): ?BankAccount
    {
        if (($data["{$side}_type"] ?? null) !== FundTransfer::HolderBankAccount) {
            return null;
        }

        return BankAccount::query()->forCompany($companyId)->where('doc_num', $data["{$side}_bank_account_doc_num"] ?? null)->first();
    }

    private function baseAmount(?Currency $currency, ?Currency $otherCurrency, string $amount, string $otherAmount): ?string
    {
        if ($currency?->is_main) {
            return $amount;
        }

        if ($otherCurrency?->is_main) {
            return $otherAmount;
        }

        return null;
    }

    private function document(array $data, int $companyId, ?FundTransfer $current = null): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('fund_transfers', (int) $data['doc_number'])]
            : ($current instanceof FundTransfer ? [] : $this->nextScopedDocument($companyId));
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function nextScopedDocument(int $companyId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('fund_transfers')));
        }

        $nextNumber = ((int) FundTransfer::query()
            ->where('company_id', $companyId)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->documents->format('fund_transfers', $nextNumber),
        ];
    }

    private function assertEditable(FundTransfer $record): void
    {
        if ($record->isLockedForEditing()) {
            throw new DomainException(__('fund_transfers.messages.document_locked'));
        }
    }

    private function assertDeletable(FundTransfer $record): void
    {
        if (! $record->isDeletable()) {
            throw new DomainException(__('fund_transfers.messages.document_delete_blocked'));
        }
    }

    private function assertOwned(FundTransfer $record, int $companyId): void
    {
        if ((int) $record->company_id !== $companyId) {
            throw new DomainException(__('fund_transfers.messages.not_found_in_context'));
        }
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(FundTransfer $record, array $values): array
    {
        $changes = [];

        foreach ($values as $field => $value) {
            if ((string) $record->{$field} !== (string) $value) {
                $changes[$field] = ['old' => $record->{$field}, 'new' => $value];
            }
        }

        return $changes;
    }

    private function load(FundTransfer $record): FundTransfer
    {
        return $record->load([
            'sourceCashbox',
            'sourceBankAccount.currency',
            'targetCashbox',
            'targetBankAccount.currency',
            'sourceCurrency',
            'targetCurrency',
        ]);
    }
}
