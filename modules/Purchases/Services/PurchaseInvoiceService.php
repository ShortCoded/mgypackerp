<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Inventory\Services\InventoryAccountingMappingService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Modules\Purchases\Models\PurchaseInvoicePaymentSchedule;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentAllocation;
use Modules\Purchases\Models\SupplierPaymentContext;

class PurchaseInvoiceService
{
    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly DocumentNumberService $documents,
        private readonly OperatingContextService $operatingContext,
        private readonly PurchaseInvoiceCalculationService $calculator,
        private readonly ProductComponentUnitOptionsService $unitOptions,
        private readonly CashVoucherService $cashVouchers,
        private readonly JournalEntryService $journalEntries,
        private readonly PurchaseInvoiceMatchingService $matching,
        private readonly NumericFormatService $numbers,
        private readonly InventoryAccountingMappingService $inventoryMappings,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $context = $this->context($data);
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $data['invoice_date'], $context['financial_period_id'], lockForUpdate: true);
            $calculation = $this->calculator->calculate(
                $data['lines'] ?? [],
                $data['header_discount_type'] ?? null,
                $data['header_discount_value'] ?? 0,
                $data['freight_amount'] ?? 0,
                $data['freight_tax_rate'] ?? 0,
            );
            $record = PurchaseInvoice::query()->create([
                ...$this->values($data, $context),
                ...$calculation['invoice'],
                ...$this->document($data, $context),
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $calculation['lines'], $context);
            app(ProcurementAttachmentService::class)->attach($record, $data['attachment_file_doc_nums'] ?? [], ProcurementAttachmentService::OperationalCollection, $context['company_id']);
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
            $this->assertOperatingContext($record);
            $this->assertEditable($record);

            $context = $this->context($data, $record);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $calculation = $this->calculator->calculate(
                $data['lines'] ?? [],
                $data['header_discount_type'] ?? null,
                $data['header_discount_value'] ?? 0,
                $data['freight_amount'] ?? 0,
                $data['freight_tax_rate'] ?? 0,
            );
            $values = [
                ...$this->values($data, $context, $record),
                ...$calculation['invoice'],
            ];

            if (array_key_exists('doc_number', $data) && $data['doc_number']) {
                $values = [...$values, ...$this->document($data, $context)];
            }

            $record->forceFill($values);
            $headerChanged = $record->isDirty();
            if ($headerChanged) {
                $this->audit->saveUpdate($record);
            }
            $record = $record->refresh();
            $linesChanged = $this->syncLines($record, $calculation['lines'], $context);
            $attachmentsChanged = app(ProcurementAttachmentService::class)->attach($record, $data['attachment_file_doc_nums'] ?? [], ProcurementAttachmentService::OperationalCollection, $context['company_id']);
            $schedulesChanged = $this->syncPaymentSchedules($record->refresh(), $this->schedulesForSync($data, $calculation, $record), $context);
            $changed = $headerChanged || $linesChanged || $schedulesChanged || $attachmentsChanged;
            if (! $headerChanged && $changed) {
                $this->audit->touchUpdateAudit($record);
            }
            $record->refreshPaymentTotals();

            return [
                'record' => $this->load($record->refresh()),
                'changed' => $changed,
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

            $this->assertOperatingContext($locked);
            if ($locked->isApproved()) {
                return $this->load($locked);
            }
            $this->assertApprovable($locked);
            $this->matching->matchForPosting($locked);
            $journalEntry = $this->journalEntries->createPostedFromSource(
                $this->postingHeader($locked),
                $this->postingLines($locked),
            );
            $this->applyInventoryValuation($locked);

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
            $this->assertOperatingContext($locked);
            if ($locked->status === PurchaseInvoice::StatusClosed) {
                return $this->load($locked);
            }

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

            $this->assertOperatingContext($locked);
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

    public function reverse(PurchaseInvoice $record, string $reason): PurchaseInvoice
    {
        return DB::transaction(function () use ($record, $reason): PurchaseInvoice {
            $context = $this->operatingContext->snapshot(request());
            $locked = PurchaseInvoice::query()
                ->with(['journalEntry.lines', 'paymentAllocations.paymentContext', 'paymentSchedules.cashVoucher', 'purchaseReturns'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());

            $this->assertOperatingContext($locked);
            if ($locked->reversal_journal_entry_id !== null) {
                return $this->load($locked);
            }
            if (! in_array($locked->status, [PurchaseInvoice::StatusApproved, PurchaseInvoice::StatusClosed], true)
                || $locked->reversal_journal_entry_id !== null
                || ! $locked->journalEntry) {
                throw new DomainException(__('Only an unreversed posted purchase invoice can be reversed.'));
            }

            if ($locked->paymentAllocations->contains(fn ($allocation): bool => $allocation->paymentContext?->isApproved())
                || $locked->paymentSchedules->contains(fn ($schedule): bool => (bool) $schedule->cashVoucher?->isApproved())) {
                throw new DomainException(__('Reverse or cancel approved Supplier payments before reversing this invoice.'));
            }

            if ($locked->purchaseReturns->contains(fn (PurchaseReturn $return): bool => $return->status === PurchaseReturn::StatusPosted)) {
                throw new DomainException(__('Reverse posted Purchase Returns before reversing this invoice.'));
            }

            if ((int) ($context['company_id'] ?? 0) !== (int) $locked->company_id
                || empty($context['financial_period_id'])) {
                throw new DomainException(__('The active accounting context is required for invoice reversal.'));
            }

            $reversal = $this->journalEntries->createPostedReversalFromSource($locked->journalEntry, [
                'entry_date' => now()->toDateString(),
                'company_id' => (int) $locked->company_id,
                'financial_period_id' => (int) $context['financial_period_id'],
                'branch_id' => $locked->branch_id,
                'currency_id' => $locked->currency_id,
                'exchange_rate' => $locked->exchange_rate,
                'description' => __('Purchase invoice reversal :document', ['document' => $locked->doc_num]),
                'notes' => $reason,
                'source_type' => 'purchase_invoice_reversal',
                'source_id' => $locked->getKey(),
                'source_doc_num' => $locked->doc_num,
            ]);
            $this->applyInventoryValuation($locked, reverse: true);

            $locked->forceFill([
                'status' => PurchaseInvoice::StatusCancelled,
                'reversal_journal_entry_id' => $reversal->getKey(),
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'reversal_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'updated_by' => auth()->id(),
            ])->save();
            $locked->refreshPaymentTotals();

            return $this->load($locked->refresh());
        }, 3);
    }

    public function delete(PurchaseInvoice $record): void
    {
        DB::transaction(function () use ($record): void {
            $record = PurchaseInvoice::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertOperatingContext($record);
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
            $record = PurchaseInvoice::withTrashed()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertOperatingContext($record);
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
            'purchaseOrder',
            'lines.product.unit',
            'lines.unit',
            'lines.costCenter',
            'lines.purchaseOrderLine.purchaseOrder',
            'lines.receiptLine.receipt',
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
    private function values(array $data, array $context, ?PurchaseInvoice $record = null): array
    {
        $supplier = $this->supplier($context['company_id'], $data['supplier_doc_num'] ?? null);
        $currency = $this->currency($context['company_id'], $data['currency_doc_num'] ?? null);
        $cashbox = $this->cashbox($context['company_id'], $data['cashbox_doc_num'] ?? null);
        $bankAccount = $this->bankAccount($context['company_id'], $data['bank_account_doc_num'] ?? null);
        $purchaseOrder = $this->purchaseOrder($context['company_id'], $data['purchase_order_doc_num'] ?? null);
        if ($purchaseOrder && ((int) $purchaseOrder->branch_id !== (int) $context['branch_id']
            || (int) $purchaseOrder->supplier_id !== (int) $supplier?->getKey())) {
            throw new DomainException(__('Purchase order, supplier, and invoice context do not match.'));
        }
        $supplierNumber = trim((string) ($data['supplier_invoice_number'] ?? ''));
        if ($supplier && $supplierNumber !== '') {
            Supplier::query()->lockForUpdate()->findOrFail($supplier->getKey());
            $duplicate = PurchaseInvoice::query()->where('company_id', $context['company_id'])
                ->where('supplier_id', $supplier->getKey())->whereRaw('LOWER(TRIM(supplier_invoice_number)) = ?', [mb_strtolower($supplierNumber)])
                ->whereNotIn('status', [PurchaseInvoice::StatusCancelled])->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))->exists();
            if ($duplicate) {
                throw new DomainException(__('This supplier invoice reference is already recorded for this supplier and company.'));
            }
        }

        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'supplier_id' => $supplier?->getKey(),
            'purchase_order_id' => $purchaseOrder?->getKey(),
            'purchase_type' => $data['purchase_type'] ?? 'standard',
            'matching_status' => 'not_matched',
            'matching_notes' => null,
            'direct_procurement_override' => (bool) ($data['direct_procurement_override'] ?? false),
            'direct_procurement_reason' => $data['direct_procurement_reason'] ?? null,
            'invoice_date' => $data['invoice_date'],
            'supplier_invoice_number' => $supplierNumber ?: null,
            'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
            'currency_id' => $currency?->getKey(),
            'exchange_rate' => $this->numbers->normalizeToScale($data['exchange_rate'] ?? 1, 6) ?? '1.000000',
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
    private function syncLines(PurchaseInvoice $record, array $lines, array $context): bool
    {
        $changed = false;
        $existing = $record->lines()->get()->keyBy('public_id');
        $kept = [];

        foreach (array_values($lines) as $index => $line) {
            $product = $this->product($context['company_id'], $line['product_doc_num'] ?? null);
            $publicId = trim((string) ($line['public_id'] ?? ''));
            $existingLine = $publicId !== '' ? $existing->get($publicId) : null;

            if (! $product instanceof Product) {
                continue;
            }

            $purchaseOrderLine = $this->purchaseOrderLine($record, $line['purchase_order_line_public_id'] ?? null);
            $receiptLine = $this->receiptLine($purchaseOrderLine, $line['receipt_line_public_id'] ?? null);
            $isSourceLinked = $purchaseOrderLine instanceof PurchaseOrderLine
                && (int) $purchaseOrderLine->product_id === (int) $product->getKey()
                && (! filled($line['receipt_line_public_id'] ?? null)
                    || ($receiptLine instanceof UnpricedInventoryReceiptLine && (int) $receiptLine->product_id === (int) $product->getKey()));

            if (! $product->isPurchasable()
                && ! $isSourceLinked
                && (! $existingLine instanceof PurchaseInvoiceLine || (int) $existingLine->product_id !== (int) $product->getKey())) {
                throw new DomainException(__('purchase_invoices.messages.purchase_product_type_invalid'));
            }

            $unit = $this->unitOptions->unitForProduct($product, $line['unit_doc_num'] ?? null, $context['company_id'])
                ?: $product->unit;
            $values = [
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'line_number' => $index + 1,
                'product_id' => $product->getKey(),
                'unit_id' => $unit?->getKey(),
                'purchase_order_line_id' => $purchaseOrderLine?->getKey(),
                'receipt_line_id' => $receiptLine?->getKey(),
                'cost_center_id' => ! array_key_exists('cost_center_doc_num', $line) && $existingLine ? $existingLine->cost_center_id : $this->lineCostCenterId($line, $purchaseOrderLine, $context['company_id']),
                'matched_quantity' => 0,
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
                $existingLine->forceFill($values);
                if ($existingLine->isDirty()) {
                    $changed = true;
                    $existingLine->forceFill(['updated_by' => auth()->id()])->save();
                }
                $kept[] = $existingLine->getKey();

                continue;
            }

            $changed = true;
            $created = $record->lines()->create([...$values, 'created_by' => auth()->id()]);
            $kept[] = $created->getKey();
        }

        $record->lines()
            ->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))
            ->get()
            ->each(function (PurchaseInvoiceLine $line) use (&$changed): void {
                $changed = true;
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });

        return $changed;
    }

    /**
     * @param  list<array<string, mixed>>  $schedules
     * @param  array{company_id: int, financial_period_id: int, branch_id: int|null}  $context
     */
    private function syncPaymentSchedules(PurchaseInvoice $record, array $schedules, array $context): bool
    {
        $changed = false;
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
                'amount' => $this->numbers->normalizeToScale($row['amount'] ?? 0, 4) ?? '0.0000',
                'payment_source_type' => $sourceType,
                'cashbox_id' => $cashbox?->getKey(),
                'bank_account_id' => $bankAccount?->getKey(),
                'payment_date' => $row['payment_date'] ?? null,
                'status' => $existingSchedule?->status ?? PurchaseInvoicePaymentSchedule::StatusScheduled,
                'notes' => $row['notes'] ?? null,
            ];

            if ($existingSchedule instanceof PurchaseInvoicePaymentSchedule) {
                $this->assertLinkedVoucherCanChange($existingSchedule, $values);
                $existingSchedule->forceFill($values);
                if ($existingSchedule->isDirty()) {
                    $changed = true;
                    $existingSchedule->forceFill(['updated_by' => auth()->id()])->save();
                }
                $schedule = $existingSchedule->refresh();
            } else {
                $changed = true;
                $schedule = $record->paymentSchedules()->create([...$values, 'created_by' => auth()->id()]);
            }

            $this->syncLinkedVoucher($record, $schedule, $cashbox);
            $kept[] = $schedule->getKey();
        }

        $record->paymentSchedules()
            ->with('cashVoucher')
            ->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))
            ->get()
            ->each(function (PurchaseInvoicePaymentSchedule $schedule) use (&$changed): void {
                $changed = true;
                $this->assertLinkedVoucherCanChange($schedule, []);
                $this->deleteDraftLinkedVoucher($schedule);
                $schedule->forceFill(['deleted_by' => auth()->id()])->save();
                $schedule->delete();
            });

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{invoice: array<string, string|null>, lines: list<array<string, mixed>>}  $calculation
     * @return list<array<string, mixed>>
     */
    private function schedulesForSync(array $data, array $calculation, ?PurchaseInvoice $record = null): array
    {
        $schedules = $data['payment_schedules'] ?? [];

        if ($schedules !== []) {
            return $schedules;
        }

        $existingSchedules = $record?->paymentSchedules()->get();
        $publicId = $existingSchedules?->count() === 1 ? $existingSchedules->first()->public_id : null;

        if (($data['payment_type'] ?? null) === PurchaseInvoice::PaymentTypeCash) {
            return [[
                'public_id' => $publicId,
                'due_date' => $data['invoice_date'],
                'amount' => $calculation['invoice']['total_amount'],
                'payment_source_type' => PurchaseInvoice::SourceCashbox,
                'cashbox_doc_num' => $data['cashbox_doc_num'] ?? null,
                'bank_account_doc_num' => null,
                'payment_date' => $data['invoice_date'],
                'notes' => $data['notes'] ?? null,
            ]];
        }

        $companyId = (int) ($this->operatingContext->snapshot(request())['company_id'] ?? 0);
        $paymentTermsDays = Supplier::query()->forCompany($companyId)
            ->where('doc_num', $data['supplier_doc_num'] ?? null)
            ->value('payment_terms_days');
        if ($paymentTermsDays === null) {
            return [];
        }

        return [[
            'public_id' => $publicId,
            'due_date' => Carbon::parse($data['invoice_date'])->addDays((int) $paymentTermsDays)->toDateString(),
            'amount' => $calculation['invoice']['total_amount'],
            'payment_source_type' => PurchaseInvoice::SourceScheduled,
            'cashbox_doc_num' => null,
            'bank_account_doc_num' => null,
            'payment_date' => null,
            'notes' => __('Inherited from Supplier payment terms.'),
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
            ]);
            if ($schedule->isDirty()) {
                $schedule->save();
            }

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
        ]);
        if ($schedule->isDirty()) {
            $schedule->save();
        }

        $paymentContext = SupplierPaymentContext::query()->firstOrNew([
            'cash_voucher_id' => $voucher->getKey(),
        ]);
        if (! $paymentContext->exists) {
            $paymentContext->forceFill($this->nextSupplierPaymentDocument($record));
        }
        $paymentContext->forceFill([
            'company_id' => $record->company_id,
            'financial_period_id' => $record->financial_period_id,
            'branch_id' => $record->branch_id,
            'supplier_id' => $record->supplier_id,
            'purchase_order_id' => $record->purchase_order_id,
            'payment_method' => SupplierPaymentContext::MethodCash,
            'payment_date' => $voucher->voucher_date,
            'amount' => $voucher->amount,
            'currency_id' => $voucher->currency_id,
            'exchange_rate' => $voucher->exchange_rate,
            'status' => $voucher->isApproved() ? SupplierPaymentContext::StatusApproved : SupplierPaymentContext::StatusDraft,
            'is_advance' => false,
            'allocated_amount' => $schedule->amount,
            'reason' => $voucher->reason,
            'notes' => $voucher->description,
        ]);
        if ($paymentContext->isDirty() || ! $paymentContext->exists) {
            $paymentContext->save();
        }
        $allocation = SupplierPaymentAllocation::query()->firstOrNew([
            'supplier_payment_context_id' => $paymentContext->getKey(),
            'purchase_invoice_id' => $record->getKey(),
            'payment_schedule_id' => $schedule->getKey(),
        ]);
        $allocation->amount = $schedule->amount;
        if ($allocation->isDirty() || ! $allocation->exists) {
            $allocation->forceFill(['allocated_by' => auth()->id(), 'allocated_at' => now()])->save();
        }
    }

    private function deleteDraftLinkedVoucher(PurchaseInvoicePaymentSchedule $schedule): void
    {
        $schedule->loadMissing('cashVoucher');
        $voucher = $schedule->cashVoucher;

        if ($voucher instanceof CashVoucher && $voucher->isDraft()) {
            $this->cashVouchers->delete(CashVoucher::TypePayment, $voucher);
        }
    }

    /** @return array{doc_number: int, doc_num: string} */
    private function nextSupplierPaymentDocument(PurchaseInvoice $record): array
    {
        return $this->documents->nextForCompany(
            'supplier_payments',
            SupplierPaymentContext::class,
            (int) $record->company_id,
        );
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

    /** @return array<string, mixed> */
    private function postingHeader(PurchaseInvoice $record): array
    {
        return [
            'entry_date' => $record->invoice_date,
            'company_id' => (int) $record->company_id,
            'financial_period_id' => (int) $record->financial_period_id,
            'branch_id' => $record->branch_id,
            'currency_id' => $record->currency_id,
            'exchange_rate' => $record->exchange_rate,
            'description' => __('purchase_invoices.journal.description', ['invoice' => $record->doc_num]),
            'notes' => $record->notes,
            'source_type' => 'purchase_invoice',
            'source_id' => $record->getKey(),
            'source_doc_num' => $record->doc_num,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function postingLines(PurchaseInvoice $record): array
    {
        $record->loadMissing('lines.product');
        $posting = [];
        $netAmounts = $this->netAmountsByLine($record);
        $mapping = null;

        foreach ($record->lines as $line) {
            $finalAmount = (string) ($netAmounts[$line->getKey()] ?? '0.0000');
            if (bccomp($finalAmount, '0', 4) <= 0) {
                continue;
            }

            if ($line->receipt_line_id !== null && ! $line->product?->isService()) {
                $mapping ??= $this->inventoryMappings->requireForCompany((int) $record->company_id);
                $receiptLine = UnpricedInventoryReceiptLine::query()->lockForUpdate()->findOrFail($line->receipt_line_id);
                $provisionalBase = bcmul((string) $receiptLine->provisional_unit_value, (string) $line->quantity, 4);
                $provisionalAmount = bcdiv($provisionalBase, (string) $record->exchange_rate, 4);
                $grniAccount = $this->inventoryMappings->requirePostableAccount($mapping, 'grniAccount', __('Purchase Invoice'));
                $this->addPostingAmount($posting, $grniAccount, $provisionalAmount, true, __('GRNI clearing'));

                $variance = bcsub($finalAmount, $provisionalAmount, 4);
                if (bccomp($variance, '0', 4) !== 0) {
                    $varianceAccount = $this->inventoryMappings->requirePostableAccount($mapping, 'purchasePriceVarianceAccount', __('Purchase Invoice'));
                    $this->addPostingAmount($posting, $varianceAccount, ltrim($variance, '-'), bccomp($variance, '0', 4) > 0, __('Purchase price variance'));
                }
            } else {
                $this->addPostingAmount(
                    $posting,
                    $this->purchaseDebitAccount($line),
                    $finalAmount,
                    true,
                    __('purchase_invoices.journal.inventory_line'),
                    $line->cost_center_id,
                );
            }
        }

        if ((float) $record->freight_amount > 0) {
            $freightCode = (string) config('purchases.accounts.freight_expense', '526');
            $freightAccount = $this->accountByCode((int) $record->company_id, $freightCode, 'purchase_debit_account_missing', ['code' => $freightCode]);
            $this->addPostingAmount($posting, $freightAccount, (string) $record->freight_amount, true, __('Freight expense'));
        }

        if ((float) $record->tax_amount > 0) {
            $taxCode = (string) config('purchases.accounts.recoverable_input_vat', '2131');
            $taxAccount = $this->accountByCode((int) $record->company_id, $taxCode, 'input_vat_account_missing');
            $this->addPostingAmount($posting, $taxAccount, (string) $record->tax_amount, true, __('purchase_invoices.journal.input_vat'));
        }

        $lines = collect($posting)
            ->map(fn (array $line): array => [
                'account_id' => $line['account_id'],
                'debit_amount' => $line['debit_amount'],
                'credit_amount' => $line['credit_amount'],
                'description' => $line['description'],
                'supplier_id' => $record->supplier_id,
                'branch_id' => $record->branch_id,
                'cost_center_id' => $line['cost_center_id'] ?? null,
            ])
            ->values()
            ->all();

        $supplierAccount = $this->supplierPayableAccount($record);
        $lines[] = [
            'account_id' => (int) $supplierAccount->getKey(),
            'debit_amount' => '0.0000',
            'credit_amount' => $record->total_amount,
            'description' => __('purchase_invoices.journal.supplier_payable'),
            'supplier_id' => $record->supplier_id,
            'branch_id' => $record->branch_id,
        ];

        return $lines;
    }

    private function applyInventoryValuation(PurchaseInvoice $record, bool $reverse = false): void
    {
        $record->loadMissing('lines.product');
        $netAmounts = $this->netAmountsByLine($record);

        foreach ($record->lines as $line) {
            if ($line->receipt_line_id === null || $line->product?->isService()) {
                continue;
            }

            $receiptLine = UnpricedInventoryReceiptLine::query()->lockForUpdate()->findOrFail($line->receipt_line_id);
            $clearedValue = bcmul((string) $receiptLine->provisional_unit_value, (string) $line->quantity, 4);
            $finalBaseValue = bcmul((string) ($netAmounts[$line->getKey()] ?? '0.0000'), (string) $record->exchange_rate, 4);
            $quantityDelta = $reverse ? bcmul((string) $line->quantity, '-1', 8) : (string) $line->quantity;
            $valueDelta = $reverse ? bcmul($clearedValue, '-1', 4) : $clearedValue;
            $newClearedQuantity = bcadd((string) $receiptLine->grni_cleared_quantity, $quantityDelta, 8);
            $eligibleQuantity = bcsub((string) $receiptLine->accepted_quantity, (string) $receiptLine->grni_returned_quantity, 8);

            if (bccomp($newClearedQuantity, '0', 8) < 0 || bccomp($newClearedQuantity, $eligibleQuantity, 8) > 0) {
                throw new DomainException(__('Purchase Invoice would over-clear the accepted GRNI quantity.'));
            }

            $receiptLine->forceFill([
                'grni_cleared_quantity' => $newClearedQuantity,
                'grni_cleared_value' => bcadd((string) $receiptLine->grni_cleared_value, $valueDelta, 4),
                'updated_by' => auth()->id(),
            ])->save();
            $line->forceFill([
                'grni_cleared_quantity' => $reverse ? '0.00000000' : $line->quantity,
                'grni_cleared_value' => $reverse ? '0.0000' : $clearedValue,
                'purchase_price_variance' => $reverse ? '0.0000' : bcsub($finalBaseValue, $clearedValue, 4),
            ])->save();
        }
    }

    /** @param array<string, array<string, mixed>> $posting */
    private function addPostingAmount(
        array &$posting,
        Account $account,
        string $amount,
        bool $debit,
        string $description,
        ?int $costCenterId = null,
    ): void {
        if (bccomp($amount, '0', 4) <= 0) {
            return;
        }

        $key = ($debit ? 'd:' : 'c:').$account->getKey().':'.($costCenterId ?? 'none');
        $posting[$key] ??= [
            'account_id' => (int) $account->getKey(), 'debit_amount' => '0.0000',
            'credit_amount' => '0.0000', 'description' => $description, 'cost_center_id' => $costCenterId,
        ];
        $column = $debit ? 'debit_amount' : 'credit_amount';
        $posting[$key][$column] = bcadd((string) $posting[$key][$column], $amount, 4);
    }

    /** @return array<int, string> */
    private function netAmountsByLine(PurchaseInvoice $record): array
    {
        $lineBaseTotal = $record->lines->sum(fn (PurchaseInvoiceLine $line): float => (float) $line->total_before_tax);
        $headerDiscount = (float) $record->header_discount_amount;
        $allocatedHeaderDiscount = 0.0;
        $lastIndex = max(0, $record->lines->count() - 1);
        $amounts = [];

        foreach ($record->lines->values() as $index => $line) {
            $lineBase = (float) $line->total_before_tax;
            $share = $lineBaseTotal > 0 ? $headerDiscount * ($lineBase / $lineBaseTotal) : 0.0;

            if ($index === $lastIndex) {
                $share = $headerDiscount - $allocatedHeaderDiscount;
            }

            $allocatedHeaderDiscount += $share;
            $amounts[$line->getKey()] = number_format(max(0, $lineBase - $share), 4, '.', '');
        }

        return $amounts;
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

    /** @param array<string, mixed> $line */
    private function lineCostCenterId(array $line, ?PurchaseOrderLine $purchaseOrderLine, int $companyId): ?int
    {
        $docNum = trim((string) ($line['cost_center_doc_num'] ?? ''));

        if ($docNum === '') {
            return $purchaseOrderLine?->cost_center_id;
        }

        return CostCenter::query()
            ->forCompany($companyId)
            ->active()
            ->where('is_group', false)
            ->where('doc_num', $docNum)
            ->valueOrFail('id');
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

    private function assertOperatingContext(PurchaseInvoice $record): void
    {
        if ($record->financialPeriod?->is_closed) {
            throw new DomainException(__('purchase_invoices.messages.period_closed'));
        }

        $context = $this->operatingContext->snapshot(request());
        if ((int) $record->company_id !== (int) $context['company_id'] || (int) $record->branch_id !== (int) $context['branch_id']
            || (int) $record->financial_period_id !== (int) $context['financial_period_id']) {
            throw new DomainException(__('The document is outside the active operating context.'));
        }
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
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->first();
    }

    private function purchaseOrder(int $companyId, ?string $docNum): ?PurchaseOrder
    {
        $docNum = trim((string) $docNum);

        return $docNum === ''
            ? null
            : PurchaseOrder::query()->forCompany($companyId)->where('doc_num', $docNum)->first();
    }

    private function purchaseOrderLine(PurchaseInvoice $invoice, ?string $publicId): ?PurchaseOrderLine
    {
        $publicId = trim((string) $publicId);
        if ($publicId === '') {
            return null;
        }

        return PurchaseOrderLine::query()
            ->where('purchase_order_id', $invoice->purchase_order_id)
            ->where('public_id', $publicId)
            ->first();
    }

    private function receiptLine(?PurchaseOrderLine $purchaseOrderLine, ?string $publicId): ?UnpricedInventoryReceiptLine
    {
        $publicId = trim((string) $publicId);
        if ($publicId === '' || ! $purchaseOrderLine instanceof PurchaseOrderLine) {
            return null;
        }

        return UnpricedInventoryReceiptLine::query()
            ->where('purchase_order_line_id', $purchaseOrderLine->getKey())
            ->where('public_id', $publicId)
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
