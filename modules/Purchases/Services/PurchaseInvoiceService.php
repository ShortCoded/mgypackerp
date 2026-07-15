<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Services\CashVoucherService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\Supplier;

class PurchaseInvoiceService
{
    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly OperatingContextService $operatingContext,
        private readonly PurchaseInvoiceCalculationService $calculator,
        private readonly ProductComponentUnitOptionsService $unitOptions,
        private readonly CashVoucherService $cashVouchers,
        private readonly JournalEntryService $journalEntries,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $context = $this->context($data);
            $calculation = $this->calculator->calculate($data['lines'] ?? [], $data['header_discount_type'] ?? null, $data['header_discount_value'] ?? 0);
            $record = PurchaseInvoice::query()->create([
                ...$this->values($data, $context),
                ...$calculation['invoice'],
                ...$this->document($data, $context),
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $calculation['lines'], $context);
            $this->syncPaymentSchedules($record->refresh(), $this->schedulesForSync($data, $calculation), $context);
            $record->refreshPaymentTotals();
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $this->load($record->refresh())];
        });
    }

    public function update(PurchaseInvoice $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $record = PurchaseInvoice::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertEditable($record);

            $context = $this->context($data, $record);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $calculation = $this->calculator->calculate($data['lines'] ?? [], $data['header_discount_type'] ?? null, $data['header_discount_value'] ?? 0);
            $values = [
                ...$this->values($data, $context),
                ...$calculation['invoice'],
            ];

            if (array_key_exists('doc_number', $data) && $data['doc_number']) {
                $values = [...$values, ...$this->document($data, $context)];
            }

            $this->audit->saveUpdate($record, $values);
            $record = $record->refresh();

            $this->syncLines($record, $calculation['lines'], $context);
            $this->syncPaymentSchedules($record->refresh(), $this->schedulesForSync($data, $calculation), $context);
            $record->refreshPaymentTotals();

            return [
                'record' => $this->load($record->refresh()),
                'changed' => true,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function approve(PurchaseInvoice $record): PurchaseInvoice
    {
        return DB::transaction(function () use ($record): PurchaseInvoice {
            /** @var PurchaseInvoice $locked */
            $locked = PurchaseInvoice::query()
                ->with(['lines.product', 'supplier.account', 'financialPeriod', 'currency'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            $this->assertApprovable($locked);
            $journalEntry = $this->journalEntries->createPostedFromPurchaseInvoice(
                $locked,
                $this->postingLines($locked),
                $this->supplierPayableAccount($locked),
            );

            $locked->forceFill([
                'status' => PurchaseInvoice::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'journal_entry_id' => $journalEntry->getKey(),
                'updated_by' => auth()->id(),
            ])->save();

            $locked->refreshPaymentTotals();

            return $this->load($locked->refresh());
        });
    }

    public function close(PurchaseInvoice $record): PurchaseInvoice
    {
        return DB::transaction(function () use ($record): PurchaseInvoice {
            /** @var PurchaseInvoice $locked */
            $locked = PurchaseInvoice::query()->lockForUpdate()->findOrFail($record->getKey());

            if (! $locked->isApproved()) {
                throw new DomainException(__('purchase_invoices.messages.close_requires_approved'));
            }

            $locked->forceFill([
                'status' => PurchaseInvoice::StatusClosed,
                'closed_by' => auth()->id(),
                'closed_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();

            return $this->load($locked->refresh());
        });
    }

    public function cancel(PurchaseInvoice $record, string $reason): PurchaseInvoice
    {
        return DB::transaction(function () use ($record, $reason): PurchaseInvoice {
            /** @var PurchaseInvoice $locked */
            $locked = PurchaseInvoice::query()->lockForUpdate()->findOrFail($record->getKey());

            if ($locked->isClosed()) {
                throw new DomainException(__('purchase_invoices.messages.closed_not_cancellable'));
            }

            if ($locked->isApproved() || $locked->journal_entry_id !== null) {
                throw new DomainException(__('purchase_invoices.messages.approved_cancel_requires_reversal'));
            }

            if ($locked->isCancelled()) {
                throw new DomainException(__('purchase_invoices.messages.already_cancelled'));
            }

            $locked->forceFill([
                'status' => PurchaseInvoice::StatusCancelled,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'updated_by' => auth()->id(),
            ])->save();

            return $this->load($locked->refresh());
        });
    }

    public function delete(PurchaseInvoice $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->assertDeletable($record);
            $this->audit->softDelete($record);

            $record->lines()->get()->each(function (PurchaseInvoiceLine $line): void {
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });

            $record->paymentSchedules()->get()->each(function (PurchaseInvoicePaymentSchedule $schedule): void {
                $this->deleteDraftLinkedVoucher($schedule);
                $schedule->forceFill(['deleted_by' => auth()->id()])->save();
                $schedule->delete();
            });
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $companyId = (int) ($this->operatingContext->snapshot(request())['company_id'] ?? 0);
            $deleted = 0;

            if ($companyId <= 0) {
                throw new DomainException(__('operating_context.messages.required'));
            }

            foreach (PurchaseInvoice::query()->where('company_id', $companyId)->whereIn('doc_num', $docNums)->get() as $record) {
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

    public function restore(PurchaseInvoice $record): PurchaseInvoice
    {
        return DB::transaction(function () use ($record): PurchaseInvoice {
            if (! $record->trashed()) {
                throw new DomainException(__('purchase_invoices.messages.restore_not_allowed'));
            }

            if (PurchaseInvoice::query()
                ->where('company_id', $record->company_id)
                ->where('financial_period_id', $record->financial_period_id)
                ->where('doc_number', $record->doc_number)
                ->whereKeyNot($record->getKey())
                ->whereNull('deleted_at')
                ->exists()) {
                throw new DomainException(__('purchase_invoices.messages.restore_conflict'));
            }

            $this->audit->restore($record, auth()->id());

            return $this->load($record->refresh());
        });
    }

    /**
     * @return list<string>
     */
    public function defaultRelations(): array
    {
        return [
            'financialPeriod',
            'branch',
            'supplier.account',
            'currency',
            'cashbox',
            'bankAccount',
            'journalEntry',
            'lines.product.unit',
            'lines.unit',
            'paymentSchedules.cashbox',
            'paymentSchedules.bankAccount',
            'paymentSchedules.cashVoucher',
        ];
    }

    private function load(PurchaseInvoice $record): PurchaseInvoice
    {
        return $record->load($this->defaultRelations());
    }

    /**
     * @return array{company_id: int, financial_period_id: int, branch_id: int|null}
     */
    private function context(array $data, ?PurchaseInvoice $record = null): array
    {
        $snapshot = $this->operatingContext->snapshot(request());
        $companyId = $snapshot['company_id'] ? (int) $snapshot['company_id'] : (int) ($record?->company_id ?? 0);

        if ($companyId <= 0) {
            throw new DomainException(__('operating_context.messages.required'));
        }

        $financialPeriodId = $this->financialPeriodId($companyId, $data['financial_period_doc_num'] ?? null, $record);

        return [
            'company_id' => $companyId,
            'financial_period_id' => $financialPeriodId,
            'branch_id' => $snapshot['branch_id'] ? (int) $snapshot['branch_id'] : ($record?->branch_id ? (int) $record->branch_id : null),
        ];
    }

    private function financialPeriodId(int $companyId, ?string $docNum, ?PurchaseInvoice $record): int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '' && $record instanceof PurchaseInvoice) {
            return (int) $record->financial_period_id;
        }

        if ($docNum === '') {
            $snapshot = $this->operatingContext->snapshot(request());
            if ($snapshot['financial_period_id']) {
                return (int) $snapshot['financial_period_id'];
            }
        }

        $period = FinancialPeriod::query()
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->whereNull('deleted_at')
            ->first();

        if (! $period instanceof FinancialPeriod) {
            throw new DomainException(__('purchase_invoices.messages.financial_period_required'));
        }

        return (int) $period->getKey();
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int|null}  $context
     * @return array<string, mixed>
     */
    private function values(array $data, array $context): array
    {
        $supplier = $this->supplier($context['company_id'], $data['supplier_doc_num'] ?? null);
        $currency = $this->currency($context['company_id'], $data['currency_doc_num'] ?? null);
        $cashbox = $this->cashbox($context['company_id'], $data['cashbox_doc_num'] ?? null);
        $bankAccount = $this->bankAccount($context['company_id'], $data['bank_account_doc_num'] ?? null);

        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'supplier_id' => $supplier?->getKey(),
            'invoice_date' => $data['invoice_date'],
            'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
            'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
            'currency_id' => $currency?->getKey(),
            'exchange_rate' => number_format((float) ($data['exchange_rate'] ?? 1), 6, '.', ''),
            'payment_type' => $data['payment_type'] ?? PurchaseInvoice::PaymentTypeCredit,
            'payment_source_type' => $data['payment_source_type'] ?? null,
            'cashbox_id' => $cashbox?->getKey(),
            'bank_account_id' => $bankAccount?->getKey(),
            'notes' => $data['notes'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
        ];
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int|null}  $context
     * @return array{doc_number: int, doc_num: string}
     */
    private function document(array $data, array $context): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->formatScopedDocNum((int) $data['doc_number'], $context['financial_period_id'])]
            : $this->nextScopedDocument($context['company_id'], $context['financial_period_id']);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{company_id: int, financial_period_id: int, branch_id: int|null}  $context
     */
    private function syncLines(PurchaseInvoice $record, array $lines, array $context): void
    {
        $existing = $record->lines()->get()->keyBy('public_id');
        $kept = [];

        foreach (array_values($lines) as $index => $line) {
            $product = $this->product($context['company_id'], $line['product_doc_num'] ?? null);

            if (! $product instanceof Product) {
                continue;
            }

            $unit = $this->unitOptions->unitForProduct($product, $line['unit_doc_num'] ?? null, $context['company_id'])
                ?: $product->unit;
            $publicId = trim((string) ($line['public_id'] ?? ''));
            $existingLine = $publicId !== '' ? $existing->get($publicId) : null;
            $values = [
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'line_number' => $index + 1,
                'product_id' => $product->getKey(),
                'unit_id' => $unit?->getKey(),
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $line['tax_amount'],
                'subtotal_amount' => $line['subtotal_amount'],
                'total_before_tax' => $line['total_before_tax'],
                'total_after_tax' => $line['total_after_tax'],
                'product_snapshot' => $this->productSnapshot($product, $unit),
                'notes' => $line['notes'] ?? null,
            ];

            if ($existingLine instanceof PurchaseInvoiceLine) {
                $existingLine->forceFill([...$values, 'updated_by' => auth()->id()])->save();
                $kept[] = $existingLine->getKey();

                continue;
            }

            $created = $record->lines()->create([...$values, 'created_by' => auth()->id()]);
            $kept[] = $created->getKey();
        }

        $record->lines()
            ->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))
            ->get()
            ->each(function (PurchaseInvoiceLine $line): void {
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });
    }

    /**
     * @param  list<array<string, mixed>>  $schedules
     * @param  array{company_id: int, financial_period_id: int, branch_id: int|null}  $context
     */
    private function syncPaymentSchedules(PurchaseInvoice $record, array $schedules, array $context): void
    {
        $existing = $record->paymentSchedules()->with('cashVoucher')->get()->keyBy('public_id');
        $kept = [];

        foreach (array_values($schedules) as $index => $row) {
            $publicId = trim((string) ($row['public_id'] ?? ''));
            $existingSchedule = $publicId !== '' ? $existing->get($publicId) : null;
            $cashbox = $this->cashbox($context['company_id'], $row['cashbox_doc_num'] ?? null);
            $bankAccount = $this->bankAccount($context['company_id'], $row['bank_account_doc_num'] ?? null);
            $sourceType = $row['payment_source_type'] ?? PurchaseInvoice::SourceScheduled;
            $values = [
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'line_number' => $index + 1,
                'due_date' => $row['due_date'],
                'amount' => number_format((float) ($row['amount'] ?? 0), 4, '.', ''),
                'payment_source_type' => $sourceType,
                'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $bankAccount?->getKey(),
                'payment_date' => $row['payment_date'] ?? null,
                'status' => PurchaseInvoicePaymentSchedule::StatusScheduled,
                'notes' => $row['notes'] ?? null,
            ];

            if ($existingSchedule instanceof PurchaseInvoicePaymentSchedule) {
                $this->assertLinkedVoucherCanChange($existingSchedule, $values);
                $existingSchedule->forceFill([...$values, 'updated_by' => auth()->id()])->save();
                $schedule = $existingSchedule->refresh();
            } else {
                $schedule = $record->paymentSchedules()->create([...$values, 'created_by' => auth()->id()]);
            }

            $this->syncLinkedVoucher($record, $schedule, $cashbox);
            $kept[] = $schedule->getKey();
        }

        $record->paymentSchedules()
            ->with('cashVoucher')
            ->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))
            ->get()
            ->each(function (PurchaseInvoicePaymentSchedule $schedule): void {
                $this->assertLinkedVoucherCanChange($schedule, []);
                $this->deleteDraftLinkedVoucher($schedule);
                $schedule->forceFill(['deleted_by' => auth()->id()])->save();
                $schedule->delete();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{invoice: array<string, string|null>, lines: list<array<string, mixed>>}  $calculation
     * @return list<array<string, mixed>>
     */
    private function schedulesForSync(array $data, array $calculation): array
    {
        $schedules = $data['payment_schedules'] ?? [];

        if (($data['payment_type'] ?? null) !== PurchaseInvoice::PaymentTypeCash || $schedules !== []) {
            return $schedules;
        }

        return [[
            'public_id' => null,
            'due_date' => $data['invoice_date'],
            'amount' => $calculation['invoice']['total_amount'],
            'payment_source_type' => PurchaseInvoice::SourceCashbox,
            'cashbox_doc_num' => $data['cashbox_doc_num'] ?? null,
            'bank_account_doc_num' => null,
            'payment_date' => $data['invoice_date'],
            'notes' => $data['notes'] ?? null,
        ]];
    }

    private function syncLinkedVoucher(PurchaseInvoice $record, PurchaseInvoicePaymentSchedule $schedule, ?Cashbox $cashbox): void
    {
        $schedule->loadMissing('cashVoucher');

        if ($schedule->payment_source_type !== PurchaseInvoice::SourceCashbox || ! $cashbox instanceof Cashbox || ! $schedule->payment_date) {
            $this->deleteDraftLinkedVoucher($schedule);
            $schedule->forceFill([
                'cash_voucher_id' => $schedule->cashVoucher?->isApproved() ? $schedule->cash_voucher_id : null,
                'status' => PurchaseInvoicePaymentSchedule::StatusScheduled,
            ])->save();

            return;
        }

        $supplier = $record->supplier()->with('account')->first();
        $currency = $record->currency()->first();

        if (! $supplier instanceof Supplier || ! $supplier->account instanceof Account || ! $currency instanceof Currency) {
            throw new DomainException(__('purchase_invoices.messages.payment_voucher_account_missing'));
        }

        $payload = [
            'voucher_date' => $schedule->payment_date,
            'cashbox_doc_num' => $cashbox->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => $record->exchange_rate,
            'amount' => $schedule->amount,
            'person_name' => $supplier->name,
            'person_national_id' => null,
            'person_phone' => $supplier->mobile ?: $supplier->phone,
            'reason' => __('purchase_invoices.payment_voucher.reason', ['invoice' => $record->doc_num]),
            'description' => __('purchase_invoices.payment_voucher.description', ['invoice' => $record->doc_num]),
            'lines' => [[
                'account_doc_num' => $supplier->account->doc_num,
                'amount' => $schedule->amount,
                'description' => __('purchase_invoices.payment_voucher.line_description', ['invoice' => $record->doc_num]),
                'notes' => $schedule->notes,
            ]],
        ];
        $voucher = $schedule->cashVoucher;

        if ($voucher instanceof CashVoucher && $voucher->isDraft()) {
            $voucher = $this->cashVouchers->update(CashVoucher::TypePayment, $voucher, $payload)['record'];
        } elseif (! $voucher instanceof CashVoucher) {
            $voucher = $this->cashVouchers->create(CashVoucher::TypePayment, $payload)['record'];
        }

        $schedule->forceFill([
            'cash_voucher_id' => $voucher->getKey(),
            'status' => $voucher->isApproved()
                ? PurchaseInvoicePaymentSchedule::StatusPaid
                : PurchaseInvoicePaymentSchedule::StatusVoucherDraft,
        ])->save();
    }

    private function deleteDraftLinkedVoucher(PurchaseInvoicePaymentSchedule $schedule): void
    {
        $schedule->loadMissing('cashVoucher');
        $voucher = $schedule->cashVoucher;

        if ($voucher instanceof CashVoucher && $voucher->isDraft()) {
            $this->cashVouchers->delete(CashVoucher::TypePayment, $voucher);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assertLinkedVoucherCanChange(PurchaseInvoicePaymentSchedule $schedule, array $values): void
    {
        $schedule->loadMissing('cashVoucher');
        $voucher = $schedule->cashVoucher;

        if (! $voucher instanceof CashVoucher || $voucher->isDraft()) {
            return;
        }

        $changing = $values === []
            || (string) ($values['amount'] ?? $schedule->amount) !== (string) $schedule->amount
            || (string) ($values['payment_source_type'] ?? $schedule->payment_source_type) !== (string) $schedule->payment_source_type
            || (string) ($values['cashbox_id'] ?? $schedule->cashbox_id) !== (string) $schedule->cashbox_id
            || (string) ($values['payment_date'] ?? $schedule->payment_date?->toDateString()) !== (string) ($schedule->payment_date?->toDateString());

        if ($changing) {
            throw new DomainException(__('purchase_invoices.messages.linked_voucher_locked'));
        }
    }

    /**
     * @return list<array{account_id: int, debit_amount: string, description: string}>
     */
    private function postingLines(PurchaseInvoice $record): array
    {
        $record->loadMissing('lines.product');
        $lineBaseTotal = $record->lines->sum(fn (PurchaseInvoiceLine $line): float => (float) $line->total_before_tax);
        $headerDiscount = (float) $record->header_discount_amount;
        $debits = [];
        $allocatedHeaderDiscount = 0.0;
        $lastIndex = max(0, $record->lines->count() - 1);

        foreach ($record->lines->values() as $index => $line) {
            $account = $this->purchaseDebitAccount($line);
            $lineBase = (float) $line->total_before_tax;
            $share = $lineBaseTotal > 0 ? $headerDiscount * ($lineBase / $lineBaseTotal) : 0.0;

            if ($index === $lastIndex) {
                $share = $headerDiscount - $allocatedHeaderDiscount;
            }

            $allocatedHeaderDiscount += $share;
            $amount = max(0, $lineBase - $share);

            if ($amount <= 0) {
                continue;
            }

            $key = (string) $account->getKey();
            $debits[$key] ??= [
                'account_id' => (int) $account->getKey(),
                'debit_amount' => 0.0,
                'description' => __('purchase_invoices.journal.inventory_line'),
            ];
            $debits[$key]['debit_amount'] += $amount;
        }

        if ((float) $record->tax_amount > 0) {
            $taxAccount = $this->accountByCode((int) $record->company_id, '2131', 'input_vat_account_missing');
            $debits['tax'] = [
                'account_id' => (int) $taxAccount->getKey(),
                'debit_amount' => (float) $record->tax_amount,
                'description' => __('purchase_invoices.journal.input_vat'),
            ];
        }

        return collect($debits)
            ->map(fn (array $line): array => [
                'account_id' => $line['account_id'],
                'debit_amount' => number_format((float) $line['debit_amount'], 4, '.', ''),
                'description' => $line['description'],
            ])
            ->values()
            ->all();
    }

    private function purchaseDebitAccount(PurchaseInvoiceLine $line): Account
    {
        $classification = $line->product?->item_classification;
        $code = match ($classification) {
            Product::ClassificationRawMaterial => '1131',
            Product::ClassificationSemiFinished => '1132',
            Product::ClassificationFinishedProduct => '1133',
            Product::ClassificationPackaging, Product::ClassificationOther => '1134',
            Product::ClassificationService => '512',
            default => '1134',
        };

        return $this->accountByCode((int) $line->company_id, $code, 'purchase_debit_account_missing', ['code' => $code]);
    }

    private function supplierPayableAccount(PurchaseInvoice $record): Account
    {
        $record->loadMissing('supplier.account');
        $account = $record->supplier?->account;

        if (! $account instanceof Account || $account->trashed() || $account->status !== 'active' || $account->is_group || ! $account->is_postable) {
            throw new DomainException(__('purchase_invoices.messages.supplier_account_missing'));
        }

        return $account;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function accountByCode(int $companyId, string $code, string $messageKey, array $replace = []): Account
    {
        $account = Account::query()
            ->forCompany($companyId)
            ->where('account_code', $code)
            ->where('status', 'active')
            ->where('is_group', false)
            ->where('is_postable', true)
            ->first();

        if (! $account instanceof Account) {
            throw new DomainException(__('purchase_invoices.messages.'.$messageKey, ['code' => $code, ...$replace]));
        }

        return $account;
    }

    private function assertEditable(PurchaseInvoice $record): void
    {
        if ($record->trashed() || $record->isLockedForEditing()) {
            throw new DomainException(__('purchase_invoices.messages.document_locked'));
        }
    }

    private function assertDeletable(PurchaseInvoice $record): void
    {
        if (! $record->isDeletable()) {
            throw new DomainException(__('purchase_invoices.messages.document_delete_blocked'));
        }
    }

    private function assertApprovable(PurchaseInvoice $record): void
    {
        if ($record->trashed()) {
            throw new DomainException(__('purchase_invoices.messages.deleted_not_approvable'));
        }

        if (! $record->isDraft()) {
            throw new DomainException(__('purchase_invoices.messages.document_not_approvable'));
        }

        if ($record->journal_entry_id !== null) {
            throw new DomainException(__('purchase_invoices.messages.already_has_journal_entry'));
        }

        if ($record->lines->isEmpty()) {
            throw new DomainException(__('purchase_invoices.messages.lines_required'));
        }

        if ($record->financialPeriod?->is_closed) {
            throw new DomainException(__('purchase_invoices.messages.period_closed'));
        }

        $invoiceDate = $record->invoice_date?->toDateString();
        if ($invoiceDate && ($record->financialPeriod?->from_date?->toDateString() > $invoiceDate || $record->financialPeriod?->to_date?->toDateString() < $invoiceDate)) {
            throw new DomainException(__('purchase_invoices.messages.invoice_date_outside_period'));
        }
    }

    private function supplier(int $companyId, ?string $docNum): ?Supplier
    {
        $docNum = trim((string) $docNum);

        return $docNum === ''
            ? null
            : Supplier::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->first();
    }

    private function currency(int $companyId, ?string $docNum): ?Currency
    {
        $docNum = trim((string) $docNum);

        return $docNum === ''
            ? null
            : Currency::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->first();
    }

    private function cashbox(int $companyId, ?string $docNum): ?Cashbox
    {
        $docNum = trim((string) $docNum);

        return $docNum === ''
            ? null
            : Cashbox::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->first();
    }

    private function bankAccount(int $companyId, ?string $docNum): ?BankAccount
    {
        $docNum = trim((string) $docNum);

        return $docNum === ''
            ? null
            : BankAccount::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->first();
    }

    private function product(int $companyId, ?string $docNum): ?Product
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return Product::query()
            ->with(['unit', 'equivalentUnit'])
            ->active()
            ->nonService()
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->first();
    }

    /**
     * @return array<string, string|null>
     */
    private function productSnapshot(Product $product, ?ItemUnit $unit): array
    {
        return [
            'doc_num' => (string) $product->doc_num,
            'name' => (string) $product->name,
            'barcode' => $product->barcode,
            'item_classification' => (string) $product->item_classification,
            'unit_doc_num' => $unit?->doc_num,
            'unit_name' => $unit?->name,
        ];
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function nextScopedDocument(int $companyId, int $financialPeriodId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('purchase_invoices')));
        }

        $nextNumber = ((int) PurchaseInvoice::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->formatScopedDocNum($nextNumber, $financialPeriodId),
        ];
    }

    private function formatScopedDocNum(int $documentNumber, int $financialPeriodId): string
    {
        $periodNumber = (int) FinancialPeriod::query()->whereKey($financialPeriodId)->value('doc_number');
        $config = config('document_numbers.purchase_invoices', []);
        $prefix = (string) ($config['prefix'] ?? 'PINV-');
        $padding = max(0, (int) ($config['padding'] ?? 5));

        return $prefix
            .str_pad((string) $periodNumber, $padding, '0', STR_PAD_LEFT)
            .'-'
            .str_pad((string) $documentNumber, $padding, '0', STR_PAD_LEFT);
    }
}
