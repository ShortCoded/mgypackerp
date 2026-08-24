<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;

class ManualJournalEntryService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingContextService $operatingContext,
        private readonly NumericFormatService $numbers,
        private readonly FinancialPeriodService $financialPeriods,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data): JournalEntry {
            $context = $this->context();
            $currency = $this->mainCurrency($context['company_id']);
            $record = JournalEntry::query()->create([
                ...$this->documents->nextForCompany(
                    'journal_entries',
                    JournalEntry::class,
                    $context['company_id'],
                    fn ($query) => $query->where('financial_period_id', $context['financial_period_id']),
                ),
                ...$this->headerValues($data, $context, $currency),
                'status' => JournalEntry::StatusDraft,
                'is_system_generated' => false,
                'is_posted' => false,
                'approved' => false,
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $data['lines']);
            $this->audit->clearCreationUpdateAudit($record);

            return $record->refresh()->load($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{record: JournalEntry, changed: bool}
     */
    public function update(JournalEntry $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $record = JournalEntry::query()->lockForUpdate()->findOrFail($record->getKey());
            $record->assertManuallyEditable();
            $context = $this->context();
            $this->assertInContext($record, $context);
            $currency = $this->mainCurrency($context['company_id']);
            $values = $this->headerValues($data, $context, $currency);
            $record->fill($values);
            $changed = $record->isDirty() || $this->linesChanged($record, $data['lines']);

            if (! $changed) {
                return ['record' => $record->load($this->relations()), 'changed' => false];
            }

            $this->audit->saveUpdate($record, $values);
            $this->syncLines($record, $data['lines']);

            return ['record' => $record->refresh()->load($this->relations()), 'changed' => true];
        });
    }

    public function post(JournalEntry $record): JournalEntry
    {
        return DB::transaction(function () use ($record): JournalEntry {
            $record = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($record->getKey());
            $record->assertManuallyEditable();
            $context = $this->context();
            $this->assertInContext($record, $context);
            $this->assertOpenPeriod($record);
            $this->assertDimensionsValid($record);
            $this->assertBalanced($record);
            $now = now();

            $this->audit->saveUpdate($record, [
                'status' => JournalEntry::StatusPosted,
                'is_posted' => true,
                'posted_at' => $now,
                'posted_by' => auth()->id(),
                'approved' => true,
                'approved_at' => $now,
                'approved_by' => auth()->id(),
            ]);

            return $record->refresh()->load($this->relations());
        });
    }

    public function delete(JournalEntry $record): void
    {
        DB::transaction(function () use ($record): void {
            $record->assertManuallyEditable();
            $this->audit->softDelete($record);
        });
    }

    public function restore(JournalEntry $record): JournalEntry
    {
        return DB::transaction(function () use ($record): JournalEntry {
            if (! $record->trashed()) {
                throw new DomainException(__('journal_entries.messages.restore_requires_trashed'));
            }

            $record->assertManuallyEditable();
            $context = $this->context();
            $this->assertInContext($record, $context);

            $conflict = JournalEntry::query()
                ->where('company_id', $record->company_id)
                ->where('financial_period_id', $record->financial_period_id)
                ->where('doc_num', $record->doc_num)
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                throw new DomainException(__('journal_entries.messages.restore_doc_num_conflict'));
            }

            $this->audit->restore($record);

            return $record->refresh()->load($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     * @return array<string, mixed>
     */
    private function headerValues(array $data, array $context, Currency $currency): array
    {
        return [
            'entry_date' => $data['entry_date'],
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'currency_id' => $currency->getKey(),
            'exchange_rate' => '1.000000',
            'reference_no' => $data['reference_no'] ?? null,
            'description' => $data['description'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(JournalEntry $record, array $lines): void
    {
        $record->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $record->lines()->create([
                'line_no' => $index + 1,
                'account_id' => $this->accountId($record, (string) $line['account_doc_num']),
                'debit_amount' => $this->numbers->normalizeToScale($line['debit_amount'], 4),
                'credit_amount' => $this->numbers->normalizeToScale($line['credit_amount'], 4),
                'description' => $line['description'] ?? null,
                'customer_id' => $this->partyId(Customer::class, $record->company_id, $line['customer_doc_num'] ?? null),
                'supplier_id' => $this->partyId(Supplier::class, $record->company_id, $line['supplier_doc_num'] ?? null),
                'employee_id' => $this->partyId(HrEmployee::class, $record->company_id, $line['employee_doc_num'] ?? null),
                'cost_center_id' => $this->costCenterId($record, $line['cost_center_doc_num'] ?? null),
                'branch_id' => $record->branch_id,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function linesChanged(JournalEntry $record, array $lines): bool
    {
        $record->loadMissing($this->relations());
        $existing = $record->lines->map(fn ($line): array => [
            'account_doc_num' => $line->account?->doc_num,
            'debit_amount' => $this->numbers->normalizeToScale($line->debit_amount, 4),
            'credit_amount' => $this->numbers->normalizeToScale($line->credit_amount, 4),
            'description' => $line->description,
            'cost_center_doc_num' => $line->costCenter?->doc_num,
            'customer_doc_num' => $line->customer?->doc_num,
            'supplier_doc_num' => $line->supplier?->doc_num,
            'employee_doc_num' => $line->employee?->doc_num,
        ])->values()->all();
        $incoming = collect($lines)->map(fn (array $line): array => [
            'account_doc_num' => (string) $line['account_doc_num'],
            'debit_amount' => $this->numbers->normalizeToScale($line['debit_amount'], 4),
            'credit_amount' => $this->numbers->normalizeToScale($line['credit_amount'], 4),
            'description' => $line['description'] ?? null,
            'cost_center_doc_num' => $line['cost_center_doc_num'] ?? null,
            'customer_doc_num' => $line['customer_doc_num'] ?? null,
            'supplier_doc_num' => $line['supplier_doc_num'] ?? null,
            'employee_doc_num' => $line['employee_doc_num'] ?? null,
        ])->values()->all();

        return $existing !== $incoming;
    }

    private function assertBalanced(JournalEntry $record): void
    {
        if ($record->lines->count() < 2) {
            throw new DomainException(__('journal_entries.messages.minimum_lines'));
        }

        $debits = '0.0000';
        $credits = '0.0000';

        foreach ($record->lines as $line) {
            $debit = $this->numbers->normalizeToScale($line->debit_amount, 4) ?? '0.0000';
            $credit = $this->numbers->normalizeToScale($line->credit_amount, 4) ?? '0.0000';
            $hasDebit = bccomp($debit, '0', 4) === 1;
            $hasCredit = bccomp($credit, '0', 4) === 1;

            if ($hasDebit === $hasCredit) {
                throw new DomainException(__('journal_entries.messages.one_side_required'));
            }

            $debits = bcadd($debits, $debit, 4);
            $credits = bcadd($credits, $credit, 4);
        }

        if (bccomp($debits, '0', 4) !== 1 || bccomp($debits, $credits, 4) !== 0) {
            throw new DomainException(__('journal_entries.messages.unbalanced'));
        }
    }

    private function assertDimensionsValid(JournalEntry $record): void
    {
        $accountIds = $record->lines->pluck('account_id')->filter()->unique()->values();
        $validAccountCount = Account::query()
            ->forCompany((int) $record->company_id)
            ->active()
            ->where('is_group', false)
            ->where('is_postable', true)
            ->whereIn('id', $accountIds)
            ->lockForUpdate()
            ->pluck('id')
            ->count();

        if ($validAccountCount !== $accountIds->count()) {
            throw new DomainException(__('journal_entries.messages.account_invalid'));
        }

        $costCenterIds = $record->lines->pluck('cost_center_id')->filter()->unique()->values();
        $validCostCenterCount = CostCenter::query()
            ->forCompany((int) $record->company_id)
            ->active()
            ->where('is_group', false)
            ->whereIn('id', $costCenterIds)
            ->lockForUpdate()
            ->pluck('id')
            ->count();

        if ($validCostCenterCount !== $costCenterIds->count()) {
            throw new DomainException(__('journal_entries.messages.cost_center_invalid'));
        }

        $branchIds = $record->lines->pluck('branch_id')->push($record->branch_id)->filter()->unique()->values();
        $validBranchCount = Branch::query()
            ->where('company_id', $record->company_id)
            ->active()
            ->whereIn('id', $branchIds)
            ->lockForUpdate()
            ->pluck('id')
            ->count();

        if ($validBranchCount !== $branchIds->count()) {
            throw new DomainException(__('journal_entries.messages.posting_dimension_invalid'));
        }

        $customers = Customer::query()
            ->forCompany((int) $record->company_id)
            ->active()
            ->whereIn('id', $record->lines->pluck('customer_id')->filter()->unique())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $suppliers = Supplier::query()
            ->forCompany((int) $record->company_id)
            ->active()
            ->whereIn('id', $record->lines->pluck('supplier_id')->filter()->unique())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $employees = HrEmployee::query()
            ->where('company_id', $record->company_id)
            ->active()
            ->whereIn('id', $record->lines->pluck('employee_id')->filter()->unique())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($record->lines as $line) {
            $customer = $line->customer_id ? $customers->get($line->customer_id) : null;
            $supplier = $line->supplier_id ? $suppliers->get($line->supplier_id) : null;

            if (($line->customer_id && (! $customer || (int) $customer->account_id !== (int) $line->account_id))
                || ($line->supplier_id && (! $supplier || (int) $supplier->account_id !== (int) $line->account_id))
                || ($line->employee_id && ! $employees->has($line->employee_id))) {
                throw new DomainException(__('journal_entries.messages.posting_dimension_invalid'));
            }
        }
    }

    private function assertOpenPeriod(JournalEntry $record): void
    {
        $this->financialPeriods->resolveOpenForPostingDate(
            (int) $record->company_id,
            $record->entry_date,
            expectedPeriodId: (int) $record->financial_period_id,
            lockForUpdate: true,
        );
    }

    /**
     * @return array{company_id: int, financial_period_id: int, branch_id: int}
     */
    private function context(): array
    {
        $snapshot = $this->operatingContext->snapshot(request());

        if ($snapshot['company_id'] === null || $snapshot['financial_period_id'] === null || $snapshot['branch_id'] === null) {
            throw new DomainException(__('journal_entries.messages.operating_context_required'));
        }

        return [
            'company_id' => (int) $snapshot['company_id'],
            'financial_period_id' => (int) $snapshot['financial_period_id'],
            'branch_id' => (int) $snapshot['branch_id'],
        ];
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     */
    private function assertInContext(JournalEntry $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id'] || (int) $record->financial_period_id !== $context['financial_period_id']) {
            throw new DomainException(__('journal_entries.messages.context_mismatch'));
        }
    }

    private function mainCurrency(int $companyId): Currency
    {
        $currency = Currency::query()->forCompany($companyId)->active()->where('is_main', true)->first();

        if (! $currency) {
            throw new DomainException(__('journal_entries.messages.main_currency_required'));
        }

        return $currency;
    }

    private function accountId(JournalEntry $record, string $docNum): int
    {
        return (int) Account::query()
            ->forCompany((int) $record->company_id)
            ->active()
            ->where('is_group', false)
            ->where('is_postable', true)
            ->where('doc_num', $docNum)
            ->valueOrFail('id');
    }

    private function costCenterId(JournalEntry $record, mixed $docNum): ?int
    {
        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        return (int) CostCenter::query()
            ->forCompany((int) $record->company_id)
            ->active()
            ->where('is_group', false)
            ->where('doc_num', $docNum)
            ->valueOrFail('id');
    }

    /**
     * @param  class-string<Customer|Supplier|HrEmployee>  $model
     */
    private function partyId(string $model, int $companyId, mixed $docNum): ?int
    {
        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        $query = $model::query()->active();
        if ($model === HrEmployee::class) {
            $query->where('company_id', $companyId);
        } else {
            $query->forCompany($companyId);
        }

        return (int) $query
            ->where('doc_num', $docNum)
            ->valueOrFail('id');
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return ['lines.account', 'lines.costCenter', 'lines.customer', 'lines.supplier', 'lines.employee', 'branch', 'currency'];
    }
}
