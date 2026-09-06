<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductComponentUnitConversionService;
use Modules\Core\Services\ProductComponentUnitOptionsService;
use Modules\Core\Services\ProductImageResolver;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderLine;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseRequisitionLine;
use Modules\Purchases\Models\Supplier;

class PurchaseOrderService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingContextService $operatingContext,
        private readonly PurchaseOrderCalculationService $calculator,
        private readonly ProductComponentUnitOptionsService $unitOptions,
        private readonly ProductImageResolver $productImages,
        private readonly NumericFormatService $numbers,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $context = $this->currentContext();
            app(FinancialPeriodService::class)->resolveOpenForPostingDate($context['company_id'], $data['document_date'], $context['financial_period_id'], lockForUpdate: true);
            $lines = $this->linesForCalculation($data['lines'] ?? [], $context);
            $calculation = $this->calculator->calculate($lines, $data['freight_amount'] ?? 0);
            $record = PurchaseOrder::query()->create([
                ...$this->values($data, $context),
                ...$calculation['order'],
                ...$this->document($data, $context),
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($record, $calculation['lines'], $context);
            app(ProcurementAttachmentService::class)->attach($record, $data['attachment_file_doc_nums'] ?? [], ProcurementAttachmentService::OperationalCollection, $context['company_id']);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $this->load($record->refresh())];
        });
    }

    public function update(PurchaseOrder $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $context = $this->currentContext();

            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertInCurrentContext($locked, $context);
            $this->assertEditable($locked);

            $oldDocNumber = $locked->doc_number === null ? null : (int) $locked->doc_number;
            $oldDocNum = $locked->doc_num;
            $lines = $this->linesForCalculation($data['lines'] ?? [], $context, $locked);
            $calculation = $this->calculator->calculate($lines, $data['freight_amount'] ?? $locked->freight_amount);
            $values = [
                ...$this->values($data, $context),
                ...$calculation['order'],
            ];

            foreach (['purchase_requisition_id', 'request_for_quotation_id', 'supplier_quotation_id', 'supplier_selection_id', 'purchase_type', 'payment_terms', 'internal_reference', 'direct_procurement_override', 'direct_procurement_reason'] as $sourceField) {
                if (! array_key_exists($sourceField, $data)) {
                    unset($values[$sourceField]);
                }
            }

            if (array_key_exists('doc_number', $data) && $data['doc_number']) {
                $values = [...$values, ...$this->document($data, $context)];
            }

            $locked->forceFill($values);
            $headerChanged = $locked->isDirty();
            if ($headerChanged) {
                $this->audit->saveUpdate($locked);
            }
            $linesChanged = $this->syncLines($locked->refresh(), $calculation['lines'], $context);
            $attachmentsChanged = app(ProcurementAttachmentService::class)->attach($locked, $data['attachment_file_doc_nums'] ?? [], ProcurementAttachmentService::OperationalCollection, $context['company_id']);
            if (! $headerChanged && ($linesChanged || $attachmentsChanged)) {
                $this->audit->touchUpdateAudit($locked);
            }

            return [
                'record' => $this->load($locked->refresh()),
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function submit(PurchaseOrder $record): PurchaseOrder
    {
        return DB::transaction(function () use ($record): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($record->id);
            $this->assertInCurrentContext($locked, $this->currentContext());
            if ($locked->status === PurchaseOrder::StatusSubmitted) {
                return $locked;
            }
            $this->assertEditable($locked);
            if (! $locked->lines()->exists()) {
                throw new DomainException(__('purchase_orders.messages.no_lines_approve'));
            }
            $locked->forceFill(['status' => PurchaseOrder::StatusSubmitted, 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'updated_by' => auth()->id()])->save();
            app(ProcurementAuditService::class)->record($locked, 'purchase_order.submitted');

            return $locked;
        }, 3);
    }

    public function reject(PurchaseOrder $record, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($record, $reason): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($record->id);
            $this->assertInCurrentContext($locked, $this->currentContext());
            if ($locked->status === PurchaseOrder::StatusRejected) {
                return $locked;
            }
            if ($locked->status !== PurchaseOrder::StatusSubmitted || blank($reason)) {
                throw new DomainException(__('Only a submitted purchase order can be rejected with a reason.'));
            }
            $locked->forceFill(['status' => PurchaseOrder::StatusRejected, 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'rejection_reason' => trim($reason), 'updated_by' => auth()->id()])->save();
            app(ProcurementAuditService::class)->record($locked, 'purchase_order.rejected');

            return $locked;
        }, 3);
    }

    public function approve(PurchaseOrder $record): PurchaseOrder
    {
        return DB::transaction(function () use ($record): PurchaseOrder {
            $context = $this->currentContext();

            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()
                ->with(['lines.product', 'lines.unit'])
                ->lockForUpdate()
                ->findOrFail($record->getKey());
            $this->assertInCurrentContext($locked, $context);

            if ($locked->trashed()) {
                throw new DomainException(__('purchase_orders.messages.deleted_not_approvable'));
            }

            if ($locked->isApproved()) {
                return $this->load($locked);
            }
            if (! in_array($locked->status, [PurchaseOrder::StatusDraft, PurchaseOrder::StatusSubmitted], true)) {
                throw new DomainException(__('purchase_orders.messages.document_not_approvable'));
            }

            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('purchase_orders.messages.no_lines_approve'));
            }

            foreach ($locked->lines as $line) {
                if (! $line->product instanceof Product || ! $line->unit instanceof ItemUnit || (float) $line->ordered_quantity <= 0) {
                    throw new DomainException(__('purchase_orders.messages.no_lines_approve'));
                }
            }

            $locked->forceFill([
                'status' => PurchaseOrder::StatusApproved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();

            $this->refreshSourceRequests($locked);

            return $this->load($locked->refresh());
        });
    }

    public function markSent(PurchaseOrder $record): PurchaseOrder
    {
        return DB::transaction(function () use ($record): PurchaseOrder {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertInCurrentContext($locked, $this->currentContext());
            if (! $locked->isApproved()) {
                throw new DomainException(__('Only an approved purchase order can be marked as sent.'));
            }
            if (! $locked->sent_at) {
                $this->audit->saveUpdate($locked, ['sent_at' => now(), 'sent_by' => auth()->id()]);
            }

            return $this->load($locked);
        }, 3);
    }

    public function close(PurchaseOrder $record): PurchaseOrder
    {
        return DB::transaction(function () use ($record): PurchaseOrder {
            $context = $this->currentContext();

            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertInCurrentContext($locked, $context);

            if ($locked->trashed()) {
                throw new DomainException(__('purchase_orders.messages.deleted_not_closeable'));
            }

            if (! $locked->isApproved()) {
                throw new DomainException(__('purchase_orders.messages.close_requires_approved'));
            }

            $locked->forceFill([
                'status' => PurchaseOrder::StatusClosed,
                'closed_by' => auth()->id(),
                'closed_at' => now(),
                'updated_by' => auth()->id(),
            ])->save();

            $this->refreshSourceRequests($locked);

            return $this->load($locked->refresh());
        });
    }

    public function cancel(PurchaseOrder $record, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($record, $reason): PurchaseOrder {
            $context = $this->currentContext();

            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertInCurrentContext($locked, $context);

            if ($locked->trashed()) {
                throw new DomainException(__('purchase_orders.messages.deleted_not_cancelable'));
            }

            if ($locked->isClosed()) {
                throw new DomainException(__('purchase_orders.messages.closed_cancel_forbidden'));
            }

            if ($locked->isCancelled()) {
                return $this->load($locked);
            }

            if (PurchaseInvoice::query()->where('purchase_order_id', $locked->getKey())->whereNotIn('status', ['cancelled', 'reversed'])->exists()
                || UnpricedInventoryReceipt::query()->where('purchase_order_id', $locked->getKey())->whereNotIn('status', ['cancelled', 'reversed'])->exists()) {
                throw new DomainException(__('purchase_orders.messages.received_cancel_forbidden'));
            }

            $locked->forceFill([
                'status' => PurchaseOrder::StatusCancelled,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => trim($reason),
                'updated_by' => auth()->id(),
            ])->save();

            $this->refreshSourceRequests($locked);

            return $this->load($locked->refresh());
        });
    }

    public function delete(PurchaseOrder $record): void
    {
        DB::transaction(function () use ($record): void {
            $context = $this->currentContext();

            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($record->getKey());
            $this->assertInCurrentContext($locked, $context);
            $this->assertDeletable($locked);
            $sourceIds = $locked->lines()->pluck('purchase_requisition_line_id')->filter()->all();
            $this->audit->softDelete($locked);

            $locked->refresh()->lines()->get()->each(function (PurchaseOrderLine $line): void {
                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });
            $this->refreshSourceRequests($locked, $sourceIds);
        });
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $context = $this->currentContext();
            $deleted = 0;

            foreach (PurchaseOrder::query()
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
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

    public function restore(PurchaseOrder $record): PurchaseOrder
    {
        return DB::transaction(function () use ($record): PurchaseOrder {
            $context = $this->currentContext();
            $this->assertInCurrentContext($record, $context);

            if (! $record->trashed()) {
                throw new DomainException(__('purchase_orders.messages.restore_not_allowed'));
            }

            if (PurchaseOrder::query()
                ->where('company_id', $record->company_id)
                ->where('financial_period_id', $record->financial_period_id)
                ->where('doc_number', $record->doc_number)
                ->whereKeyNot($record->getKey())
                ->whereNull('deleted_at')
                ->exists()) {
                throw new DomainException(__('purchase_orders.messages.restore_conflict'));
            }

            $deletedAt = $record->deleted_at;
            $this->audit->restore($record, auth()->id());
            $record->lines()
                ->withTrashed()
                ->when($deletedAt, fn ($query) => $query->where('deleted_at', '>=', $deletedAt))
                ->get()
                ->each
                ->restore();

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
            'branchStore.branch',
            'supplier',
            'currency',
            'lines.product.unit',
            'lines.unit',
            'createdBy',
            'updatedBy',
            'approvedBy',
            'closedBy',
            'cancelledBy',
        ];
    }

    private function load(PurchaseOrder $record): PurchaseOrder
    {
        return $record->load($this->defaultRelations());
    }

    /**
     * @return array{company_id: int, financial_period_id: int, branch_id: int}
     */
    private function currentContext(): array
    {
        $snapshot = $this->operatingContext->snapshot(request());

        if (! $snapshot['company_id'] || ! $snapshot['financial_period_id'] || ! $snapshot['branch_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }

        return [
            'company_id' => (int) $snapshot['company_id'],
            'financial_period_id' => (int) $snapshot['financial_period_id'],
            'branch_id' => (int) $snapshot['branch_id'],
        ];
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     * @return array<string, mixed>
     */
    private function values(array $data, array $context): array
    {
        $supplier = $this->supplier($context['company_id'], $data['supplier_doc_num'] ?? null);
        $currency = $this->currency($context['company_id'], $data['currency_doc_num'] ?? null);
        $branchStore = $this->branchStore($context['company_id'], $data['branch_store_uuid'] ?? null);

        return [
            'company_id' => $context['company_id'],
            'financial_period_id' => $context['financial_period_id'],
            'branch_id' => $context['branch_id'],
            'branch_store_id' => $branchStore->getKey(),
            'supplier_id' => $supplier->getKey(),
            'currency_id' => $currency?->getKey(),
            'document_date' => $data['document_date'],
            'exchange_rate' => $this->numbers->normalizeToScale($data['exchange_rate'] ?? 1, 6) ?? '1.000000',
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'supplier_reference' => $data['supplier_reference'] ?? null,
            'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
            'request_for_quotation_id' => $data['request_for_quotation_id'] ?? null,
            'supplier_quotation_id' => $data['supplier_quotation_id'] ?? null,
            'supplier_selection_id' => $data['supplier_selection_id'] ?? null,
            'purchase_type' => $data['purchase_type'] ?? 'standard',
            'payment_terms' => filled($data['payment_terms'] ?? null)
                ? $data['payment_terms']
                : ($supplier->payment_terms_days !== null ? __('Net :days days', ['days' => $supplier->payment_terms_days]) : null),
            'freight_amount' => $this->numbers->normalizeToScale($data['freight_amount'] ?? 0, 4) ?? '0.0000',
            'internal_reference' => $data['internal_reference'] ?? null,
            'direct_procurement_override' => (bool) ($data['direct_procurement_override'] ?? false),
            'direct_procurement_reason' => $data['direct_procurement_reason'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
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
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     * @return list<array<string, mixed>>
     */
    private function linesForCalculation(array $lines, array $context, ?PurchaseOrder $record = null): array
    {
        $existing = $record instanceof PurchaseOrder
            ? $record->lines()->withTrashed()->get()->keyBy('public_id')
            : collect();

        return collect($lines)
            ->map(function (array $line) use ($existing): array {
                $publicId = trim((string) ($line['public_id'] ?? ''));
                $existingLine = $publicId !== '' ? $existing->get($publicId) : null;

                return [
                    ...$line,
                    'received_quantity' => $existingLine instanceof PurchaseOrderLine
                        ? $this->calculator->formatQuantity($existingLine->received_quantity)
                        : $this->calculator->formatQuantity(0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     */
    private function syncLines(PurchaseOrder $record, array $lines, array $context): bool
    {
        $changed = false;
        $existing = $record->lines()->get()->keyBy('public_id');
        $previousSourceIds = $existing->pluck('purchase_requisition_line_id')->filter()->all();
        $lines = collect($lines)->map(function (array $line) use ($existing): array {
            $previous = $existing->get($line['public_id'] ?? '');
            foreach (['purchase_requisition_line_id', 'request_for_quotation_line_id', 'supplier_quotation_line_id', 'supplier_selection_line_id'] as $key) {
                if ($previous instanceof PurchaseOrderLine && $previous->{$key} !== null) {
                    $line[$key] = $previous->{$key};
                }
            }

            return $line;
        })->all();
        $this->assertRequisitionLines($record, $lines, $context);
        $kept = [];

        foreach (array_values($lines) as $index => $line) {
            $product = $this->product($context['company_id'], $line['product_doc_num'] ?? null);
            $publicId = trim((string) ($line['public_id'] ?? ''));
            $existingLine = $publicId !== '' ? $existing->get($publicId) : null;

            if (! $product instanceof Product) {
                continue;
            }

            if (! $product->isPurchasable()
                && (! $existingLine instanceof PurchaseOrderLine || (int) $existingLine->product_id !== (int) $product->getKey())) {
                throw new DomainException(__('purchase_orders.messages.purchase_product_type_invalid'));
            }

            $unit = $this->unitOptions->unitForProduct($product, $line['unit_doc_num'] ?? null, $context['company_id']);

            if (! $unit instanceof ItemUnit) {
                continue;
            }

            $values = [
                'company_id' => $context['company_id'],
                'financial_period_id' => $context['financial_period_id'],
                'line_number' => $index + 1,
                'product_id' => $product->getKey(),
                'unit_id' => $unit->getKey(),
                'purchase_requisition_line_id' => $line['purchase_requisition_line_id'] ?? null,
                'request_for_quotation_line_id' => $line['request_for_quotation_line_id'] ?? null,
                'supplier_quotation_line_id' => $line['supplier_quotation_line_id'] ?? null,
                'supplier_selection_line_id' => $line['supplier_selection_line_id'] ?? null,
                'cost_center_id' => ! array_key_exists('cost_center_doc_num', $line) && $existingLine ? $existingLine->cost_center_id : $this->lineCostCenterId($line, $context['company_id']),
                'ordered_quantity' => $line['ordered_quantity'],
                'received_quantity' => $line['received_quantity'],
                'remaining_quantity' => $line['remaining_quantity'],
                'unit_price' => $line['unit_price'],
                'line_total' => $line['line_total'],
                'description' => $line['description'] ?? null,
                'discount_type' => $line['discount_type'],
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $line['tax_amount'],
                'subtotal_amount' => $line['subtotal_amount'],
                'total_before_tax' => $line['total_before_tax'],
                'total_after_tax' => $line['total_after_tax'],
                'required_delivery_date' => $line['required_delivery_date'] ?? null,
                'specification' => $line['specification'] ?? null,
                'product_snapshot' => $this->lineProductSnapshot($existingLine, $product, $unit),
                'notes' => $line['notes'] ?? null,
            ];

            if ($existingLine instanceof PurchaseOrderLine) {
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
            ->each(function (PurchaseOrderLine $line) use (&$changed): void {
                $changed = true;
                if ((float) $line->received_quantity > 0) {
                    throw new DomainException(__('purchase_orders.messages.received_line_remove_forbidden'));
                }

                $line->forceFill(['deleted_by' => auth()->id()])->save();
                $line->delete();
            });
        $this->refreshSourceRequests($record, $previousSourceIds);

        return $changed;
    }

    private function assertRequisitionLines(PurchaseOrder $order, array $lines, array $context): void
    {
        $sourceLines = collect($lines)->filter(fn (array $line): bool => filled($line['purchase_requisition_line_id'] ?? null));
        foreach ($sourceLines->groupBy('purchase_requisition_line_id')->sortKeys() as $id => $inputs) {
            $source = PurchaseRequisitionLine::query()->with('requisition')->lockForUpdate()->find($id);
            if (! $source instanceof PurchaseRequisitionLine) {
                throw new DomainException(__('The selected purchase request line is invalid.'));
            }
            $request = PurchaseRequisition::query()->lockForUpdate()->findOrFail($source->purchase_requisition_id);
            if ((int) $request->company_id !== $context['company_id']
                || (int) $request->branch_id !== $context['branch_id']
                || ($request->branch_store_id !== null && (int) $request->branch_store_id !== (int) $order->branch_store_id)
                || ! in_array($request->status, [PurchaseRequisition::StatusApproved, PurchaseRequisition::StatusPartiallyConverted, PurchaseRequisition::StatusFullyConverted], true)) {
                throw new DomainException(__('Purchase orders require approved purchase request lines in the same operating context and warehouse.'));
            }
            foreach ($inputs as $input) {
                $product = $this->product($context['company_id'], $input['product_doc_num'] ?? null);
                $unit = $product ? $this->unitOptions->unitForProduct($product, $input['unit_doc_num'] ?? null, $context['company_id']) : null;
                if ($product?->getKey() !== $source->product_id || $unit?->getKey() !== $source->unit_id) {
                    throw new DomainException(__('Purchase order item and unit must match the approved purchase request line.'));
                }
            }
            $otherOrders = (float) $source->purchaseOrderLines()->where('purchase_order_id', '<>', $order->getKey())
                ->whereHas('purchaseOrder', fn ($query) => $query->whereNotIn('status', [PurchaseOrder::StatusCancelled, 'rejected']))->sum('ordered_quantity');
            if ($otherOrders + $inputs->sum(fn (array $input): float => (float) $input['ordered_quantity']) > (float) $source->approved_quantity + 0.00000001) {
                throw new DomainException(__('Ordered quantities exceed the approved purchase request quantity.'));
            }
        }
        if ($order->purchase_requisition_id !== null && $sourceLines->count() !== count($lines)) {
            throw new DomainException(__('Every sourced purchase order line must retain its purchase request reference.'));
        }
    }

    private function refreshSourceRequests(PurchaseOrder $order, array $previousSourceIds = []): void
    {
        $ids = array_merge($previousSourceIds, $order->lines()->pluck('purchase_requisition_line_id')->filter()->all());
        $requestIds = PurchaseRequisitionLine::query()->whereIn('id', $ids)->pluck('purchase_requisition_id');
        PurchaseRequisition::query()->whereIn('id', $requestIds)->orderBy('id')->lockForUpdate()->get()
            ->each(fn (PurchaseRequisition $request) => $request->refreshOrderingStatus());
    }

    private function supplier(int $companyId, ?string $docNum): Supplier
    {
        $supplier = Supplier::query()
            ->active()
            ->forCompany($companyId)
            ->where('doc_num', trim((string) $docNum))
            ->first();

        if (! $supplier instanceof Supplier) {
            throw new DomainException(__('purchase_orders.messages.supplier_unavailable'));
        }

        return $supplier;
    }

    /** @param array<string, mixed> $line */
    private function lineCostCenterId(array $line, int $companyId): ?int
    {
        $docNum = trim((string) ($line['cost_center_doc_num'] ?? ''));

        if ($docNum === '') {
            return null;
        }

        return CostCenter::query()
            ->forCompany($companyId)
            ->active()
            ->where('is_group', false)
            ->where('doc_num', $docNum)
            ->valueOrFail('id');
    }

    private function currency(int $companyId, ?string $docNum): ?Currency
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        $currency = Currency::query()
            ->active()
            ->forCompany($companyId)
            ->where('doc_num', $docNum)
            ->first();

        if (! $currency instanceof Currency) {
            throw new DomainException(__('purchase_orders.messages.currency_unavailable'));
        }

        return $currency;
    }

    private function branchStore(int $companyId, ?string $uuid): BranchStore
    {
        $store = BranchStore::query()
            ->where('public_uuid', trim((string) $uuid))
            ->whereNull('deleted_at')
            ->whereHas('branch', fn ($query) => $query
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->whereNull('deleted_at'))
            ->first();

        if (! $store instanceof BranchStore) {
            throw new DomainException(__('purchase_orders.messages.store_unavailable'));
        }

        return $store;
    }

    private function product(int $companyId, ?string $docNum): ?Product
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return Product::query()
            ->with('mainImageUsage.file')
            ->active()
            ->forCompany($companyId)
            ->where('products.doc_num', $docNum)
            ->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
            ->leftJoin('item_units as equivalent_units', 'equivalent_units.id', '=', 'products.equivalent_unit_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'products.item_category_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'products.item_group_id')
            ->leftJoin('item_models', 'item_models.id', '=', 'products.item_model_id')
            ->leftJoin('item_colors', 'item_colors.id', '=', 'products.item_color_id')
            ->leftJoin('item_sizes', 'item_sizes.id', '=', 'products.item_size_id')
            ->leftJoin('item_decals', 'item_decals.id', '=', 'products.item_decal_id')
            ->leftJoin('item_origin_countries', 'item_origin_countries.id', '=', 'products.item_origin_country_id')
            ->select([
                'products.id',
                'products.company_id',
                'products.doc_number',
                'products.doc_num',
                'products.name',
                'products.image_path',
                'products.barcode',
                'products.item_classification',
                'products.status',
                'products.item_unit_id',
                'products.equivalent_unit_id',
                'products.equivalent_value',
                'item_units.doc_num as unit_doc_num',
                'item_units.name as unit_name',
                'equivalent_units.doc_num as equivalent_unit_doc_num',
                'equivalent_units.name as equivalent_unit_name',
                'item_categories.name as category_name',
                'item_groups.name as group_name',
                'item_models.name as model_name',
                'item_colors.name as color_name',
                'item_sizes.name as size_name',
                'item_decals.name as decal_name',
                'item_origin_countries.name as origin_country_name',
            ])
            ->first();
    }

    /**
     * @return array<string, string|null>
     */
    private function lineProductSnapshot(?PurchaseOrderLine $existingLine, Product $product, ItemUnit $unit): array
    {
        if (
            $existingLine instanceof PurchaseOrderLine
            && (int) $existingLine->product_id === (int) $product->getKey()
            && (int) $existingLine->unit_id === (int) $unit->getKey()
            && is_array($existingLine->product_snapshot)
            && $existingLine->product_snapshot !== []
        ) {
            return $existingLine->product_snapshot;
        }

        return $this->productSnapshot($product, $unit);
    }

    /**
     * @return array<string, string|null>
     */
    private function productSnapshot(Product $product, ItemUnit $unit): array
    {
        $factor = app(ProductComponentUnitConversionService::class)
            ->convert('1', $product, $unit, $product, $product->unit, 8);
        if ($factor === null || bccomp($factor, '0', 8) <= 0) {
            throw new DomainException(__('The selected purchase unit has no valid conversion to the stock unit.'));
        }

        return [
            'stock_conversion_factor' => $factor,
            'stock_unit_id' => (string) $product->item_unit_id,
            'doc_num' => (string) $product->doc_num,
            'name' => (string) $product->name,
            'barcode' => $this->nullableText($product->barcode),
            'unit_doc_num' => (string) $unit->doc_num,
            'unit_label' => $this->unitLabel($unit),
            'item_classification' => __("products.classifications.{$product->item_classification}"),
            'category' => $this->nullableText($product->category_name),
            'group' => $this->nullableText($product->group_name),
            'size' => $this->nullableText($product->size_name),
            'color' => $this->nullableText($product->color_name),
            'model' => $this->nullableText($product->model_name),
            'decal' => $this->nullableText($product->decal_name),
            'origin_country' => $this->nullableText($product->origin_country_name),
            'image_url' => $this->productImages->url($product),
        ];
    }

    private function unitLabel(ItemUnit $unit): string
    {
        return trim(implode(' / ', array_filter([$unit->doc_num, $unit->name])));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function assertEditable(PurchaseOrder $record): void
    {
        if ($record->isApproved()) {
            throw new DomainException(__('purchase_orders.messages.approved_edit_forbidden'));
        }

        if ($record->isClosed()) {
            throw new DomainException(__('purchase_orders.messages.closed_edit_forbidden'));
        }

        if ($record->isCancelled()) {
            throw new DomainException(__('purchase_orders.messages.cancelled_edit_forbidden'));
        }

        if ($record->hasReceipts()) {
            throw new DomainException(__('purchase_orders.messages.received_edit_forbidden'));
        }
    }

    private function assertDeletable(PurchaseOrder $record): void
    {
        if (! $record->isDeletable()) {
            throw new DomainException(__('purchase_orders.messages.document_delete_blocked'));
        }
    }

    /**
     * @param  array{company_id: int, financial_period_id: int, branch_id: int}  $context
     */
    private function assertInCurrentContext(PurchaseOrder $record, array $context): void
    {
        if (FinancialPeriod::query()->whereKey($record->financial_period_id)->value('is_closed')) {
            throw new DomainException(__('journal_entries.messages.period_closed'));
        }
        if (
            (int) $record->company_id !== $context['company_id']
            || (int) $record->financial_period_id !== $context['financial_period_id']
            || (int) $record->branch_id !== $context['branch_id']
        ) {
            throw new DomainException(__('operating_context.messages.required'));
        }
    }

    private function nextScopedDocument(int $companyId, int $financialPeriodId): array
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable('purchase_orders')));
        }

        $nextNumber = ((int) PurchaseOrder::query()
            ->where('company_id', $companyId)
            ->where('financial_period_id', $financialPeriodId)
            ->max('doc_number')) + 1;

        return [
            'doc_number' => $nextNumber,
            'doc_num' => $this->formatScopedDocNum($nextNumber, $financialPeriodId),
        ];
    }

    private function formatScopedDocNum(int $docNumber, int $financialPeriodId): string
    {
        $base = $this->documents->format('purchase_orders', $docNumber);
        $period = FinancialPeriod::query()->find($financialPeriodId);

        if (! $period instanceof FinancialPeriod || ! is_numeric($period->doc_number)) {
            return $base;
        }

        $config = config('document_numbers.purchase_orders', []);
        $padding = max(0, (int) ($config['padding'] ?? 5));
        $prefix = (string) ($config['prefix'] ?? 'PO-');
        $periodNumber = str_pad((string) $period->doc_number, $padding, '0', STR_PAD_LEFT);
        $documentNumber = str_pad((string) $docNumber, $padding, '0', STR_PAD_LEFT);

        return $prefix.$periodNumber.'-'.$documentNumber;
    }
}
