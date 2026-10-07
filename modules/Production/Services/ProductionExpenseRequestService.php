<?php

namespace Modules\Production\Services;

use App\Services\PostingAccountResolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Inventory\Models\InventoryDocument;
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
        private readonly PostingAccountResolver $postingAccounts,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(ProductionRun $run, array $data): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($run, $data): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = ProductionRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $this->assertContext($locked, $context);
            if (in_array($locked->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_execution.messages.expense_run_closed'));
            }

            $paymentChannel = $data['payment_channel'] ?? 'cashbox';
            $currency = Currency::query()->forCompany($context['company_id'])->active()->findOrFail($data['currency_id']);
            $cashbox = $paymentChannel === 'cashbox' && filled($data['cashbox_id'] ?? null)
                ? Cashbox::query()->forCompany($context['company_id'])->where('branch_id', $context['branch_id'])->active()->findOrFail($data['cashbox_id'])
                : null;
            $account = filled($data['expense_account_id'] ?? null)
                ? Account::query()->forCompany($context['company_id'])->active()->where('is_postable', true)->where('account_type', Account::TypeExpense)->findOrFail($data['expense_account_id'])
                : null;
            $bankAccount = $paymentChannel === 'bank' && filled($data['bank_account_id'] ?? null)
                ? BankAccount::query()->forCompany($context['company_id'])->active()->findOrFail($data['bank_account_id'])
                : null;

            if (! $account) {
                throw new DomainException(__('production_execution.messages.expense_account_required'));
            }
            if ($paymentChannel === 'cashbox' && ! $cashbox) {
                throw new DomainException(__('production_execution.messages.expense_cashbox_account_required'));
            }
            if ($paymentChannel === 'bank' && ! $bankAccount) {
                throw new DomainException(__('production_execution.messages.expense_bank_account_required'));
            }
            if ($paymentChannel === 'bank' && (int) $bankAccount->currency_id !== (int) $currency->getKey()) {
                throw new DomainException(__('production_execution.messages.expense_bank_currency_mismatch'));
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
                'exchange_rate' => $this->exchangeRateFor($currency, $data['exchange_rate'] ?? null),
                'payment_channel' => $paymentChannel,
                'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $bankAccount?->getKey(),
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
    public function update(ProductionExpenseRequest $request, array $data): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($request, $data): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = ProductionExpenseRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if (! $locked->isEditable() || $locked->production_run_id === null) {
                throw new DomainException(__('production_execution.messages.expense_submitted_edit_only'));
            }

            $run = ProductionRun::query()
                ->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])
                ->lockForUpdate()
                ->findOrFail($data['production_run_id']);
            $this->assertContext($run, $context);
            $paymentChannel = $data['payment_channel'];
            $currency = Currency::query()->forCompany($context['company_id'])->active()->findOrFail($data['currency_id']);
            $cashbox = $paymentChannel === 'cashbox' && filled($data['cashbox_id'] ?? null)
                ? Cashbox::query()->forCompany($context['company_id'])->where('branch_id', $context['branch_id'])->active()->findOrFail($data['cashbox_id'])
                : null;
            $account = filled($data['expense_account_id'] ?? null)
                ? Account::query()->forCompany($context['company_id'])->active()->where('is_postable', true)->where('account_type', Account::TypeExpense)->findOrFail($data['expense_account_id'])
                : null;
            $bankAccount = $paymentChannel === 'bank' && filled($data['bank_account_id'] ?? null)
                ? BankAccount::query()->forCompany($context['company_id'])->active()->findOrFail($data['bank_account_id'])
                : null;

            if (! $account) {
                throw new DomainException(__('production_execution.messages.expense_account_required'));
            }
            if ($paymentChannel === 'cashbox' && ! $cashbox) {
                throw new DomainException(__('production_execution.messages.expense_cashbox_account_required'));
            }
            if ($paymentChannel === 'bank' && ! $bankAccount) {
                throw new DomainException(__('production_execution.messages.expense_bank_account_required'));
            }
            if ($paymentChannel === 'bank' && (int) $bankAccount->currency_id !== (int) $currency->getKey()) {
                throw new DomainException(__('production_execution.messages.expense_bank_currency_mismatch'));
            }

            $locked->update([
                'production_order_id' => $run->production_order_id,
                'production_run_id' => $run->getKey(),
                'amount' => $data['amount'],
                'currency_id' => $currency->getKey(),
                'exchange_rate' => $this->exchangeRateFor($currency, $data['exchange_rate'] ?? null),
                'payment_channel' => $paymentChannel,
                'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $bankAccount?->getKey(),
                'expense_account_id' => $account?->getKey(),
                'reason' => trim($data['reason']),
                'notes' => $data['notes'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['run', 'currency', 'cashbox', 'bankAccount', 'expenseAccount']);
        });
    }

    public function delete(ProductionExpenseRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $context = $this->requiredContext();
            $locked = ProductionExpenseRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if (! $locked->isEditable() || $locked->production_run_id === null) {
                throw new DomainException(__('production_execution.messages.expense_submitted_edit_only'));
            }

            $locked->update(['deleted_by' => auth()->id()]);
            $locked->delete();
        });
    }

    public function restore(ProductionExpenseRequest $request): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($request): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = ProductionExpenseRequest::withTrashed()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);

            if (! $locked->trashed() || $locked->status !== ProductionExpenseRequest::StatusSubmitted || $locked->production_run_id === null) {
                throw new DomainException(__('production_execution.messages.expense_not_restorable'));
            }

            $run = ProductionRun::query()
                ->whereNotIn('status', [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled])
                ->lockForUpdate()
                ->findOrFail($locked->production_run_id);
            $this->assertContext($run, $context);
            $locked->restore();
            $locked->update(['restored_by' => auth()->id(), 'restored_at' => now(), 'updated_by' => auth()->id()]);

            return $locked->refresh();
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
                ? Account::query()->forCompany($context['company_id'])->active()->where('is_postable', true)->where('account_type', Account::TypeExpense)->findOrFail($data['expense_account_id'])
                : null;
            $bankAccount = filled($data['bank_account_id'] ?? null)
                ? BankAccount::query()->forCompany($context['company_id'])->active()->findOrFail($data['bank_account_id'])
                : null;
            if (! $account) {
                throw new DomainException(__('production_execution.messages.expense_account_required'));
            }
            if (($data['payment_channel'] ?? 'cashbox') === 'cashbox' && ! $cashbox) {
                throw new DomainException(__('production_execution.messages.expense_cashbox_account_required'));
            }
            if (($data['payment_channel'] ?? 'cashbox') === 'bank' && ! $bankAccount) {
                throw new DomainException(__('production_execution.messages.expense_bank_account_required'));
            }
            if (($data['payment_channel'] ?? 'cashbox') === 'bank' && (int) $bankAccount->currency_id !== (int) $currency->getKey()) {
                throw new DomainException(__('production_execution.messages.expense_bank_currency_mismatch'));
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
                'exchange_rate' => $this->exchangeRateFor($currency, $data['exchange_rate'] ?? null),
                'payment_channel' => $data['payment_channel'] ?? 'cashbox',
                'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $bankAccount?->getKey(),
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
    public function updateForMaintenance(ProductionExpenseRequest $request, array $data): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($request, $data): ProductionExpenseRequest {
            $context = $this->requiredContext();
            $locked = ProductionExpenseRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            if ($locked->maintenance_work_order_id === null || $locked->status !== ProductionExpenseRequest::StatusSubmitted) {
                throw new DomainException(__('maintenance.messages.expense_not_editable'));
            }

            $workOrder = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($data['maintenance_work_order_id']);
            $this->assertContext($workOrder, $context);
            if (in_array($workOrder->status, [MaintenanceWorkOrder::StatusCompleted, MaintenanceWorkOrder::StatusClosed, MaintenanceWorkOrder::StatusCancelled], true)) {
                throw new DomainException(__('maintenance.messages.expense_order_closed'));
            }

            $currency = Currency::query()->forCompany($context['company_id'])->active()->findOrFail($data['currency_id']);
            $cashbox = filled($data['cashbox_id'] ?? null)
                ? Cashbox::query()->forCompany($context['company_id'])->where('branch_id', $context['branch_id'])->active()->findOrFail($data['cashbox_id'])
                : null;
            $account = filled($data['expense_account_id'] ?? null)
                ? Account::query()->forCompany($context['company_id'])->active()->where('is_postable', true)->where('account_type', Account::TypeExpense)->findOrFail($data['expense_account_id'])
                : null;
            $bankAccount = filled($data['bank_account_id'] ?? null)
                ? BankAccount::query()->forCompany($context['company_id'])->active()->findOrFail($data['bank_account_id'])
                : null;
            if (! $account) {
                throw new DomainException(__('production_execution.messages.expense_account_required'));
            }
            if (($data['payment_channel'] ?? 'cashbox') === 'cashbox' && ! $cashbox) {
                throw new DomainException(__('production_execution.messages.expense_cashbox_account_required'));
            }
            if (($data['payment_channel'] ?? 'cashbox') === 'bank' && ! $bankAccount) {
                throw new DomainException(__('production_execution.messages.expense_bank_account_required'));
            }
            if (($data['payment_channel'] ?? 'cashbox') === 'bank' && (int) $bankAccount->currency_id !== (int) $currency->getKey()) {
                throw new DomainException(__('production_execution.messages.expense_bank_currency_mismatch'));
            }

            $locked->update([
                'maintenance_work_order_id' => $workOrder->getKey(),
                'amount' => $data['amount'],
                'currency_id' => $currency->getKey(),
                'exchange_rate' => $this->exchangeRateFor($currency, $data['exchange_rate'] ?? null),
                'payment_channel' => $data['payment_channel'],
                'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $bankAccount?->getKey(),
                'expense_account_id' => $account?->getKey(),
                'reason' => trim($data['reason']),
                'notes' => $data['notes'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load(['maintenanceWorkOrder.asset', 'maintenanceWorkOrder.mold', 'currency', 'cashbox', 'bankAccount', 'expenseAccount']);
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
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            $locked = ProductionExpenseRequest::query()->with(['cashbox.account', 'bankAccount.account', 'currency', 'expenseAccount', 'run', 'maintenanceWorkOrder.asset'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            if ($locked->production_run_id !== null) {
                $run = ProductionRun::query()->whereKey($locked->production_run_id)->lockForUpdate()->firstOrFail();
                app(ProductionStageTransferService::class)->assertCostMutationAllowed($run, conversionOnly: true);
            }

            if ($locked->status !== ProductionExpenseRequest::StatusApproved) {
                throw new DomainException(__('production_execution.messages.expense_approved_only'));
            }
            if (! $locked->expenseAccount?->is_postable || $locked->expenseAccount->status !== 'active' || $locked->expenseAccount->trashed()) {
                throw new DomainException(__('production_execution.messages.expense_account_required'));
            }
            $exchangeRate = $this->exchangeRateFor($locked->currency, $locked->exchange_rate);
            $voucher = null;
            if ($locked->payment_channel === 'cashbox') {
                if (! $locked->cashbox?->account) {
                    throw new DomainException(__('production_execution.messages.expense_cashbox_account_required'));
                }
                $created = $this->cashVouchers->create(CashVoucher::TypePayment, [
                    'voucher_date' => now()->toDateString(),
                    'cashbox_doc_num' => $locked->cashbox->doc_num,
                    'currency_doc_num' => $locked->currency->doc_num,
                    'exchange_rate' => $exchangeRate,
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
                $creditAccount = $locked->cashbox->account;
            } elseif ($locked->payment_channel === 'bank') {
                if (! $locked->bankAccount?->account || $locked->bankAccount->trashed() || $locked->bankAccount->status !== 'active'
                    || ! $locked->bankAccount->account->is_postable || $locked->bankAccount->account->status !== 'active' || $locked->bankAccount->account->trashed()) {
                    throw new DomainException(__('production_execution.messages.expense_bank_account_required'));
                }
                if ((int) $locked->bankAccount->currency_id !== (int) $locked->currency_id) {
                    throw new DomainException(__('production_execution.messages.expense_bank_currency_mismatch'));
                }
                $creditAccount = $locked->bankAccount->account;
            } else {
                throw new DomainException(__('production_execution.messages.expense_invalid_state'));
            }
            $debitAccount = $locked->production_run_id !== null
                ? $this->postingAccounts->resolve($context['company_id'], PostingAccountResolver::WorkInProcessInventory, __('production_execution.production_expense'))
                : $locked->expenseAccount;
            $journal = $this->journals->createPostedFromSource([
                'entry_date' => now()->toDateString(),
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'branch_id' => $context['branch_id'],
                'currency_id' => $locked->currency_id,
                'exchange_rate' => $exchangeRate,
                'description' => __('production_execution.messages.expense_journal_description', ['number' => $locked->doc_num]),
                'notes' => $locked->notes,
                'source_type' => 'production_expense_payment',
                'source_id' => $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
            ], [
                [
                    'account_id' => $debitAccount->getKey(),
                    'debit_amount' => $locked->amount,
                    'credit_amount' => '0.0000',
                    'description' => $locked->reason,
                    'cost_center_id' => $locked->run?->cost_center_id ?? $locked->maintenanceWorkOrder?->asset?->cost_center_id,
                ],
                [
                    'account_id' => $creditAccount->getKey(),
                    'debit_amount' => '0.0000',
                    'credit_amount' => $locked->amount,
                    'description' => $locked->reason,
                    'cost_center_id' => $locked->run?->cost_center_id ?? $locked->maintenanceWorkOrder?->asset?->cost_center_id,
                    'bank_account_id' => $locked->payment_channel === 'bank' ? $locked->bank_account_id : null,
                ],
            ]);
            $locked->update([
                'status' => ProductionExpenseRequest::StatusPaid,
                'cash_voucher_id' => $voucher?->getKey(),
                'journal_entry_id' => $journal->getKey(),
                'cost_accounting_snapshot' => ['version' => 1, 'capitalized' => $locked->production_run_id !== null,
                    'journal_id' => (int) $journal->id, 'debit_account_id' => (int) $debitAccount->id, 'credit_account_id' => (int) $creditAccount->id,
                    'expense_account_id' => (int) $locked->expense_account_id, 'currency_id' => (int) $locked->currency_id,
                    'exchange_rate' => $exchangeRate, 'amount' => (string) $locked->amount,
                    'base_amount' => bcmul((string) $locked->amount, $exchangeRate, 8)],
                'paid_by' => auth()->id(),
                'paid_at' => now(),
                'updated_by' => auth()->id(),
            ]);

            return $locked->refresh()->load('cashVoucher');
        });
    }

    public function withdrawApproval(ProductionExpenseRequest $request, string $reason): ProductionExpenseRequest
    {
        Gate::authorize('production.expenses.reverse');

        return DB::transaction(function () use ($request, $reason): ProductionExpenseRequest {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            $locked = ProductionExpenseRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $locked->request_date,
                $context['financial_period_id'], lockForUpdate: true);
            if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 2000) {
                throw new DomainException(__('production_execution.messages.reversal_reason_required'));
            }
            if ($locked->production_run_id === null || $locked->paid_at !== null || $locked->paid_by !== null
                || $locked->cash_voucher_id !== null || $locked->journal_entry_id !== null || $locked->reversal_journal_entry_id !== null
                || $locked->cost_accounting_snapshot !== null
                || JournalEntry::withTrashed()->where('company_id', $locked->company_id)->where('source_id', $locked->id)
                    ->whereIn('source_type', ['production_expense_payment', 'production_expense_reversal'])->exists()) {
                throw new DomainException(__('cancellation_review.expense_withdraw_ineligible'));
            }
            if ($locked->status === ProductionExpenseRequest::StatusRejected
                && DB::table('activity_log')->where('company_id', $locked->company_id)->where('subject_type', ProductionExpenseRequest::class)
                    ->where('subject_id', $locked->id)->where('event', 'production.expense.approval_withdrawn')->exists()) {
                return $locked;
            }
            if ($locked->status !== ProductionExpenseRequest::StatusApproved || $locked->approved_by === null || $locked->approved_at === null) {
                throw new DomainException(__('cancellation_review.expense_withdraw_ineligible'));
            }
            $run = ProductionRun::query()->lockForUpdate()->findOrFail($locked->production_run_id);
            $this->assertContext($run, $context);
            app(ProductionStageTransferService::class)->assertCostMutationAllowed($run, conversionOnly: true);
            if ($run->status === ProductionRun::StatusCancelled || bccomp((string) $run->received_base_quantity, '0', 8) > 0
                || InventoryDocument::query()->where('company_id', $run->company_id)
                    ->where('branch_id', $run->branch_id)->whereIn('status', ['draft', 'approved', 'posted'])
                    ->whereIn('document_type', [
                        InventoryDocument::TypeProductionReceipt,
                        InventoryDocument::TypeProductionHandover,
                    ])->where(fn ($query) => $query->where('production_run_id', $run->id)
                    ->orWhereHas('lines', fn ($lines) => $lines->where('production_run_id', $run->id)))->exists()) {
                throw new DomainException(__('production_stage_transfer.owner_recovery_required'));
            }
            $before = $locked->getRawOriginal();
            $locked->forceFill(['status' => ProductionExpenseRequest::StatusRejected, 'updated_by' => auth()->id()])->save();
            app(ActivityLogger::class)->log(request(), 'production', 'production.expense.approval_withdrawn', 'success', [
                'subject' => $locked, 'causer' => auth()->user(), 'company_id' => $locked->company_id,
                'branch_id' => $locked->branch_id, 'financial_period_id' => $locked->financial_period_id,
                'properties_only' => true, 'properties' => ['reason' => trim($reason), 'before_snapshot' => $before,
                    'after_status' => ProductionExpenseRequest::StatusRejected, 'unpaid' => true, 'financial_effect' => 'none'],
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function reverse(ProductionExpenseRequest $request, string $reason): ProductionExpenseRequest
    {
        return DB::transaction(function () use ($request, $reason): ProductionExpenseRequest {
            $context = $this->requiredContext();
            Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            $locked = ProductionExpenseRequest::query()->with(['cashVoucher', 'journalEntry'])->lockForUpdate()->findOrFail($request->getKey());
            $this->assertContext($locked, $context);
            if ($locked->production_run_id !== null) {
                $run = ProductionRun::query()->whereKey($locked->production_run_id)->lockForUpdate()->firstOrFail();
                app(ProductionStageTransferService::class)->assertCostMutationAllowed($run, conversionOnly: true);
            }

            if ($locked->status !== ProductionExpenseRequest::StatusPaid || ! $locked->journalEntry
                || ($locked->payment_channel === 'cashbox' && ! $locked->cashVoucher)) {
                throw new DomainException(__('production_execution.messages.expense_paid_only'));
            }
            if (blank($reason)) {
                throw new DomainException(__('production_execution.messages.reversal_reason_required'));
            }

            if ($locked->cashVoucher) {
                $this->cashVouchers->cancel(CashVoucher::TypePayment, $locked->cashVoucher, $reason);
            }
            $reversal = $locked->journalEntry ? $this->journals->createPostedReversalFromSource($locked->journalEntry, [
                'entry_date' => now()->toDateString(),
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'branch_id' => $context['branch_id'],
                'currency_id' => $locked->currency_id,
                'exchange_rate' => (string) $locked->journalEntry->exchange_rate,
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
            if (($values['status'] ?? null) === ProductionExpenseRequest::StatusApproved) {
                $this->exchangeRateFor($locked->currency, $locked->exchange_rate);
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

    private function exchangeRateFor(Currency $currency, mixed $rawRate): string
    {
        if (($rawRate === null || trim((string) $rawRate) === '') && $currency->is_main) {
            return '1.000000';
        }

        $rate = trim((string) $rawRate);
        if (! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $rate) || bccomp($rate, '0', 6) <= 0) {
            throw new DomainException(__('production_execution.messages.expense_exchange_rate_required'));
        }
        if ($currency->is_main && bccomp($rate, '1', 6) !== 0) {
            throw new DomainException(__('production_execution.messages.expense_main_currency_rate'));
        }

        return bcadd($rate, '0', 6);
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
