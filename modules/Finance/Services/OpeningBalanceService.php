<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\OpeningBalance;

class OpeningBalanceService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingContextService $operatingContext,
        private readonly NumericFormatService $numbers,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $context = $this->currentContext();
            $record = OpeningBalance::query()->create([
                ...$this->values($data, $context),
                ...$this->document($data, $context),
                'is_closed' => true,
                'created_by' => auth()->id(),
            ]);
            $this->syncLines($record, $data['lines'] ?? []);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load(['lines.account'])];
        });
    }

    public function update(OpeningBalance $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $context = $this->currentContext();
            $this->assertInCurrentContext($record, $context);
            $this->assertEditable($record);

            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = [...$this->values($data, $context), 'is_closed' => true];
            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, $context)];
            }
            $linesChanged = $this->linesChanged($record, $data['lines'] ?? []);
            $changes = $this->changes($record, $values);

            if ($changes === [] && ! $linesChanged) {
                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                ];
            }

            $this->audit->saveUpdate($record, $values);
            $this->syncLines($record->refresh(), $data['lines'] ?? []);

            return [
                'record' => $record->refresh()->load(['lines.account']),
                'changed' => true,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(OpeningBalance $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertDeletable($record);
            $this->audit->softDelete($record);
        });
    }

    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $deleted = 0;
            $context = $this->currentContext();

            foreach (OpeningBalance::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->whereIn('doc_num', $docNums)
                ->get() as $record) {
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

    public function restore(OpeningBalance $record): OpeningBalance
    {
        return DB::transaction(function () use ($record): OpeningBalance {
            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     * @return array{doc_number: int, doc_num: string}
     */
    private function document(array $data, array $context): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('opening_balances', (int) $data['doc_number'])]
            : $this->nextScopedDocument($context['company_id'], $context['financial_period_id']);
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     * @return array<string, mixed>
     */
    private function values(array $data, array $context): array
    {
        $currency = Currency::query()
            ->where('company_id', $context['company_id'])
            ->where('doc_num', $data['currency_doc_num'])
            ->first();

        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'currency_id' => $currency?->getKey(),
            'document_date' => $data['document_date'],
            'exchange_rate' => $currency?->is_main
                ? '1.000000'
                : ($this->numbers->normalizeToScale($data['exchange_rate'] ?? 1, 6) ?? '1.000000'),
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => OpeningBalance::StatusDraft,
            'is_cancelled' => false,
            'approved' => false,
        ];
    }

    private function changes(OpeningBalance $record, array $values): array
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
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(OpeningBalance $record, array $lines): void
    {
        $record->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $accountId = Account::query()
                ->where('company_id', $record->company_id)
                ->where('doc_num', $line['account_doc_num'])
                ->value('id');
            $amount = $this->numbers->normalizeToScale($line['amount'] ?? 0, 4) ?? '0.0000';
            $type = (string) ($line['transaction_type'] ?? '');

            $record->lines()->create([
                'line_no' => $index + 1,
                'account_id' => $accountId,
                'debit_amount' => $type === 'debit' ? $amount : '0.0000',
                'credit_amount' => $type === 'credit' ? $amount : '0.0000',
                'description' => $line['description'] ?? null,
                'customer_id' => $line['customer_id'] ?? null,
                'supplier_id' => $line['supplier_id'] ?? null,
                'employee_id' => $line['employee_id'] ?? null,
                'bank_account_id' => $line['bank_account_id'] ?? null,
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'branch_id' => $line['branch_id'] ?? null,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function linesChanged(OpeningBalance $record, array $lines): bool
    {
        $record->loadMissing('lines.account');
        $existing = $record->lines->map(function ($line): array {
            $normalizedDebit = $this->numbers->normalize($line->debit_amount);
            $isDebit = $normalizedDebit !== null && ! $this->numbers->equivalent($normalizedDebit, 0);
            $amount = $isDebit ? $line->debit_amount : $line->credit_amount;

            return [
                'account_doc_num' => $line->account?->doc_num,
                'transaction_type' => $isDebit ? 'debit' : 'credit',
                'amount' => $this->numbers->normalizeToScale($amount, 4) ?? '0.0000',
                'description' => $line->description,
            ];
        })->values()->all();

        $incoming = collect($lines)->map(fn (array $line): array => [
            'account_doc_num' => $line['account_doc_num'] ?? null,
            'transaction_type' => $line['transaction_type'] ?? null,
            'amount' => $this->numbers->normalizeToScale($line['amount'] ?? 0, 4) ?? '0.0000',
            'description' => $line['description'] ?? null,
        ])->values()->all();

        return json_encode($existing) !== json_encode($incoming);
    }

    private function assertEditable(OpeningBalance $record): void
    {
        if ($record->isApproved()) {
            throw new DomainException(__('opening_balances.messages.approved_edit_forbidden'));
        }

        if ($record->isClosed()) {
            throw new DomainException(__('opening_balances.messages.closed_edit_forbidden'));
        }

        if ($record->is_cancelled || $record->journal_entry_id !== null) {
            throw new DomainException(__('opening_balances.messages.document_locked'));
        }
    }

    /**
     * @param  array{company_id: int, financial_period_id: int}  $context
     */
    private function assertInCurrentContext(OpeningBalance $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id'] || (int) $record->financial_period_id !== $context['financial_period_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }
    }

    private function assertDeletable(OpeningBalance $record): void
    {
        if ($record->isApproved()) {
            throw new DomainException(__('opening_balances.messages.approved_delete_forbidden'));
        }

        if ($record->isClosed()) {
            throw new DomainException(__('opening_balances.messages.closed_delete_forbidden'));
        }

        if ($record->is_cancelled || $record->journal_entry_id !== null) {
            throw new DomainException(__('opening_balances.messages.document_delete_blocked'));
        }
    }

    /**
     * @return array{company_id: int, financial_period_id: int}
     */
    private function currentContext(): array
    {
        $context = $this->operatingContext->snapshot(request());

        if (! $context['company_id'] || ! $context['financial_period_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
        ];
    }

    private function nextScopedDocument(int $companyId, int $financialPeriodId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('opening_balances')));
        }

        $nextNumber = ((int) OpeningBalance::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->documents->format('opening_balances', $nextNumber),
        ];
    }
}
