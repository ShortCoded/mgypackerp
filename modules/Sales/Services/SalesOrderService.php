<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\QuotationPaymentMilestone;
use Modules\Sales\Models\QuotationRevision;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class SalesOrderService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly SalesAmountService $amounts,
        private readonly SalesUnitConversionService $unitConversions,
        private readonly CreditControlService $creditControl,
        private readonly SalesCycleAuditService $audit,
    ) {}

    /** @param array{company_id: int, financial_period_id: int, branch_id: int} $context */
    public function createFromQuotation(Quotation $quotation, array $context): SalesOrder
    {
        return DB::transaction(function () use ($quotation, $context): SalesOrder {
            $locked = Quotation::query()
                ->with(['currentRevision.lines.product', 'currentRevision.lines.unit', 'currentRevision.paymentMilestones'])
                ->lockForUpdate()
                ->findOrFail($quotation->getKey());
            $revision = $locked->currentRevision;

            if ($locked->status !== Quotation::StatusAccepted || ! $revision instanceof QuotationRevision || $locked->current_revision_id !== $revision->getKey()) {
                throw new DomainException('Only the accepted current quotation revision can be converted.');
            }
            if ((int) $locked->company_id !== $context['company_id'] || (int) $locked->branch_id !== $context['branch_id']) {
                throw new DomainException('Switch to the quotation operating company and branch before conversion.');
            }
            if ($locked->valid_until?->isBefore(now()->startOfDay())) {
                throw new DomainException('The accepted quotation has expired and must be revised before conversion.');
            }
            if (! $locked->customer_id || ! $locked->currency_id || $revision->lines->isEmpty()) {
                throw new DomainException('The quotation requires a customer, currency, and at least one sales line.');
            }
            if (SalesOrder::query()->where('quotation_revision_id', $revision->getKey())->exists()) {
                throw new DomainException('This quotation revision was already converted.');
            }

            $physicalLines = $revision->lines->reject(fn ($line): bool => $line->product?->item_classification === Product::ClassificationService);
            $store = $physicalLines->isEmpty() ? null : BranchStore::query()
                ->where('branch_id', $context['branch_id'])
                ->orderBy('position')
                ->orderBy('name')
                ->first();
            if ($physicalLines->isNotEmpty() && ! $store instanceof BranchStore) {
                throw new DomainException('The quotation branch needs a finished-goods store before physical lines can be converted.');
            }

            $requestedDate = $revision->lines->pluck('requested_date')->filter()->max();
            $expectedDeliveryDate = $requestedDate?->toDateString() ?? $locked->valid_until?->toDateString() ?? now()->toDateString();
            if ($expectedDeliveryDate < now()->toDateString()) {
                $expectedDeliveryDate = now()->toDateString();
            }

            return $this->create([
                ...$context,
                'quotation_id' => $locked->getKey(),
                'quotation_revision_id' => $revision->getKey(),
                'customer_id' => $locked->customer_id,
                'sales_employee_id' => $locked->sales_person_id,
                'currency_id' => $locked->currency_id,
                'branch_store_id' => $store?->getKey(),
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => $expectedDeliveryDate,
                'sales_channel' => 'quotation',
                'exchange_rate' => $locked->exchange_rate,
                'customer_reference' => $locked->customer_reference,
                'notes' => $revision->notes_snapshot ?: $locked->notes,
                'internal_notes' => $locked->internal_notes,
                'terms_snapshot' => $this->termSnapshot($revision->terms_snapshot),
                'payment_terms_snapshot' => $this->termSnapshot($revision->payment_terms_snapshot),
                'execution_terms_snapshot' => $this->termSnapshot($revision->execution_terms_snapshot),
                'warranty_terms_snapshot' => $this->termSnapshot($revision->warranty_terms_snapshot),
                'technical_notes_snapshot' => $this->termSnapshot($revision->technical_notes_snapshot),
                'delivery_terms_snapshot' => $this->termSnapshot($revision->delivery_terms_snapshot),
                'lines' => $this->quotationLines($revision),
                'payment_schedules' => $this->quotationPaymentSchedules($revision, $expectedDeliveryDate),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): SalesOrder
    {
        return DB::transaction(function () use ($data): SalesOrder {
            $this->assertRequiredContext($data);
            $lines = $this->validatedLines($data['lines'] ?? [], (int) $data['company_id']);
            $totals = $this->totals($lines);
            $agreement = CustomerCommercialAgreement::query()
                ->where('company_id', $data['company_id'])->where('customer_id', $data['customer_id'])
                ->where(fn ($query) => $query->whereNull('currency_id')->orWhere('currency_id', $data['currency_id'] ?? null))
                ->effective((string) $data['order_date'])->orderByRaw('currency_id is null')->latest('effective_from')->first();
            $requiredAdvance = $this->requiredAdvance($agreement, $totals['total_amount']);
            $numbers = $this->documents->nextForCompany(
                'sales_orders', SalesOrder::class, (int) $data['company_id'],
                fn ($query) => $query->where('financial_period_id', $data['financial_period_id']),
            );

            $order = SalesOrder::query()->create([
                ...collect($data)->except(['lines', 'payment_schedules', 'doc_number', 'doc_num'])->all(),
                ...$numbers,
                ...$totals,
                'agreement_snapshot' => $agreement?->snapshot(),
                'credit_limit_snapshot' => $agreement?->credit_limit ?? 0,
                'required_advance_amount' => $requiredAdvance,
                'status' => SalesOrder::StatusDraft,
                'credit_status' => 'pending',
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $index => $line) {
                $order->lines()->create([...$line, 'line_number' => $index + 1]);
            }

            $this->syncPaymentSchedules($order, $data['payment_schedules'] ?? []);
            $this->consumeQuotation($order);
            $this->recordStatus($order, null, SalesOrder::StatusDraft);
            $this->audit->record($order, 'sales_order.created');

            return $order->load(['lines.product', 'paymentSchedules', 'customer']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(SalesOrder $order, array $data): SalesOrder
    {
        return DB::transaction(function () use ($order, $data): SalesOrder {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if (! $locked->isEditable()) {
                throw new DomainException('Released sales orders must be reopened before amendment.');
            }

            $lines = $this->validatedLines($data['lines'] ?? [], (int) $locked->company_id);
            $totals = $this->totals($lines);
            $agreement = CustomerCommercialAgreement::query()
                ->where('company_id', $locked->company_id)->where('customer_id', $data['customer_id'])
                ->where(fn ($query) => $query->whereNull('currency_id')->orWhere('currency_id', $data['currency_id'] ?? null))
                ->effective((string) $data['order_date'])->orderByRaw('currency_id is null')->latest('effective_from')->first();
            $locked->update([
                ...collect($data)->except(['lines', 'payment_schedules', 'doc_number', 'doc_num', 'company_id', 'financial_period_id'])->all(),
                ...$totals,
                'agreement_snapshot' => $agreement?->snapshot(),
                'credit_limit_snapshot' => $agreement?->credit_limit ?? 0,
                'required_advance_amount' => $this->requiredAdvance($agreement, $totals['total_amount']),
                'updated_by' => auth()->id(),
            ]);
            $locked->lines()->delete();
            foreach ($lines as $index => $line) {
                $locked->lines()->create([...$line, 'line_number' => $index + 1]);
            }
            $locked->paymentSchedules()->delete();
            $this->syncPaymentSchedules($locked, $data['payment_schedules'] ?? []);
            $this->audit->record($locked, 'sales_order.amended', [
                'status' => $locked->status,
                'total_amount' => $locked->total_amount,
            ]);

            return $locked->refresh()->load(['lines.product', 'paymentSchedules']);
        });
    }

    public function submit(SalesOrder $order): SalesOrder
    {
        return $this->transition($order, [SalesOrder::StatusDraft, SalesOrder::StatusReopened], SalesOrder::StatusPendingApproval);
    }

    public function approve(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order): SalesOrder {
            $locked = SalesOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->getKey());
            if (! in_array($locked->status, [SalesOrder::StatusDraft, SalesOrder::StatusPendingApproval, SalesOrder::StatusHeldCredit], true)) {
                throw new DomainException('The sales order is not awaiting approval.');
            }
            if ($locked->lines->isEmpty()) {
                throw new DomainException('A sales order must contain at least one line.');
            }

            $condition = $this->creditControl->evaluate($locked);
            if ($condition['blocked']) {
                $from = $locked->status;
                $locked->update(['status' => SalesOrder::StatusHeldCredit, 'credit_status' => 'blocked', 'updated_by' => auth()->id()]);
                $this->recordStatus($locked, $from, SalesOrder::StatusHeldCredit, json_encode($condition, JSON_THROW_ON_ERROR));

                return $locked->refresh();
            }

            return $this->release($locked, 'clear');
        });
    }

    public function overrideCreditHold(SalesOrder $order, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason): SalesOrder {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if ($locked->status !== SalesOrder::StatusHeldCredit) {
                throw new DomainException('Only a credit-held order can be overridden.');
            }
            if (trim($reason) === '') {
                throw new DomainException('A credit override reason is required.');
            }

            $condition = $this->creditControl->evaluate($locked);
            if (! $condition['temporary_override_allowed']) {
                throw new DomainException('The applicable agreement does not permit a temporary override.');
            }

            $locked->creditOverrides()->create([
                'blocking_condition' => $condition,
                'reason' => trim($reason),
                'resulting_action' => 'released',
                'overridden_by' => auth()->id(),
                'overridden_at' => now(),
            ]);
            $this->audit->record($locked, 'sales_order.credit_overridden', ['reason' => trim($reason), 'blocking_condition' => $condition]);

            return $this->release($locked, 'overridden');
        });
    }

    public function reject(SalesOrder $order, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason): SalesOrder {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            $from = $locked->status;
            if (! in_array($from, [SalesOrder::StatusPendingApproval, SalesOrder::StatusHeldCredit], true)) {
                throw new DomainException('The sales order cannot be rejected from its current status.');
            }
            $locked->update(['status' => SalesOrder::StatusRejected, 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'rejection_reason' => trim($reason)]);
            $this->recordStatus($locked, $from, SalesOrder::StatusRejected, $reason);

            return $locked->refresh();
        });
    }

    public function reopen(SalesOrder $order, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason): SalesOrder {
            $locked = SalesOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->getKey());
            if (! in_array($locked->status, [SalesOrder::StatusApproved, SalesOrder::StatusRejected, SalesOrder::StatusClosed], true)) {
                throw new DomainException('The sales order cannot be reopened from its current status.');
            }
            if ($locked->lines->contains(fn (SalesOrderLine $line): bool => collect([
                $line->reserved_quantity,
                $line->production_requested_quantity,
                $line->produced_quantity,
                $line->delivered_quantity,
                $line->invoiced_quantity,
            ])->contains(fn (mixed $quantity): bool => $this->amounts->compare($quantity, '0', 8) > 0))) {
                throw new DomainException('An order with reservations, production, deliveries, or invoices cannot be amended; use controlled downstream reversal documents.');
            }
            $from = $locked->status;
            $locked->update(['status' => SalesOrder::StatusReopened, 'reopened_by' => auth()->id(), 'reopened_at' => now(), 'reopen_reason' => trim($reason)]);
            $this->recordStatus($locked, $from, SalesOrder::StatusReopened, $reason);
            $this->audit->record($locked, 'sales_order.reopened', ['reason' => trim($reason)]);

            return $locked->refresh();
        });
    }

    public function cancel(SalesOrder $order, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason): SalesOrder {
            $locked = SalesOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->getKey());
            if (in_array($locked->status, [SalesOrder::StatusCancelled, SalesOrder::StatusClosed], true)) {
                throw new DomainException('The sales order cannot be cancelled from its current status.');
            }
            if ($locked->lines->contains(fn (SalesOrderLine $line): bool => $this->amounts->compare($line->delivered_quantity, '0', 8) > 0 || $this->amounts->compare($line->invoiced_quantity, '0', 8) > 0)) {
                throw new DomainException('An order with deliveries or invoices must be reversed through downstream documents.');
            }
            foreach (InventoryReservation::query()->where('sales_order_id', $locked->getKey())->where('status', InventoryReservation::StatusActive)->lockForUpdate()->get() as $reservation) {
                $reservation->update(['released_quantity' => $reservation->remaining_quantity, 'status' => InventoryReservation::StatusReleased, 'released_by' => auth()->id(), 'released_at' => now(), 'release_reason' => trim($reason)]);
            }
            $from = $locked->status;
            $locked->update(['status' => SalesOrder::StatusCancelled, 'cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'cancel_reason' => trim($reason), 'updated_by' => auth()->id()]);
            $this->recordStatus($locked, $from, SalesOrder::StatusCancelled, $reason);
            $this->audit->record($locked, 'sales_order.cancelled', ['reason' => trim($reason)]);

            return $locked->refresh();
        });
    }

    private function release(SalesOrder $order, string $creditStatus): SalesOrder
    {
        $from = $order->status;
        $order->update(['status' => SalesOrder::StatusApproved, 'credit_status' => $creditStatus, 'approved_by' => auth()->id(), 'approved_at' => now(), 'updated_by' => auth()->id()]);
        $this->recordStatus($order, $from, SalesOrder::StatusApproved, $creditStatus === 'overridden' ? 'Credit hold overridden.' : null);
        $this->audit->record($order, 'sales_order.approved', ['credit_status' => $creditStatus]);

        return $order->refresh();
    }

    private function transition(SalesOrder $order, array $allowed, string $status): SalesOrder
    {
        return DB::transaction(function () use ($order, $allowed, $status): SalesOrder {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if (! in_array($locked->status, $allowed, true)) {
                throw new DomainException('Invalid sales order status transition.');
            }
            $from = $locked->status;
            $locked->update(['status' => $status, 'updated_by' => auth()->id()]);
            $this->recordStatus($locked, $from, $status);

            return $locked->refresh();
        });
    }

    /** @param list<array<string, mixed>> $input @return list<array<string, mixed>> */
    private function validatedLines(array $input, int $companyId): array
    {
        if ($input === []) {
            throw new DomainException('A sales order requires at least one line.');
        }

        return collect($input)->map(function (array $line) use ($companyId): array {
            $product = Product::query()->forCompany($companyId)->active()->findOrFail($line['product_id']);
            if (! $product->isSalesEligible()) {
                throw new DomainException('Only finished products and services may be sold.');
            }
            $this->amounts->assertPositive($line['quantity'], 'Line quantity must be greater than zero.');
            $this->amounts->assertPositive($line['unit_price'] ?? '0', 'Line unit price must be greater than zero.', 4);
            $unitSnapshot = $this->unitConversions->snapshot($product, $line['unit_id'] ?? null, $line['quantity']);
            $gross = $this->amounts->multiply($line['quantity'], $line['unit_price']);
            $discount = (string) ($line['discount_amount'] ?? 0);
            $tax = (string) ($line['tax_amount'] ?? 0);
            $total = $this->amounts->add($this->amounts->subtract($gross, $discount), $tax);

            return [
                ...collect($line)->except(['id', 'public_id', 'line_number'])->all(),
                'unit_id' => $unitSnapshot['unit_id'],
                'conversion_factor' => $unitSnapshot['conversion_factor'],
                'base_quantity' => $unitSnapshot['base_quantity'],
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'line_total' => $total,
                'product_classification_snapshot' => $product->item_classification,
                'reserved_quantity' => 0, 'reserved_base_quantity' => 0,
                'production_requested_quantity' => 0, 'production_requested_base_quantity' => 0,
                'produced_quantity' => 0, 'produced_base_quantity' => 0,
                'delivered_quantity' => 0, 'delivered_base_quantity' => 0,
                'invoiced_quantity' => 0, 'invoiced_base_quantity' => 0,
                'returned_quantity' => 0, 'returned_base_quantity' => 0,
            ];
        })->all();
    }

    /** @param list<array<string, mixed>> $lines @return array{subtotal_amount: string, discount_amount: string, tax_amount: string, total_amount: string} */
    private function totals(array $lines): array
    {
        $subtotal = $this->amounts->sum(array_map(fn (array $line): string => $this->amounts->multiply($line['quantity'], $line['unit_price']), $lines));
        $discount = $this->amounts->sum(array_column($lines, 'discount_amount'));
        $tax = $this->amounts->sum(array_column($lines, 'tax_amount'));

        return ['subtotal_amount' => $subtotal, 'discount_amount' => $discount, 'tax_amount' => $tax, 'total_amount' => $this->amounts->add($this->amounts->subtract($subtotal, $discount), $tax)];
    }

    private function requiredAdvance(?CustomerCommercialAgreement $agreement, string $total): string
    {
        if (! $agreement) {
            return '0.0000';
        }
        $percentage = $this->amounts->multiply($total, bcdiv((string) $agreement->required_advance_percentage, '100', 8));

        return $this->amounts->compare($percentage, $agreement->required_advance_minimum) >= 0 ? $percentage : (string) $agreement->required_advance_minimum;
    }

    /** @param list<array<string, mixed>> $schedules */
    private function syncPaymentSchedules(SalesOrder $order, array $schedules): void
    {
        if ($schedules === []) {
            return;
        }
        $total = $this->amounts->sum(array_column($schedules, 'amount'));
        if ($this->amounts->compare($total, $order->total_amount) !== 0) {
            throw new DomainException('Payment schedule amounts must equal the sales order total.');
        }
        foreach ($schedules as $index => $schedule) {
            $order->paymentSchedules()->create([...$schedule, 'line_number' => $index + 1, 'status' => 'pending', 'collected_amount' => 0, 'remaining_amount' => $schedule['amount']]);
        }
    }

    private function consumeQuotation(SalesOrder $order): void
    {
        if (! $order->quotation_id || ! $order->quotation_revision_id) {
            return;
        }
        $quotation = Quotation::query()->lockForUpdate()->findOrFail($order->quotation_id);
        $revision = QuotationRevision::query()->lockForUpdate()->findOrFail($order->quotation_revision_id);
        if ($quotation->status !== Quotation::StatusAccepted || $quotation->current_revision_id !== $revision->getKey()) {
            throw new DomainException('Only the accepted current quotation revision can be converted.');
        }
        if (SalesOrder::query()->where('quotation_revision_id', $revision->getKey())->whereKeyNot($order->getKey())->exists()) {
            throw new DomainException('This quotation revision was already converted.');
        }
        $quotation->update(['status' => Quotation::StatusConverted, 'updated_by' => auth()->id()]);
    }

    /** @return list<array<string, mixed>> */
    private function quotationLines(QuotationRevision $revision): array
    {
        $baseDiscount = $this->amounts->sum($revision->lines->pluck('discount_amount'));
        $documentDiscount = $this->amounts->subtract($revision->discount_amount, $baseDiscount);
        $discountableTotal = $this->amounts->sum($revision->lines->map(
            fn ($line): string => $this->amounts->subtract(
                $this->amounts->multiply($line->quantity, $line->unit_price),
                $line->discount_amount,
            ),
        ));
        $allocatedDocumentDiscount = '0.0000';
        $lastIndex = $revision->lines->count() - 1;

        return $revision->lines->values()->map(function ($line, int $index) use ($documentDiscount, $discountableTotal, &$allocatedDocumentDiscount, $lastIndex): array {
            $share = '0.0000';
            if ($this->amounts->compare($documentDiscount, '0') > 0) {
                if ($index === $lastIndex) {
                    $share = $this->amounts->subtract($documentDiscount, $allocatedDocumentDiscount);
                } else {
                    $lineBase = $this->amounts->subtract($this->amounts->multiply($line->quantity, $line->unit_price), $line->discount_amount);
                    $share = $this->amounts->round($this->amounts->multiply($documentDiscount, bcdiv($lineBase, $discountableTotal, 8), 8));
                    $allocatedDocumentDiscount = $this->amounts->add($allocatedDocumentDiscount, $share);
                }
            }

            return [
                'quotation_revision_line_id' => $line->getKey(),
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'description' => $line->description ?: $line->product_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $this->amounts->add($line->discount_amount, $share),
                'tax_amount' => $line->tax_amount,
                'requested_date' => $line->requested_date,
                'specifications' => $line->specifications,
                'customer_notes' => $line->notes,
                'warehouse_notes' => $line->warehouse_notes,
                'production_notes' => $line->production_notes,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function quotationPaymentSchedules(QuotationRevision $revision, string $expectedDeliveryDate): array
    {
        $milestones = $revision->paymentMilestones->values();
        if ($milestones->isEmpty()) {
            return [];
        }

        $weights = $milestones->map(function (QuotationPaymentMilestone $milestone) use ($revision): string {
            if ($this->amounts->compare($milestone->amount ?? '0', '0') > 0) {
                return (string) $milestone->amount;
            }

            return $this->amounts->multiply($revision->total, bcdiv((string) ($milestone->percentage ?? 0), '100', 8), 8);
        });
        $weightTotal = $this->amounts->sum($weights);
        if ($this->amounts->compare($weightTotal, '0') <= 0) {
            return [[
                'title' => 'Quotation total',
                'amount' => $revision->total,
                'due_date' => $expectedDeliveryDate,
                'due_condition' => 'custom',
            ]];
        }

        $allocated = '0.0000';
        $lastIndex = $milestones->count() - 1;

        return $milestones->map(function (QuotationPaymentMilestone $milestone, int $index) use ($weights, $weightTotal, $revision, $expectedDeliveryDate, &$allocated, $lastIndex): array {
            $amount = $index === $lastIndex
                ? $this->amounts->subtract($revision->total, $allocated)
                : $this->amounts->round($this->amounts->multiply($revision->total, bcdiv($weights[$index], $weightTotal, 8), 8));
            $allocated = $this->amounts->add($allocated, $amount);
            $dueDate = match ($milestone->due_type) {
                QuotationPaymentMilestone::DueOnContract => now()->toDateString(),
                QuotationPaymentMilestone::DueAfterDelivery => Carbon::parse($expectedDeliveryDate)->addDays(30)->toDateString(),
                QuotationPaymentMilestone::DueAfterInstallation => Carbon::parse($expectedDeliveryDate)->addDays(60)->toDateString(),
                default => $milestone->due_date?->toDateString() ?? $expectedDeliveryDate,
            };

            return [
                'installment_type' => 'quotation_milestone',
                'title' => $milestone->title,
                'description' => $milestone->description,
                'percentage' => $this->amounts->compare($revision->total, '0') > 0
                    ? $this->amounts->multiply($amount, bcdiv('100', $revision->total, 8), 4)
                    : '0.0000',
                'amount' => $amount,
                'due_date' => $dueDate,
                'due_condition' => $milestone->due_type ?: 'custom',
                'notes' => $milestone->notes,
            ];
        })->all();
    }

    /** @return array{content: string}|null */
    private function termSnapshot(?string $content): ?array
    {
        return filled($content) ? ['content' => $content] : null;
    }

    private function recordStatus(SalesOrder $order, ?string $from, string $to, ?string $reason = null): void
    {
        $order->statusHistory()->create(['from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'changed_by' => auth()->id(), 'changed_at' => now()]);
    }

    /** @param array<string, mixed> $data */
    private function assertRequiredContext(array $data): void
    {
        foreach (['company_id', 'financial_period_id', 'branch_id', 'customer_id', 'order_date'] as $key) {
            if (empty($data[$key])) {
                throw new DomainException("Missing required sales order context: {$key}.");
            }
        }
    }
}
