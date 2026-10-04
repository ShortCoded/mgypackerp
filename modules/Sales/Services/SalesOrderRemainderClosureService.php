<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCycleService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesIssueOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesOrderRemainderClosure;

class SalesOrderRemainderClosureService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly FinancialPeriodService $periods,
        private readonly SalesAmountService $amounts,
        private readonly SalesAccountingService $accounting,
        private readonly SalesIssueOrderService $issueOrders,
        private readonly SalesCycleAuditService $audit,
        private readonly ProductionCycleService $production,
    ) {}

    /**
     * @param  list<array{sales_order_line_public_id: string, expected_remaining_quantity: string|int|float}>  $lines
     */
    public function close(
        SalesOrder $order,
        array $lines,
        string $reason,
        string $closureDate,
        string $idempotencyKey,
        int $expectedFinancialPeriodId,
    ): SalesOrderRemainderClosure {
        $reason = trim($reason);
        if ($reason === '' || $lines === []) {
            throw new DomainException(__('sales_ui.remainder.messages.reason_lines_required'));
        }

        $canonicalLines = collect($lines)->map(fn (array $line): array => [
            'sales_order_line_public_id' => (string) $line['sales_order_line_public_id'],
            'expected_remaining_quantity' => bcadd((string) $line['expected_remaining_quantity'], '0', 8),
        ])->sortBy('sales_order_line_public_id')->values();
        if ($canonicalLines->pluck('sales_order_line_public_id')->unique()->count() !== $canonicalLines->count()) {
            throw new DomainException(__('sales_ui.remainder.messages.duplicate_line'));
        }

        $requestHash = hash('sha256', json_encode([
            'sales_order_id' => (int) $order->getKey(),
            'closure_date' => $closureDate,
            'reason' => $reason,
            'lines' => $canonicalLines->all(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $order,
            $canonicalLines,
            $reason,
            $closureDate,
            $idempotencyKey,
            $expectedFinancialPeriodId,
            $requestHash,
        ): SalesOrderRemainderClosure {
            Company::query()->whereKey($order->company_id)->lockForUpdate()->firstOrFail();
            $period = $this->periods->resolveOpenForPostingDate(
                (int) $order->company_id,
                $closureDate,
                $expectedFinancialPeriodId,
                lockForUpdate: true,
            );

            $existing = SalesOrderRemainderClosure::query()
                ->where('company_id', $order->company_id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof SalesOrderRemainderClosure) {
                if ((int) $existing->sales_order_id !== (int) $order->getKey()
                    || ! hash_equals($existing->request_hash, $requestHash)
                    || $existing->status !== SalesOrderRemainderClosure::StatusApplied) {
                    throw new DomainException(__('sales_ui.remainder.messages.token_conflict'));
                }

                return $existing->load(['lines.orderLine', 'order']);
            }

            $lockedOrder = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if (! in_array($lockedOrder->status, [
                SalesOrder::StatusApproved,
                SalesOrder::StatusPartiallyFulfilled,
                SalesOrder::StatusFulfilled,
            ], true)) {
                throw new DomainException(__('sales_ui.remainder.messages.ineligible_status'));
            }

            $publicIds = $canonicalLines->pluck('sales_order_line_public_id')->all();
            $lockedLines = SalesOrderLine::query()
                ->where('sales_order_id', $lockedOrder->getKey())
                ->whereIn('public_id', $publicIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($lockedLines->count() !== count($publicIds)) {
                throw new DomainException(__('sales_ui.remainder.messages.lines_missing'));
            }

            $expectedByPublicId = $canonicalLines->keyBy('sales_order_line_public_id');
            $declines = [];
            foreach ($lockedLines as $line) {
                if ($line->isService()) {
                    throw new DomainException(__('sales_ui.remainder.messages.service_line_unsupported'));
                }
                $remaining = $line->remainingDeliveryQuantity();
                $expected = $expectedByPublicId->get($line->public_id)['expected_remaining_quantity'];
                if (bccomp($remaining, $expected, 8) !== 0 || bccomp($remaining, '0', 8) <= 0) {
                    throw new DomainException(__('sales_ui.remainder.messages.stale_remainder'));
                }
                $declines[$line->getKey()] = [
                    'quantity' => $remaining,
                    'base_quantity' => $line->remainingDeliveryBaseQuantity(),
                    'delivered_quantity' => (string) $line->delivered_quantity,
                    'delivered_base_quantity' => (string) $line->delivered_base_quantity,
                ];
            }

            $creditPlan = $this->prepareCreditPlan($lockedOrder, $lockedLines, $declines);
            $productionOrders = $this->productionOrdersToShortClose($lockedLines);
            $beforeSnapshot = $this->beforeSnapshot($lockedOrder, $lockedLines, $declines, $creditPlan, $productionOrders);
            $numbers = $this->documents->nextForCompany(
                'sales_order_remainder_closures',
                SalesOrderRemainderClosure::class,
                (int) $lockedOrder->company_id,
            );
            $closure = SalesOrderRemainderClosure::query()->create([
                ...$numbers,
                'company_id' => $lockedOrder->company_id,
                'financial_period_id' => $period->getKey(),
                'branch_id' => $lockedOrder->branch_id,
                'sales_order_id' => $lockedOrder->getKey(),
                'closure_date' => $closureDate,
                'reason' => $reason,
                'status' => SalesOrderRemainderClosure::StatusApplying,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'before_snapshot' => $beforeSnapshot,
                'created_by' => auth()->id(),
            ]);

            $reservationEffects = $this->releaseReservations($lockedLines, $reason);
            $productionEffects = $this->shortCloseProduction($productionOrders, $lockedLines, $reason);

            foreach ($lockedLines as $line) {
                $decline = $declines[$line->getKey()];
                $line->update([
                    'declined_quantity' => bcadd((string) $line->declined_quantity, $decline['quantity'], 8),
                    'declined_base_quantity' => bcadd((string) $line->declined_base_quantity, $decline['base_quantity'], 8),
                ]);
            }

            $creditEffects = $this->applyCredits($closure, $creditPlan, $period->getKey(), $closureDate, $reason);
            $this->refreshIssueOrders($creditEffects, $reason);
            $fromStatus = $lockedOrder->status;
            $toStatus = $this->refreshOrderStatus($lockedOrder);

            foreach ($lockedLines as $line) {
                $line->refresh();
                $decline = $declines[$line->getKey()];
                $reservation = $reservationEffects[$line->getKey()] ?? ['quantity' => '0.00000000', 'base_quantity' => '0.00000000'];
                $production = $productionEffects[$line->getKey()] ?? ['quantity' => '0.00000000', 'base_quantity' => '0.00000000'];
                $credit = $creditEffects['by_order_line'][$line->getKey()] ?? ['quantity' => '0.00000000', 'base_quantity' => '0.00000000'];
                $closure->lines()->create([
                    'sales_order_line_id' => $line->getKey(),
                    'declined_quantity' => $decline['quantity'],
                    'declined_base_quantity' => $decline['base_quantity'],
                    'delivered_quantity_snapshot' => $decline['delivered_quantity'],
                    'delivered_base_quantity_snapshot' => $decline['delivered_base_quantity'],
                    'released_reservation_quantity' => $reservation['quantity'],
                    'released_reservation_base_quantity' => $reservation['base_quantity'],
                    'released_production_quantity' => $production['quantity'],
                    'released_production_base_quantity' => $production['base_quantity'],
                    'credited_remainder_quantity' => $credit['quantity'],
                    'credited_remainder_base_quantity' => $credit['base_quantity'],
                ]);
            }

            $effectSnapshot = [
                'order_status' => ['before' => $fromStatus, 'after' => $toStatus],
                'reservation_releases' => array_values($reservationEffects),
                'production_short_closes' => array_values($productionEffects),
                'credit_notes' => $creditEffects['credit_notes'],
                'lines' => $lockedLines->map(fn (SalesOrderLine $line): array => [
                    'sales_order_line_id' => (int) $line->getKey(),
                    'quantity' => (string) $line->quantity,
                    'delivered_quantity' => (string) $line->delivered_quantity,
                    'declined_quantity' => (string) $line->declined_quantity,
                    'effective_quantity' => $line->effectiveQuantity(),
                    'net_invoiced_quantity' => $line->netInvoicedQuantity(),
                ])->all(),
            ];
            $closure->update([
                'status' => SalesOrderRemainderClosure::StatusApplied,
                'effect_snapshot' => $effectSnapshot,
            ]);
            $this->audit->record($closure, 'sales_order.remainder_closed', [
                'sales_order' => $lockedOrder->doc_num,
                'reason' => $reason,
                'credit_notes' => array_column($creditEffects['credit_notes'], 'doc_num'),
            ]);

            return $closure->refresh()->load(['lines.orderLine', 'order']);
        }, 3);
    }

    /**
     * @param  Collection<int, SalesOrderLine>  $lines
     * @param  array<int, array{quantity: string, base_quantity: string, delivered_quantity: string, delivered_base_quantity: string}>  $declines
     * @return array<int, list<array{invoice: CustomerInvoice, invoice_line: CustomerInvoiceLine, quantity: string}>>
     */
    private function prepareCreditPlan(SalesOrder $order, Collection $lines, array $declines): array
    {
        $requiredByLine = [];
        foreach ($lines as $line) {
            $projectedEffective = bcsub($line->effectiveQuantity(), $declines[$line->getKey()]['quantity'], 8);
            $excess = bcsub($line->netInvoicedQuantity(), $projectedEffective, 8);
            if (bccomp($excess, '0', 8) > 0) {
                $requiredByLine[$line->getKey()] = $excess;
            }
        }
        if ($requiredByLine === []) {
            return [];
        }

        $invoices = CustomerInvoice::query()
            ->where('sales_order_id', $order->getKey())
            ->where('document_type', CustomerInvoice::TypeInvoice)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $invoiceLines = CustomerInvoiceLine::query()
            ->whereIn('sales_order_line_id', array_keys($requiredByLine))
            ->whereIn('customer_invoice_id', $invoices->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($invoiceLines->contains(function (CustomerInvoiceLine $line) use ($invoices): bool {
            $invoice = $invoices->get($line->customer_invoice_id);

            return ! $invoice instanceof CustomerInvoice
                || $invoice->posting_status !== 'posted'
                || $invoice->status !== CustomerInvoice::StatusPosted
                || $invoice->journal_entry_id === null;
        })) {
            throw new DomainException(__('sales_ui.remainder.messages.draft_invoice'));
        }
        $creditNotes = CustomerInvoice::query()
            ->whereIn('original_invoice_id', $invoices->keys())
            ->where('document_type', CustomerInvoice::TypeCreditNote)
            ->where('posting_status', 'posted')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($creditNotes->contains(fn (CustomerInvoice $credit): bool => $credit->source_type !== SalesOrderRemainderClosure::class)) {
            throw new DomainException(__('sales_ui.remainder.messages.conflicting_credit'));
        }
        $priorCreditLines = CustomerInvoiceLine::query()
            ->whereIn('customer_invoice_id', $creditNotes->pluck('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy(fn (CustomerInvoiceLine $line): string => (string) ($line->source_snapshot['original_invoice_line_public_id'] ?? ''));

        $plan = [];
        foreach ($requiredByLine as $orderLineId => $required) {
            $remaining = $required;
            $candidates = $invoiceLines->where('sales_order_line_id', $orderLineId)->sortByDesc('id');
            foreach ($candidates as $invoiceLine) {
                $invoice = $invoices->get($invoiceLine->customer_invoice_id);
                if (! $invoice instanceof CustomerInvoice
                    || $invoice->posting_status !== 'posted'
                    || $invoice->status !== CustomerInvoice::StatusPosted
                    || $invoice->journal_entry_id === null) {
                    continue;
                }
                $alreadyCredited = $priorCreditLines->get($invoiceLine->public_id, collect())
                    ->reduce(fn (string $sum, CustomerInvoiceLine $line): string => bcadd($sum, (string) $line->quantity, 8), '0.00000000');
                $available = bcsub((string) $invoiceLine->quantity, $alreadyCredited, 8);
                if (bccomp($available, '0', 8) <= 0) {
                    continue;
                }
                $quantity = bccomp($remaining, $available, 8) > 0 ? $available : $remaining;
                $plan[$invoice->getKey()][] = [
                    'invoice' => $invoice,
                    'invoice_line' => $invoiceLine,
                    'quantity' => $quantity,
                ];
                $remaining = bcsub($remaining, $quantity, 8);
                if (bccomp($remaining, '0', 8) === 0) {
                    break;
                }
            }
            if (bccomp($remaining, '0', 8) !== 0) {
                throw new DomainException(__('sales_ui.remainder.messages.credit_shortfall'));
            }
        }

        return $plan;
    }

    /** @param Collection<int, SalesOrderLine> $lines @return Collection<int, ProductionOrder> */
    private function productionOrdersToShortClose(Collection $lines): Collection
    {
        $selectedLineIds = $lines->pluck('id')->map(fn ($id): int => (int) $id);
        $orderIds = ProductionOrderLine::query()
            ->whereIn('sales_order_line_id', $selectedLineIds)
            ->orderBy('production_order_id')
            ->lockForUpdate()
            ->pluck('production_order_id')
            ->unique();
        if ($orderIds->isEmpty()) {
            return collect();
        }

        $orders = ProductionOrder::query()->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
        ProductionOrderLine::query()->whereIn('production_order_id', $orderIds)->orderBy('id')->lockForUpdate()->get();
        ProductionRun::query()->whereIn('production_order_id', $orderIds)->orderBy('id')->lockForUpdate()->get();
        $result = collect();
        foreach ($orders as $productionOrder) {
            if (in_array($productionOrder->status, [
                ProductionOrder::StatusCompleted,
                ProductionOrder::StatusCancelled,
                ProductionOrder::StatusShortClosed,
            ], true)) {
                continue;
            }
            $productionOrder->load(['lines', 'runs.requirements', 'runs.orderLine']);
            $hasUnproduced = $productionOrder->lines->contains(fn (ProductionOrderLine $line): bool => bccomp(
                (string) $line->base_quantity,
                (string) $line->received_base_quantity,
                8,
            ) > 0);
            if (! $hasUnproduced) {
                continue;
            }
            $unsafeLine = $productionOrder->lines->first(function (ProductionOrderLine $line) use ($selectedLineIds): bool {
                $unproduced = bcsub((string) $line->base_quantity, (string) $line->received_base_quantity, 8);

                return bccomp($unproduced, '0', 8) > 0
                    && ($line->sales_order_line_id === null || ! $selectedLineIds->contains((int) $line->sales_order_line_id));
            });
            $unsafeRun = $productionOrder->runs->first(function (ProductionRun $run) use ($selectedLineIds): bool {
                return ! in_array($run->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)
                    && ($run->production_order_line_id === null
                        || ! $selectedLineIds->contains((int) $run->orderLine?->sales_order_line_id));
            });
            if ($unsafeLine instanceof ProductionOrderLine || $unsafeRun instanceof ProductionRun) {
                throw new DomainException(__('sales_ui.remainder.messages.shared_production', [
                    'document' => $productionOrder->doc_num,
                ]));
            }
            $result->push($productionOrder);
        }

        return $result;
    }

    /** @param Collection<int, SalesOrderLine> $lines @return array<int, array<string, string|int>> */
    private function releaseReservations(Collection $lines, string $reason): array
    {
        $effects = [];
        $reservations = InventoryReservation::query()
            ->whereIn('sales_order_line_id', $lines->pluck('id'))
            ->where('status', InventoryReservation::StatusActive)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('sales_order_line_id');
        foreach ($lines as $line) {
            $releasedBase = '0.00000000';
            foreach ($reservations->get($line->getKey(), collect()) as $reservation) {
                $quantity = (string) $reservation->remaining_quantity;
                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }
                $reservation->update([
                    'released_quantity' => bcadd((string) $reservation->released_quantity, $quantity, 8),
                    'status' => InventoryReservation::StatusReleased,
                    'released_by' => auth()->id(),
                    'released_at' => now(),
                    'release_reason' => __('sales_ui.remainder.messages.reservation_release', ['reason' => $reason]),
                ]);
                $releasedBase = bcadd($releasedBase, $quantity, 8);
            }
            if (bccomp($releasedBase, '0', 8) > 0) {
                $storedBase = bcsub((string) $line->reserved_base_quantity, $releasedBase, 8);
                $storedBase = bccomp($storedBase, '0', 8) > 0 ? $storedBase : '0.00000000';
                $line->update([
                    'reserved_base_quantity' => $storedBase,
                    'reserved_quantity' => bcdiv($storedBase, (string) $line->conversion_factor, 8),
                ]);
                $effects[$line->getKey()] = [
                    'sales_order_line_id' => (int) $line->getKey(),
                    'quantity' => bcdiv($releasedBase, (string) $line->conversion_factor, 8),
                    'base_quantity' => $releasedBase,
                ];
            }
        }

        return $effects;
    }

    /**
     * @param  Collection<int, ProductionOrder>  $orders
     * @param  Collection<int, SalesOrderLine>  $lines
     * @return array<int, array<string, string|int>>
     */
    private function shortCloseProduction(Collection $orders, Collection $lines, string $reason): array
    {
        $before = $lines->mapWithKeys(fn (SalesOrderLine $line): array => [
            $line->getKey() => (string) $line->production_requested_base_quantity,
        ]);
        foreach ($orders as $order) {
            $this->production->shortCloseOrder($order, __('sales_ui.remainder.messages.production_release', ['reason' => $reason]));
        }
        $effects = [];
        foreach ($lines as $line) {
            $after = (string) $line->fresh()->production_requested_base_quantity;
            $releasedBase = bcsub((string) $before->get($line->getKey()), $after, 8);
            if (bccomp($releasedBase, '0', 8) > 0) {
                $effects[$line->getKey()] = [
                    'sales_order_line_id' => (int) $line->getKey(),
                    'quantity' => bcdiv($releasedBase, (string) $line->conversion_factor, 8),
                    'base_quantity' => $releasedBase,
                ];
            }
        }

        return $effects;
    }

    /**
     * @param  array<int, list<array{invoice: CustomerInvoice, invoice_line: CustomerInvoiceLine, quantity: string}>>  $plan
     * @return array{credit_notes: list<array<string, mixed>>, by_order_line: array<int, array{quantity: string, base_quantity: string}>}
     */
    private function applyCredits(
        SalesOrderRemainderClosure $closure,
        array $plan,
        int $financialPeriodId,
        string $postingDate,
        string $reason,
    ): array {
        $creditNotes = [];
        $byOrderLine = [];
        foreach ($plan as $invoiceId => $rows) {
            $invoice = CustomerInvoice::query()->with(['customer', 'paymentSchedules'])->lockForUpdate()->findOrFail($invoiceId);
            $prepared = [];
            foreach ($rows as $row) {
                $line = $row['invoice_line'];
                $quantity = $row['quantity'];
                $ratio = bcdiv($quantity, (string) $line->quantity, 16);
                $lineTotal = $this->amounts->round(bcmul((string) $line->line_total, $ratio, 16));
                $tax = $this->amounts->round(bcmul((string) $line->tax_amount, $ratio, 16));
                $discount = $this->amounts->round(bcmul((string) $line->discount_amount, $ratio, 16));
                $prepared[] = [
                    'line' => $line,
                    'quantity' => $quantity,
                    'base_quantity' => bcmul($quantity, (string) $line->conversion_factor, 8),
                    'line_total' => $lineTotal,
                    'tax' => $tax,
                    'discount' => $discount,
                ];
            }
            $total = $this->amounts->sum(array_column($prepared, 'line_total'));
            $tax = $this->amounts->sum(array_column($prepared, 'tax'));
            $discount = $this->amounts->sum(array_column($prepared, 'discount'));
            $net = $this->amounts->subtract($total, $tax);
            $numbers = $this->documents->nextForCompany(
                'customer_credit_notes',
                CustomerInvoice::class,
                (int) $invoice->company_id,
            );
            $credit = CustomerInvoice::query()->create([
                ...$numbers,
                'company_id' => $invoice->company_id,
                'financial_period_id' => $financialPeriodId,
                'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id,
                'sales_order_id' => $invoice->sales_order_id,
                'invoice_date' => $postingDate,
                'due_date' => $postingDate,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $invoice->exchange_rate,
                'subtotal_amount' => $this->amounts->add($net, $discount),
                'discount_amount' => $discount,
                'taxable_amount' => $net,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'remaining_amount' => '0.0000',
                'document_type' => CustomerInvoice::TypeCreditNote,
                'original_invoice_id' => $invoice->getKey(),
                'status' => CustomerInvoice::StatusDraft,
                'posting_status' => 'unposted',
                'source_type' => SalesOrderRemainderClosure::class,
                'source_id' => $closure->getKey(),
                'source_doc_num' => $closure->doc_num,
                'notes' => __('sales_ui.remainder.messages.financial_credit_note').' '.$reason,
                'created_by' => auth()->id(),
            ]);
            foreach ($prepared as $index => $row) {
                $line = $row['line'];
                $credit->lines()->create([
                    'sales_order_line_id' => $line->sales_order_line_id,
                    'delivery_line_id' => null,
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'line_number' => $index + 1,
                    'conversion_factor' => $line->conversion_factor,
                    'base_quantity' => $row['base_quantity'],
                    'description' => $line->description,
                    'quantity' => $row['quantity'],
                    'unit_price' => $line->unit_price,
                    'discount_amount' => $row['discount'],
                    'tax_amount' => $row['tax'],
                    'line_total' => $row['line_total'],
                    'is_service' => $line->is_service,
                    'unit_cost' => '0.00000000',
                    'source_snapshot' => [
                        ...($line->source_snapshot ?? []),
                        'original_invoice_line_public_id' => $line->public_id,
                        'sales_order_remainder_closure_id' => $closure->getKey(),
                        'financial_only' => true,
                    ],
                ]);
                if ($line->sales_order_line_id !== null) {
                    $orderLine = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
                    $orderLine->update([
                        'remainder_credited_quantity' => bcadd((string) $orderLine->remainder_credited_quantity, $row['quantity'], 8),
                        'remainder_credited_base_quantity' => bcadd((string) $orderLine->remainder_credited_base_quantity, $row['base_quantity'], 8),
                    ]);
                    $effect = $byOrderLine[$orderLine->getKey()] ?? ['quantity' => '0.00000000', 'base_quantity' => '0.00000000'];
                    $byOrderLine[$orderLine->getKey()] = [
                        'quantity' => bcadd($effect['quantity'], $row['quantity'], 8),
                        'base_quantity' => bcadd($effect['base_quantity'], $row['base_quantity'], 8),
                    ];
                }
            }

            $journal = $this->accounting->postCreditNote($credit->load(['customer', 'lines']));
            $outstandingBefore = (string) $invoice->remaining_amount;
            $applied = $this->amounts->compare($total, $outstandingBefore) > 0 ? $outstandingBefore : $total;
            if ($this->amounts->compare($applied, '0') < 0) {
                $applied = '0.0000';
            }
            $scheduleCredits = $this->applyCreditToSchedules($invoice, $applied);
            $invoice->update([
                'credited_amount' => $this->amounts->add($invoice->credited_amount, $applied),
                'remaining_amount' => $this->amounts->subtract($outstandingBefore, $applied),
            ]);
            $credit->update([
                'status' => CustomerInvoice::StatusPosted,
                'posting_status' => 'posted',
                'is_closed' => true,
                'journal_entry_id' => $journal->getKey(),
                'issued_by' => auth()->id(),
                'issued_at' => now(),
                'credit_available_amount' => $this->amounts->subtract($total, $applied),
                'credit_application_snapshot' => [
                    'original_invoice_id' => (int) $invoice->getKey(),
                    'applied_to_original' => $applied,
                    'schedules' => $scheduleCredits,
                    'sales_order_remainder_closure_id' => (int) $closure->getKey(),
                ],
            ]);
            $creditNotes[] = [
                'id' => (int) $credit->getKey(),
                'doc_num' => $credit->doc_num,
                'original_invoice_id' => (int) $invoice->getKey(),
                'total_amount' => $total,
                'applied_to_original' => $applied,
                'available_credit' => $this->amounts->subtract($total, $applied),
            ];
        }

        return ['credit_notes' => $creditNotes, 'by_order_line' => $byOrderLine];
    }

    /** @return list<array{schedule_id: int, amount: string}> */
    private function applyCreditToSchedules(CustomerInvoice $invoice, string $amount): array
    {
        $remaining = $amount;
        $applied = [];
        foreach ($invoice->paymentSchedules()->orderBy('due_date')->orderBy('id')->lockForUpdate()->get() as $schedule) {
            if ($this->amounts->compare($remaining, '0') <= 0) {
                break;
            }
            $credit = $this->amounts->compare($remaining, $schedule->outstanding_amount) > 0
                ? $schedule->outstanding_amount
                : $remaining;
            if ($this->amounts->compare($credit, '0') <= 0) {
                continue;
            }
            $schedule->increment('credited_amount', $credit);
            $applied[] = ['schedule_id' => (int) $schedule->getKey(), 'amount' => $credit];
            $remaining = $this->amounts->subtract($remaining, $credit);
        }
        if ($this->amounts->compare($remaining, '0') !== 0) {
            throw new DomainException(__('sales_ui.remainder.messages.schedule_shortfall'));
        }

        return $applied;
    }

    /** @param array{credit_notes: list<array<string, mixed>>, by_order_line: array<int, array{quantity: string, base_quantity: string}>} $effects */
    private function refreshIssueOrders(array $effects, string $reason): void
    {
        foreach ($effects['credit_notes'] as $creditEffect) {
            $invoice = CustomerInvoice::query()->with(['lines', 'deliveries.lines', 'creditNotes.lines'])->findOrFail($creditEffect['original_invoice_id']);
            $issueOrder = $invoice->issueOrder()->lockForUpdate()->first();
            if ($issueOrder instanceof SalesIssueOrder
                && $issueOrder->status === SalesIssueOrder::StatusPending
                && $this->issueOrders->remainingLines($invoice) === []) {
                $issueOrder->update([
                    'status' => SalesIssueOrder::StatusShortClosed,
                    'short_close_reason' => $reason,
                    'short_closed_by' => auth()->id(),
                    'short_closed_at' => now(),
                ]);
            }
        }
    }

    private function refreshOrderStatus(SalesOrder $order): string
    {
        $order->load('lines');
        $complete = $order->lines->every(function (SalesOrderLine $line): bool {
            $accepted = $line->isService() ? $line->netInvoicedQuantity() : (string) $line->delivered_quantity;

            return bccomp(bcadd($accepted, (string) $line->declined_quantity, 8), (string) $line->quantity, 8) >= 0;
        });
        $hasDecline = $order->lines->contains(fn (SalesOrderLine $line): bool => bccomp((string) $line->declined_quantity, '0', 8) > 0);
        $hasFulfillment = $order->lines->contains(fn (SalesOrderLine $line): bool => bccomp((string) $line->delivered_quantity, '0', 8) > 0
            || bccomp($line->netInvoicedQuantity(), '0', 8) > 0);
        $status = $complete && $hasDecline
            ? SalesOrder::StatusClosed
            : ($complete ? SalesOrder::StatusFulfilled : ($hasFulfillment ? SalesOrder::StatusPartiallyFulfilled : SalesOrder::StatusApproved));
        $from = $order->status;
        if ($from !== $status) {
            $order->update(['status' => $status, 'updated_by' => auth()->id()]);
            $order->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $status,
                'reason' => __('sales_ui.remainder.messages.status_history'),
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);
        }

        return $status;
    }

    /**
     * @param  Collection<int, SalesOrderLine>  $lines
     * @param  array<int, array<string, string>>  $declines
     * @param  array<int, list<array{invoice: CustomerInvoice, invoice_line: CustomerInvoiceLine, quantity: string}>>  $creditPlan
     * @param  Collection<int, ProductionOrder>  $productionOrders
     * @return array<string, mixed>
     */
    private function beforeSnapshot(
        SalesOrder $order,
        Collection $lines,
        array $declines,
        array $creditPlan,
        Collection $productionOrders,
    ): array {
        return [
            'order' => ['id' => (int) $order->getKey(), 'doc_num' => $order->doc_num, 'status' => $order->status],
            'lines' => $lines->map(fn (SalesOrderLine $line): array => [
                'id' => (int) $line->getKey(), 'public_id' => $line->public_id,
                'quantity' => (string) $line->quantity, 'base_quantity' => (string) $line->base_quantity,
                'delivered_quantity' => (string) $line->delivered_quantity,
                'invoiced_quantity' => (string) $line->invoiced_quantity,
                'declined_quantity' => (string) $line->declined_quantity,
                'remainder_credited_quantity' => (string) $line->remainder_credited_quantity,
                'closing_quantity' => $declines[$line->getKey()]['quantity'],
            ])->all(),
            'credit_plan' => collect($creditPlan)->flatten(1)->map(fn (array $row): array => [
                'invoice_id' => (int) $row['invoice']->getKey(),
                'invoice_line_id' => (int) $row['invoice_line']->getKey(),
                'quantity' => $row['quantity'],
            ])->values()->all(),
            'production_orders_to_short_close' => $productionOrders->map(fn (ProductionOrder $productionOrder): array => [
                'id' => (int) $productionOrder->getKey(), 'doc_num' => $productionOrder->doc_num,
            ])->all(),
        ];
    }
}
