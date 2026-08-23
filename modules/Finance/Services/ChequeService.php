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
use Modules\Finance\Models\Cheque;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\SupplierPaymentPostingService;

class ChequeService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
        private readonly FinanceAmountService $amounts,
        private readonly SupplierPaymentPostingService $supplierPaymentPostings,
    ) {}

    public function create(array $data, ?int $companyId = null): array
    {
        return DB::transaction(function () use ($data, $companyId): array {
            $companyId ??= $this->companies->requireCompanyId();
            $chequeType = (string) $data['cheque_type'];
            $record = Cheque::query()->create([
                ...$this->values($data, $companyId),
                ...$this->document($chequeType, $data, $companyId),
                'status' => $this->initialStatus($chequeType),
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $data['lines'] ?? []);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load(['bankAccount.currency', 'currency', 'lines.account'])];
        });
    }

    public function update(Cheque $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $companyId = $this->companies->requireCompanyId();
            $this->assertOwned($record, $companyId);
            $this->assertEditable($record);

            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = $this->values($data, $companyId);

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($record->cheque_type, $data, $companyId, $record)];
            }

            $changes = $this->changes($record, $values);
            $linesChanged = $this->linesChanged($record, $data['lines'] ?? []);

            if ($changes === [] && ! $linesChanged) {
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
            } else {
                $this->audit->touchUpdateAudit($record);
            }

            $this->syncLines($record->refresh(), $data['lines'] ?? []);

            return [
                'record' => $record->refresh()->load(['bankAccount.currency', 'currency', 'lines.account']),
                'changed' => true,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(Cheque $record): void
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

            foreach (Cheque::query()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $record) {
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

    public function restore(Cheque $record): Cheque
    {
        return DB::transaction(function () use ($record): Cheque {
            $companyId = $this->companies->requireCompanyId();
            $this->assertOwned($record, $companyId);

            if (! $record->isDeletable()) {
                throw new DomainException(__('cheques.messages.restore_status_forbidden'));
            }

            if (Cheque::query()
                ->forCompany($companyId)
                ->ofType($record->cheque_type)
                ->whereKeyNot($record->getKey())
                ->where('doc_number', $record->doc_number)
                ->exists()) {
                throw new DomainException(__('cheques.messages.restore_conflict'));
            }

            if (Cheque::query()
                ->forCompany($companyId)
                ->whereKeyNot($record->getKey())
                ->where('doc_num', $record->doc_num)
                ->exists()) {
                throw new DomainException(__('cheques.messages.restore_conflict'));
            }

            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    public function markDeposited(Cheque $record): Cheque
    {
        return $this->transition($record, Cheque::StatusDeposited, ['received'], ['deposited_at' => now()]);
    }

    public function markCollected(Cheque $record): Cheque
    {
        return $this->transition($record, Cheque::StatusCollected, ['deposited'], ['collected_at' => now()], requireFullDistribution: true);
    }

    public function markReturned(Cheque $record): Cheque
    {
        $allowed = $record->isReceived()
            ? [Cheque::StatusReceived, Cheque::StatusDeposited]
            : [Cheque::StatusIssued, Cheque::StatusDelivered];

        return $this->transition($record, Cheque::StatusReturned, $allowed, ['returned_at' => now()]);
    }

    public function markIssued(Cheque $record): Cheque
    {
        return $this->transition($record, Cheque::StatusIssued, [Cheque::StatusDraft], ['issued_at' => now()]);
    }

    public function markDelivered(Cheque $record): Cheque
    {
        return $this->transition($record, Cheque::StatusDelivered, [Cheque::StatusIssued], ['delivered_at' => now()]);
    }

    public function markCleared(Cheque $record): Cheque
    {
        return $this->transition($record, Cheque::StatusCleared, [Cheque::StatusIssued, Cheque::StatusDelivered], ['cleared_at' => now()], requireFullDistribution: true);
    }

    public function cancel(Cheque $record, string $reason): Cheque
    {
        $allowed = $record->isReceived()
            ? [Cheque::StatusReceived, Cheque::StatusDeposited]
            : [Cheque::StatusDraft, Cheque::StatusIssued, Cheque::StatusDelivered];

        return $this->transition($record, Cheque::StatusCancelled, $allowed, [
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
            'cancel_reason' => $reason,
        ]);
    }

    /**
     * @return array{amount: string, distributed_amount: string, remaining_amount: string, amount_units: int, distributed_units: int, remaining_units: int}
     */
    public function totals(Cheque $record): array
    {
        $record->loadMissing('lines');
        $amountUnits = $this->amounts->toUnits($record->amount);
        $distributedUnits = $record->lines->sum(fn ($line): int => $this->amounts->toUnits($line->amount));
        $remainingUnits = $amountUnits - $distributedUnits;

        return [
            'amount' => $this->amounts->fromUnits($amountUnits),
            'distributed_amount' => $this->amounts->fromUnits($distributedUnits),
            'remaining_amount' => $this->amounts->fromUnits($remainingUnits),
            'amount_units' => $amountUnits,
            'distributed_units' => $distributedUnits,
            'remaining_units' => $remainingUnits,
        ];
    }

    private function transition(Cheque $record, string $status, array $allowedStatuses, array $extra, bool $requireFullDistribution = false): Cheque
    {
        return DB::transaction(function () use ($record, $status, $allowedStatuses, $extra, $requireFullDistribution): Cheque {
            $companyId = $this->companies->requireCompanyId();

            /** @var Cheque $locked */
            $locked = Cheque::query()
                ->with(['lines.account', 'bankAccount.currency', 'currency'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            $this->assertOwned($locked, $companyId);

            if ($locked->trashed()) {
                throw new DomainException(__('cheques.messages.deleted_not_actionable'));
            }

            if (! in_array($locked->status, $allowedStatuses, true)) {
                throw new DomainException(__('cheques.messages.status_transition_forbidden'));
            }

            if (($status === Cheque::StatusDeposited || $status === Cheque::StatusCollected) && ! $locked->isReceived()) {
                throw new DomainException(__('cheques.messages.received_action_required'));
            }

            if (($status === Cheque::StatusIssued || $status === Cheque::StatusDelivered || $status === Cheque::StatusCleared) && ! $locked->isIssued()) {
                throw new DomainException(__('cheques.messages.issued_action_required'));
            }

            if ($requireFullDistribution && $this->totals($locked)['remaining_units'] !== 0) {
                throw new DomainException(__('cheques.messages.final_status_requires_full_distribution'));
            }

            $locked->forceFill([
                ...$extra,
                'status' => $status,
                'updated_by' => auth()->id(),
            ])->save();
            $this->synchronizeSupplierPayment($locked, $status);

            return $locked->refresh()->load(['bankAccount.currency', 'currency', 'lines.account']);
        });
    }

    private function synchronizeSupplierPayment(Cheque $cheque, string $status): void
    {
        $payment = SupplierPaymentContext::query()
            ->with('journalEntry')
            ->where('cheque_id', $cheque->getKey())
            ->lockForUpdate()
            ->first();

        if (! $payment instanceof SupplierPaymentContext) {
            return;
        }

        if ($status === Cheque::StatusIssued) {
            $cheque->loadMissing('bankAccount.account');
            $bankAccount = $cheque->bankAccount;
            $account = $bankAccount?->account;
            if (! $account instanceof Account || ! $bankAccount instanceof BankAccount) {
                throw new DomainException(__('The issuing Bank Account requires a postable GL account.'));
            }

            $this->supplierPaymentPostings->post($payment, $account, (int) $bankAccount->getKey());

            return;
        }

        if (in_array($status, [Cheque::StatusReturned, Cheque::StatusCancelled], true)) {
            $reason = $status === Cheque::StatusReturned
                ? __('Supplier payment cheque returned')
                : (string) $cheque->cancel_reason;
            $this->supplierPaymentPostings->reverse($payment, $reason);
        }
    }

    private function document(string $chequeType, array $data, int $companyId, ?Cheque $current = null): array
    {
        $key = Cheque::documentNumberKeyForType($chequeType);

        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format($key, (int) $data['doc_number'])]
            : ($current instanceof Cheque ? [] : $this->nextScopedDocument($chequeType, $key, $companyId));
    }

    private function values(array $data, int $companyId): array
    {
        $bankAccount = empty($data['bank_account_doc_num'])
            ? null
            : BankAccount::query()->forCompany($companyId)->where('doc_num', $data['bank_account_doc_num'])->first();
        $currency = Currency::query()->forCompany($companyId)->where('doc_num', $data['currency_doc_num'])->first();
        $exchangeRate = $currency?->is_main ? '1.000000' : $this->amounts->normalize($data['exchange_rate'] ?? 1, 6);
        $amount = $this->amounts->normalize($data['amount'] ?? 0, 4);

        return [
            'company_id' => $companyId,
            'cheque_type' => $data['cheque_type'],
            'cheque_number' => $data['cheque_number'],
            'cheque_date' => $data['cheque_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'bank_account_id' => $bankAccount?->getKey(),
            'external_bank_name' => $data['external_bank_name'] ?? null,
            'external_bank_branch' => $data['external_bank_branch'] ?? null,
            'party_type' => $data['party_type'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'party_name' => $data['party_name'] ?? null,
            'currency_id' => $currency?->getKey(),
            'exchange_rate' => $exchangeRate,
            'amount' => $amount,
            'amount_base' => $this->amounts->multiply($amount, $exchangeRate, 4),
            'reason' => $data['reason'],
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(Cheque $record, array $lines): void
    {
        $record->lines()->delete();
        $exchangeRate = (string) $record->exchange_rate;

        foreach (array_values($lines) as $index => $line) {
            if (empty($line['account_doc_num']) || empty($line['amount'])) {
                continue;
            }

            $accountId = Account::query()
                ->where('company_id', $record->company_id)
                ->where('doc_num', $line['account_doc_num'])
                ->value('id');
            $amount = $this->amounts->normalize($line['amount'] ?? 0, 4);

            $record->lines()->create([
                'line_number' => $index + 1,
                'account_id' => $accountId,
                'amount' => $amount,
                'amount_base' => $this->amounts->multiply($amount, $exchangeRate, 4),
                'description' => $line['description'] ?? null,
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function linesChanged(Cheque $record, array $lines): bool
    {
        $record->loadMissing('lines.account');
        $existing = $record->lines->map(fn ($line): array => [
            'account_doc_num' => $line->account?->doc_num,
            'amount' => $this->amounts->normalize($line->amount, 4),
            'description' => $line->description,
            'notes' => $line->notes,
        ])->values()->all();

        $incoming = collect($lines)
            ->filter(fn (array $line): bool => ! empty($line['account_doc_num']) || ! empty($line['amount']))
            ->map(fn (array $line): array => [
                'account_doc_num' => $line['account_doc_num'] ?? null,
                'amount' => $this->amounts->normalize($line['amount'] ?? 0, 4),
                'description' => $line['description'] ?? null,
                'notes' => $line['notes'] ?? null,
            ])
            ->values()
            ->all();

        return json_encode($existing) !== json_encode($incoming);
    }

    private function initialStatus(string $chequeType): string
    {
        return $chequeType === Cheque::TypeIssued ? Cheque::StatusDraft : Cheque::StatusReceived;
    }

    private function assertEditable(Cheque $record): void
    {
        if ($record->isLockedForEditing()) {
            throw new DomainException(__('cheques.messages.document_locked'));
        }
    }

    private function assertDeletable(Cheque $record): void
    {
        if (! $record->isDeletable()) {
            throw new DomainException(__('cheques.messages.document_delete_blocked'));
        }
    }

    private function assertOwned(Cheque $record, int $companyId): void
    {
        if ((int) $record->company_id !== $companyId) {
            throw new DomainException(__('cheques.messages.not_found_in_context'));
        }
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(Cheque $record, array $values): array
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
     * @return array{doc_number: int, doc_num: string}
     */
    private function nextScopedDocument(string $chequeType, string $key, int $companyId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('cheques')));
        }

        $nextNumber = ((int) Cheque::query()
            ->where('company_id', $companyId)
            ->where('cheque_type', $chequeType)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->documents->format($key, $nextNumber),
        ];
    }
}
