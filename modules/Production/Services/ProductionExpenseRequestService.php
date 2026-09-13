<?php

namespace Modules\Production\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionRun;

class ProductionExpenseRequestService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $context,
        private readonly CashVoucherService $cashVouchers,
        private readonly JournalEntryService $journals,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(ProductionRun $run, array $data): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($run, $data): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = ProductionRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $this->assertContext($locked, $context);
            $currency = Currency::query()->forCompany($context['company_id'])->active()->findOrFail($data['currency_id']);
            $cashbox = filled($data['cashbox_id'] ?? null)
                ? Cashbox::query()->forCompany($context['company_id'])->where('branch_id', $context['branch_id'])->active()->findOrFail($data['cashbox_id'])
                : null;
            $account = filled($data['expense_account_id'] ?? null)
                ? Account::query()->forCompany($context['company_id'])->active()->where('is_postable', true)->findOrFail($data['expense_account_id'])
                : null;

            if (($data['payment_channel'] ?? 'cashbox') === 'cashbox' && (! $cashbox || ! $account)) {
                throw new DomainException(__('production_execution.messages.expense_cashbox_account_required'));
            }

            $numbers = $this->documents->nextForCompany(
                'production_expense_requests',
                ProductionExpenseRequest::class,
                $context['company_id'],
                fn ($query) => $query->where('financial_period_id', $context['financial_period_id']),
            );

            return ProductionExpenseRequest::query()->create([
                ...$numbers,
                ...$context,
                'production_order_id' => $locked->production_order_id,
                'production_run_id' => $locked->getKey(),
                'request_date' => now()->toDateString(),
                'amount' => $data['amount'],
                'currency_id' => $currency->getKey(),
                'payment_channel' => $data['payment_channel'] ?? 'cashbox',
                'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'expense_account_id' => $account?->getKey(),
                'reason' => trim($data['reason']),
                'notes' => $data['notes'] ?? null,
                'status' => ProductionExpenseRequest::StatusSubmitted,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'created_by' => auth()->id(),
            ])->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function createForMaintenance(MaintenanceWorkOrder $workOrder, array $data): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($workOrder, $data): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            $this->assertContext($locked, $context);
            if (in_array($locked->status, [MaintenanceWorkOrder::StatusCompleted, MaintenanceWorkOrder::StatusClosed, MaintenanceWorkOrder::StatusCancelled], true)) {
                throw new DomainException(__('maintenance.messages.expense_order_closed'));
            }

            $currency = Currency::query()->forCompany($context['company_id'])->active()->findOrFail($data['currency_id']);
            $cashbox = filled($data['cashbox_id'] ?? null)
                ? Cashbox::query()->forCompany($context['company_id'])->where('branch_id', $context['branch_id'])->active()->findOrFail($data['cashbox_id'])
                : null;
            $account = filled($data['expense_account_id'] ?? null)
                ? Account::query()->forCompany($context['company_id'])->active()->where('is_postable', true)->findOrFail($data['expense_account_id'])
                : null;
            if (($data['payment_channel'] ?? 'cashbox') === 'cashbox' && (! $cashbox || ! $account)) {
                throw new DomainException(__('production_execution.messages.expense_cashbox_account_required'));
            }

            $numbers = $this->documents->nextForCompany(
                'production_expense_requests',
                ProductionExpenseRequest::class,
                $context['company_id'],
                fn ($query) => $query->where('financial_period_id', $context['financial_period_id']),
            );

            return ProductionExpenseRequest::query()->create([
                ...$numbers,
                ...$context,
                'maintenance_work_order_id' => $locked->getKey(),
                'request_date' => now()->toDateString(),
                'amount' => $data['amount'],
                'currency_id' => $currency->getKey(),
                'payment_channel' => $data['payment_channel'] ?? 'cashbox',
                'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'expense_account_id' => $account?->getKey(),
                'reason' => trim($data['reason']),
                'notes' => $data['notes'] ?? null,
                'status' => ProductionExpenseRequest::StatusSubmitted,
                'submitted_by' => auth()->id(),
                'submitted_at' => now(),
                'created_by' => auth()->id(),
            ])->refresh();
        });
    }

    public function approve(ProductionExpenseRequest $request): ProductionExpenseRequest
    {
        return $this->transition($request, ProductionExpenseRequest::StatusSubmitted, [
            'status' => ProductionExpenseRequest::StatusApproved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public function pay(ProductionExpenseRequest $request): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($request): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = ProductionExpenseRequest::query()->with(['cashbox.account', 'currency', 'expenseAccount', 'run', 'maintenanceWorkOrder.asset'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if ($locked->status !== ProductionExpenseRequest::StatusApproved) {
                throw new DomainException(__('production_execution.messages.expense_approved_only'));
            }
            if ($locked->payment_channel !== 'cashbox') {
                throw new DomainException(__('production_execution.messages.bank_expense_requires_treasury_payment'));
            }

            $created = $this->cashVouchers->create(CashVoucher::TypePayment, [
                'voucher_date' => now()->toDateString(),
                'cashbox_doc_num' => $locked->cashbox->doc_num,
                'currency_doc_num' => $locked->currency->doc_num,
                'exchange_rate' => 1,
                'amount' => $locked->amount,
                'person_name' => $locked->maintenance_work_order_id ? __('maintenance.maintenance_expense') : __('production_execution.production_expense'),
                'reason' => $locked->reason,
                'description' => __('production_execution.messages.generated_from_expense_request', ['number' => $locked->doc_num]),
                'lines' => [[
                    'account_doc_num' => $locked->expenseAccount->doc_num,
                    'amount' => $locked->amount,
                    'description' => $locked->reason,
                ]],
            ], $context['company_id']);
            $voucher = $this->cashVouchers->approve(CashVoucher::TypePayment, $created['record'], $context['company_id']);
            $journal = $this->journals->createPostedFromSource([
                'entry_date' => now()->toDateString(),
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'branch_id' => $context['branch_id'],
                'currency_id' => $locked->currency_id,
                'exchange_rate' => 1,
                'description' => __('production_execution.messages.expense_journal_description', ['number' => $locked->doc_num]),
                'notes' => $locked->notes,
                'source_type' => 'production_expense_payment',
                'source_id' => $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
            ], [
                [
                    'account_id' => $locked->expenseAccount->getKey(),
                    'debit_amount' => $locked->amount,
                    'credit_amount' => '0.0000',
                    'description' => $locked->reason,
                    'cost_center_id' => $locked->run?->cost_center_id ?? $locked->maintenanceWorkOrder?->asset?->cost_center_id,
                ],
                [
                    'account_id' => $locked->cashbox->account->getKey(),
                    'debit_amount' => '0.0000',
                    'credit_amount' => $locked->amount,
                    'description' => $locked->reason,
                    'cost_center_id' => $locked->run?->cost_center_id ?? $locked->maintenanceWorkOrder?->asset?->cost_center_id,
                ],
            ]);
            $locked->update([
                'status' => ProductionExpenseRequest::StatusPaid,
                'cash_voucher_id' => $voucher->getKey(),
                'journal_entry_id' => $journal->getKey(),
                'paid_by' => auth()->id(),
                'paid_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load('cashVoucher');
        });
    }

    public function reverse(ProductionExpenseRequest $request, string $reason): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($request, $reason): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = ProductionExpenseRequest::query()->with(['cashVoucher', 'journalEntry'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if ($locked->status !== ProductionExpenseRequest::StatusPaid || ! $locked->cashVoucher) {
                throw new DomainException(__('production_execution.messages.expense_paid_only'));
            }
            if (blank($reason)) {
                throw new DomainException(__('production_execution.messages.reversal_reason_required'));
            }

            $this->cashVouchers->cancel(CashVoucher::TypePayment, $locked->cashVoucher, $reason);
            $reversal = $locked->journalEntry ? $this->journals->createPostedReversalFromSource($locked->journalEntry, [
                'entry_date' => now()->toDateString(),
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'branch_id' => $context['branch_id'],
                'currency_id' => $locked->currency_id,
                'exchange_rate' => 1,
                'description' => __('production_execution.messages.expense_reversal_journal_description', ['number' => $locked->doc_num]),
                'notes' => trim($reason),
                'source_type' => 'production_expense_reversal',
                'source_id' => $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
            ]) : null;
            $locked->update([
                'status' => ProductionExpenseRequest::StatusReversed,
                'reversal_journal_entry_id' => $reversal?->getKey(),
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'reversal_reason' => trim($reason),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $values */
    private function transition(ProductionExpenseRequest $request, string $from, array $values): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($request, $from, $values): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = ProductionExpenseRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            if ($locked->status !== $from) {
                throw new DomainException(__('production_execution.messages.expense_invalid_state'));
            }
            $locked->update([...$values, 'updated_by' => auth()->id()]);

            return $locked->refresh();
        });
    }

    /** @return array{company_id: int, financial_period_id: int, branch_id: int} */
    private function requiredContext(): array
    {
        $context = $this->context->snapshot(request());
        if (! $context['company_id'] || ! $context['financial_period_id'] || ! $context['branch_id']) {
            throw new DomainException(__('production_execution.messages.operating_context_required'));
        }

        return ['company_id' => (int) $context['company_id'], 'financial_period_id' => (int) $context['financial_period_id'], 'branch_id' => (int) $context['branch_id']];
    }

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    private function assertContext(object $record, array $context): void
    {
        if ((int) $record->company_id !== $context['company_id']
            || (int) $record->financial_period_id !== $context['financial_period_id']
            || (int) $record->branch_id !== $context['branch_id']) {
            throw new DomainException(__('production_execution.messages.document_outside_context'));
        }
    }
}
