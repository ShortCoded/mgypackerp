<?php

namespace Modules\Sales\Services;

use App\Services\DocumentOwnerEffectProofService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Production\Models\ProductionOrder;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\CustomerInvoice;
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
        private readonly OperatingContextService $operatingContext,
        private readonly FinancialPeriodService $periods,
    ) {}

    /** @param array{company_id: int, financial_period_id: int, branch_id: int, branch_store_id?: int|null} $context */
    public function createFromQuotation(Quotation $quotation, array $context, ?array $selection = null): SalesOrder
    {
        return DB::transaction(function () use ($quotation, $context, $selection): SalesOrder {
            Company::query()->whereKey($quotation->company_id)->lockForUpdate()->firstOrFail();
            $locked = Quotation::query()
                ->with(['currentRevision.lines.product', 'currentRevision.lines.unit', 'currentRevision.paymentMilestones'])
                ->lockForUpdate()
                ->findOrFail($quotation->getKey());
            $revision = $locked->currentRevision;

            if ($locked->status !== Quotation::StatusAccepted || ! $revision instanceof QuotationRevision || $locked->current_revision_id !== $revision->getKey()) {
                throw new DomainException(__('Only the accepted current quotation revision can be converted.'));
            }
            if ((int) $locked->company_id !== $context['company_id'] || (int) $locked->branch_id !== $context['branch_id']) {
                throw new DomainException(__('Switch to the quotation operating company and branch before conversion.'));
            }
            if ($locked->valid_until?->isBefore(now()->startOfDay())) {
                throw new DomainException(__('The accepted quotation has expired and must be revised before conversion.'));
            }
            if (! $locked->customer_id || ! $locked->currency_id || $revision->lines->isEmpty()) {
                throw new DomainException(__('The quotation requires a customer, currency, and at least one sales line.'));
            }

            $branchStoreId = null;
            if (! empty($context['branch_store_id'])) {
                $branchStoreId = BranchStore::query()
                    ->where('branch_id', $context['branch_id'])
                    ->findOrFail((int) $context['branch_store_id'])
                    ->getKey();
            }

            $requestedDate = $revision->lines->pluck('requested_date')->filter()->max();
            $expectedDeliveryDate = $requestedDate?->toDateString() ?? $locked->valid_until?->toDateString() ?? now()->toDateString();
            if ($expectedDeliveryDate < now()->toDateString()) {
                $expectedDeliveryDate = now()->toDateString();
            }

            $reconciledCancelledOrders = $this->reconciledCancelledQuotationOrders($locked);
            $converted = SalesOrderLine::query()->whereNotIn('sales_order_id', $reconciledCancelledOrders)->whereIn('quotation_revision_line_id', $revision->lines->modelKeys())
                ->selectRaw('quotation_revision_line_id, sum(quantity) as quantity, sum(discount_amount) as discount, sum(header_discount_amount) as header_discount, sum(tax_amount) as tax, sum(line_total + discount_amount - tax_amount) as gross')
                ->groupBy('quotation_revision_line_id')->get()->keyBy('quotation_revision_line_id');
            $requested = $selection === null ? null : collect($selection)->keyBy('public_id');
            if ($requested !== null && ($requested->count() !== count($selection) || $requested->keys()->diff($revision->lines->pluck('public_uuid'))->isNotEmpty())) {
                throw new DomainException(__('Select each source line once.'));
            }
            $convertedLines = [];
            foreach ($this->quotationLines($revision) as $line) {
                $source = $revision->lines->firstWhere('id', $line['quotation_revision_line_id']);
                $prior = $converted->get($source->id);
                $remaining = bcsub((string) $source->quantity, (string) ($prior?->quantity ?? 0), 8);
                $quantity = $requested === null ? $remaining : (string) ($requested->get($source->public_uuid)['quantity'] ?? '0');
                if (bccomp($quantity, '0', 8) === 0) {
                    continue;
                }
                $this->amounts->assertPositive($quantity, __('Converted quantity must be positive.'));
                $this->amounts->assertNotGreaterThan($quantity, $remaining, __('Converted quantity exceeds the remaining quotation quantity.'));
                $ratio = bcdiv($quantity, (string) $source->quantity, 12);
                $final = bccomp($quantity, $remaining, 8) === 0;
                $share = [];
                foreach (['discount' => $line['discount_amount'], 'header_discount' => $line['header_discount_amount'],
                    'tax' => $line['tax_amount'], 'gross' => $this->amounts->unitPriceTotal($source->quantity, $source->unit_price)] as $key => $total) {
                    $remainingAmount = $this->amounts->subtract((string) $total, (string) ($prior?->{$key} ?? 0));
                    $slice = $key === 'gross' ? $this->amounts->unitPriceTotal($quantity, $source->unit_price) : $this->amounts->multiply((string) $total, $ratio);
                    $share[$key] = $final || bccomp($slice, $remainingAmount, 4) > 0 ? $remainingAmount : $slice;
                    if (bccomp($share[$key], '0', 4) < 0) {
                        throw new DomainException(__('sales_ui.invoice_source_tax_locked'));
                    }
                }
                $convertedLines[] = [...$line, 'quantity' => $quantity,
                    'discount_value' => $line['discount_type'] === 'fixed' ? $this->amounts->subtract($share['discount'], $share['header_discount']) : $line['discount_value'],
                    'header_discount_amount' => $share['header_discount'], 'discount_amount' => $share['discount'],
                    'tax_amount' => $share['tax'], 'line_total' => $this->amounts->add($this->amounts->subtract($share['gross'], $share['discount']), $share['tax'])];
            }
            if ($convertedLines === []) {
                throw new DomainException(__('This quotation revision was already converted.'));
            }
            $period = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $locked->company_id, now()->toDateString(), lockForUpdate: true);

            return $this->create([
                ...$context,
                'financial_period_id' => $period->id,
                'sales_request_id' => $locked->sales_request_id,
                'quotation_id' => $locked->getKey(),
                'quotation_revision_id' => $revision->getKey(),
                'customer_id' => $locked->customer_id,
                'business_employee_id' => $locked->business_employee_id,
                'currency_id' => $locked->currency_id,
                'branch_store_id' => $branchStoreId,
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
                'discount_type' => $revision->discount_type,
                'discount_value' => $revision->discount_type === 'fixed' ? $this->amounts->sum(array_column($convertedLines, 'header_discount_amount')) : $revision->discount_value,
                'header_discount_amount' => $this->amounts->sum(array_column($convertedLines, 'header_discount_amount')),
                'lines' => $convertedLines,
                'payment_schedules' => $selection === null && $converted->isEmpty() ? $this->quotationPaymentSchedules($revision, $expectedDeliveryDate) : [],
            ], preserveSourceDiscounts: true);
        });
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, bool $preserveSourceDiscounts = false): SalesOrder
    {
        return DB::transaction(function () use ($data, $preserveSourceDiscounts): SalesOrder {
            Company::query()->whereKey($data['company_id'])->lockForUpdate()->firstOrFail();
            $this->assertRequiredContext($data);
            $lines = $this->validatedLines($data['lines'] ?? [], (int) $data['company_id']);
            if ($preserveSourceDiscounts) {
                foreach ($lines as $index => &$line) {
                    if (isset($data['lines'][$index]['line_total'])) {
                        $line['line_total'] = $data['lines'][$index]['line_total'];
                    }
                }
                unset($line);
            }
            $discounts = $preserveSourceDiscounts ? ['lines' => $lines] : app(SalesOrderDiscountService::class)->calculate($lines, $data['discount_type'] ?? null, $data['discount_value'] ?? '0');
            $lines = $discounts['lines'];
            $data = [...$data, ...collect($discounts)->except('lines')->all()];
            $totals = $this->totals($lines);
            $totals = [...$totals, ...app(SalesWithholdingService::class)->calculate($totals['total_amount'], $data['withholding_rate'] ?? '0', $data['withholding_basis'] ?? null, $this->amounts->subtract($totals['subtotal_amount'], $totals['discount_amount']))];
            $agreement = CustomerCommercialAgreement::query()
                ->where('company_id', $data['company_id'])->where('customer_id', $data['customer_id'])
                ->where(fn ($query) => $query->whereNull('currency_id')->orWhere('currency_id', $data['currency_id'] ?? null))
                ->effective((string) $data['order_date'])->orderByRaw('currency_id is null')->latest('effective_from')->first();
            $requiredAdvance = $this->requiredAdvance($agreement, $totals['total_amount']);
            $numbers = $this->documents->nextForCompany(
                'sales_orders', SalesOrder::class, (int) $data['company_id'],
            );

            $order = SalesOrder::query()->create([
                ...collect($data)->except(['lines', 'payment_schedules', 'doc_number', 'doc_num'])->all(),
                ...$numbers,
                ...$totals,
                'agreement_snapshot' => $this->creditControl->snapshotFor($agreement, (int) $data['company_id'], (int) $data['customer_id'], isset($data['currency_id']) ? (int) $data['currency_id'] : null),
                'credit_limit_snapshot' => $this->creditControl->snapshotFor($agreement, (int) $data['company_id'], (int) $data['customer_id'], isset($data['currency_id']) ? (int) $data['currency_id'] : null)['credit_limit'],
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
                throw new DomainException(__('Released sales orders must be reopened before amendment.'));
            }
            Gate::authorize('sales_orders.edit');
            $this->assertReopenContext($locked);
            foreach (['company_id', 'branch_id', 'financial_period_id'] as $field) {
                if (isset($data[$field]) && (int) $data[$field] !== (int) $locked->{$field}) {
                    throw new DomainException(__('The document is outside the active operating context.'));
                }
            }
            if (($locked->reopened_at !== null && ! isset($data['amendment_token']))
                || (isset($data['amendment_token']) && ! hash_equals($locked->amendmentToken(), (string) $data['amendment_token']))) {
                throw new DomainException(__('sales_ui.amendment_stale'));
            }
            unset($data['amendment_token']);
            $data = collect($data)->except(['id', 'public_id', 'status', 'approved_at', 'approved_by',
                'reopened_at', 'reopened_by', 'reopen_reason', 'reopen_snapshot', 'cancelled_at', 'cancelled_by',
                'cancel_reason', 'created_by', 'created_at', 'deleted_by', 'deleted_at'])->all();
            app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $locked->company_id, $locked->order_date, (int) $locked->financial_period_id, lockForUpdate: true);

            $data['customer_id'] = $locked->customer_id;
            $data['currency_id'] = $locked->currency_id;
            $data['exchange_rate'] = $locked->exchange_rate;
            $lines = $this->validatedLines($data['lines'] ?? [], (int) $locked->company_id);
            $currentLines = $locked->lines()->lockForUpdate()->get();
            foreach ($lines as $index => &$line) {
                $input = $data['lines'][$index];
                $source = $currentLines->firstWhere('public_id', $input['public_id'] ?? '');
                if (! $source instanceof SalesOrderLine) {
                    continue;
                }
                $sameTerms = (int) $source->product_id === (int) $line['product_id'] && (int) $source->unit_id === (int) $line['unit_id']
                    && bccomp($source->quantity, (string) $line['quantity'], 8) === 0
                    && bccomp($source->unit_price, (string) $line['unit_price'], 8) === 0
                    && (! array_key_exists('discount_type', $input) || (($input['discount_type'] ?? null) === ($source->discount_type ?? 'fixed')
                        && bccomp((string) ($input['discount_value'] ?? 0), (string) ($source->discount_value ?? $this->amounts->subtract($source->discount_amount, $source->header_discount_amount ?? '0')), 4) === 0))
                    && (! array_key_exists('discount_type', $data) || (($data['discount_type'] ?? null) === $locked->discount_type
                        && bccomp((string) ($data['discount_value'] ?? 0), (string) ($locked->discount_value ?? 0), 4) === 0));
                $submittedRate = filled($input['tax_rate'] ?? null) ? app(SalesTaxService::class)->rate($input['tax_rate']) : null;
                if ($submittedRate === null) {
                    if ($source->tax_rate === null && bccomp($source->tax_amount, '0', 4) > 0 && ! $sameTerms
                        && ! array_key_exists('tax_amount', $input) && ! $locked->canAppendProductionAmendment()) {
                        throw new DomainException(__('sales_ui.legacy_tax_rate_required'));
                    }
                    $line['tax_rate'] = $source->tax_rate;
                    $line['tax_calculation_basis'] = $source->tax_calculation_basis;
                    $line['tax_amount'] = $input['tax_amount'] ?? $source->tax_amount;
                } elseif ($sameTerms && $source->tax_rate !== null && bccomp($submittedRate, $source->tax_rate, 4) === 0) {
                    $line['tax_rate'] = $source->tax_rate;
                    $line['tax_calculation_basis'] = $source->tax_calculation_basis;
                    $line['tax_amount'] = $source->tax_amount;
                } else {
                    $line['tax_rate'] = $submittedRate;
                    $line['tax_calculation_basis'] = SalesTaxService::Rate;
                }
            }
            unset($line);
            if (array_key_exists('discount_type', $data) && $locked->canAppendProductionAmendment() && ! $locked->canReplaceUnexecutedLines()
                && (($data['discount_type'] ?? null) !== $locked->discount_type || bccomp((string) ($data['discount_value'] ?? 0), (string) ($locked->discount_value ?? 0), 4) !== 0)) {
                throw new DomainException(__('sales_ui.production_amendment_locked_terms'));
            }
            $replaceUnexecutedLines = $locked->canReplaceUnexecutedLines();
            if ($locked->canAppendProductionAmendment() && ! $replaceUnexecutedLines) {
                if (array_key_exists('withholding_rate', $data) && bccomp((string) ($data['withholding_rate'] ?? 0), (string) ($locked->withholding_rate ?? 0), 4) !== 0) {
                    throw new DomainException(__('sales_ui.production_amendment_locked_terms'));
                }

                return $this->updateProductionAmendment($locked, $data, $lines, $currentLines);
            }
            if (! $replaceUnexecutedLines) {
                throw new DomainException(__('sales_ui.line_correction_execution_blocked'));
            }
            $existingByPublicId = $currentLines->keyBy('public_id');
            $seen = [];
            foreach ($lines as $index => &$line) {
                $publicId = $data['lines'][$index]['public_id'] ?? null;
                $source = $publicId !== null ? $existingByPublicId->get($publicId) : null;
                if ($publicId !== null && (! $source instanceof SalesOrderLine || isset($seen[$publicId]))) {
                    throw new DomainException(__('sales_ui.production_amendment_line_identity'));
                }
                if ($source instanceof SalesOrderLine) {
                    $seen[$publicId] = true;
                    if (! array_key_exists('discount_type', $data['lines'][$index]) && ($source->discount_type !== null || bccomp((string) $source->header_discount_amount, '0', 4) > 0)) {
                        $line['discount_type'] = $source->discount_type ?? 'fixed';
                        $line['discount_value'] = $source->discount_value ?? $this->amounts->subtract($source->discount_amount, $source->header_discount_amount);
                    }
                    if ((int) $source->product_id !== (int) $line['product_id'] && $line['description'] === $source->description) {
                        $line['description'] = Product::query()->forCompany((int) $locked->company_id)->findOrFail($line['product_id'])->name;
                    }
                }
                $line['sales_request_line_id'] = $source?->sales_request_line_id;
                $line['quotation_revision_line_id'] = $source?->quotation_revision_line_id;
            }
            unset($line);
            $discountType = array_key_exists('discount_type', $data) ? $data['discount_type'] : $locked->discount_type;
            $discountValue = $data['discount_value'] ?? $locked->discount_value ?? '0';
            $preserveQuotationDiscounts = $locked->quotation_id !== null && $currentLines->count() === count($lines)
                && $discountType === $locked->discount_type && bccomp((string) $discountValue, (string) ($locked->discount_value ?? 0), 4) === 0;
            foreach ($lines as $index => $line) {
                $source = $existingByPublicId->get($data['lines'][$index]['public_id'] ?? '');
                $preserveQuotationDiscounts = $preserveQuotationDiscounts && $source instanceof SalesOrderLine
                    && (int) $source->product_id === (int) $line['product_id'] && (int) $source->unit_id === (int) $line['unit_id']
                    && bccomp((string) $source->quantity, (string) $line['quantity'], 8) === 0
                    && bccomp((string) $source->unit_price, (string) $line['unit_price'], 8) === 0
                    && ($source->discount_type ?? 'fixed') === ($line['discount_type'] ?? 'fixed')
                    && bccomp((string) ($source->discount_value ?? 0), (string) ($line['discount_value'] ?? 0), 4) === 0
                    && $source->tax_rate === ($line['tax_rate'] ?? null)
                    && $source->tax_calculation_basis === ($line['tax_calculation_basis'] ?? SalesTaxService::LegacyAmount)
                    && bccomp((string) $source->tax_amount, (string) $line['tax_amount'], 4) === 0;
            }
            if ($preserveQuotationDiscounts) {
                foreach ($lines as $index => &$line) {
                    $source = $existingByPublicId->get($data['lines'][$index]['public_id']);
                    $line['discount_type'] = $source->discount_type;
                    $line['discount_value'] = $source->discount_value;
                    $line['discount_amount'] = $source->discount_amount;
                    $line['header_discount_amount'] = $source->header_discount_amount;
                    $line['line_total'] = $source->line_total;
                }
                unset($line);
                $discounts = ['lines' => $lines, 'discount_type' => $locked->discount_type, 'discount_value' => $locked->discount_value, 'header_discount_amount' => $locked->header_discount_amount];
            } else {
                foreach ($lines as &$line) {
                    if ($line['tax_rate'] !== null) {
                        $line['tax_calculation_basis'] = SalesTaxService::Rate;
                    }
                }
                unset($line);
                $discounts = app(SalesOrderDiscountService::class)->calculate($lines, $discountType, $discountValue);
                foreach ($discounts['lines'] as $index => $line) {
                    $source = $existingByPublicId->get($data['lines'][$index]['public_id'] ?? '');
                    if ($source instanceof SalesOrderLine && $line['tax_rate'] === null && bccomp($source->tax_amount, '0', 4) > 0
                        && ! array_key_exists('tax_amount', $data['lines'][$index])
                        && bccomp($this->amounts->subtract($line['line_total'], $line['tax_amount']), $this->amounts->subtract($source->line_total, $source->tax_amount), 4) !== 0) {
                        throw new DomainException(__('sales_ui.legacy_tax_rate_required'));
                    }
                }
            }
            $lines = $discounts['lines'];
            $data = [...$data, ...collect($discounts)->except('lines')->all()];
            $totals = $this->totals($lines);
            $totals = [...$totals, ...app(SalesWithholdingService::class)->calculate($totals['total_amount'], $data['withholding_rate'] ?? $locked->withholding_rate ?? '0', $data['withholding_basis'] ?? $locked->withholding_basis, $this->amounts->subtract($totals['subtotal_amount'], $totals['discount_amount']))];
            $sameLines = $currentLines->count() === count($lines) && $currentLines->values()->every(fn (SalesOrderLine $line, int $index): bool => ! (clone $line)->fill($lines[$index])->isDirty());
            $currentSchedules = $locked->paymentSchedules()->get();
            $inputSchedules = $data['payment_schedules'] ?? [];
            $sameSchedules = $currentSchedules->count() === count($inputSchedules) && $currentSchedules->values()->every(fn ($schedule, int $index): bool => ! (clone $schedule)->fill($inputSchedules[$index])->isDirty());
            $headerValues = collect($data)->except(['lines', 'payment_schedules', 'doc_number', 'doc_num', 'company_id', 'financial_period_id'])->all();
            if ($sameLines && $sameSchedules && ! (clone $locked)->fill([...$headerValues, ...$totals])->isDirty()) {
                return $locked->load(['lines.product', 'paymentSchedules']);
            }
            $agreement = CustomerCommercialAgreement::query()
                ->where('company_id', $locked->company_id)->where('customer_id', $data['customer_id'])
                ->where(fn ($query) => $query->whereNull('currency_id')->orWhere('currency_id', $data['currency_id'] ?? null))
                ->effective((string) $data['order_date'])->orderByRaw('currency_id is null')->latest('effective_from')->first();
            $locked->update([
                ...collect($data)->except(['lines', 'payment_schedules', 'doc_number', 'doc_num', 'company_id', 'financial_period_id'])->all(),
                ...$totals,
                'agreement_snapshot' => $this->creditControl->snapshotFor($agreement, (int) ($data['company_id'] ?? $locked->company_id), (int) $data['customer_id'], isset($data['currency_id']) ? (int) $data['currency_id'] : null),
                'credit_limit_snapshot' => $this->creditControl->snapshotFor($agreement, (int) ($data['company_id'] ?? $locked->company_id), (int) $data['customer_id'], isset($data['currency_id']) ? (int) $data['currency_id'] : null)['credit_limit'],
                'required_advance_amount' => $this->requiredAdvance($agreement, $totals['total_amount']),
                'updated_by' => auth()->id(),
            ]);
            $beforeLines = $currentLines->map->attributesToArray()->all();
            $lineNumberOffset = (int) $currentLines->max('line_number') + count($lines) + 1;
            $locked->lines()->increment('line_number', $lineNumberOffset);
            foreach ($currentLines as $source) {
                $source->forceFill(['line_number' => $source->line_number + $lineNumberOffset])->syncOriginalAttribute('line_number');
            }
            $retainedLineIds = [];
            foreach ($lines as $index => $line) {
                $publicId = $data['lines'][$index]['public_id'] ?? null;
                $source = $publicId !== null ? $existingByPublicId->get($publicId) : null;
                if ($source instanceof SalesOrderLine) {
                    $source->update([...$line, 'line_number' => $index + 1]);
                    $retainedLineIds[] = $source->getKey();
                } else {
                    $retainedLineIds[] = $locked->lines()->create([...$line, 'line_number' => $index + 1])->getKey();
                }
            }
            $locked->lines()->whereNotIn('id', $retainedLineIds)->delete();
            $locked->paymentSchedules()->delete();
            $this->syncPaymentSchedules($locked, $data['payment_schedules'] ?? []);
            $this->audit->record($locked, 'sales_order.amended', [
                'status' => $locked->status,
                'total_amount' => $locked->total_amount,
                'before_lines' => $beforeLines,
                'after_lines' => $locked->lines()->get()->map->attributesToArray()->all(),
            ]);

            return $locked->refresh()->load(['lines.product', 'paymentSchedules']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, SalesOrderLine>  $currentLines
     */
    private function updateProductionAmendment(SalesOrder $order, array $data, array $lines, Collection $currentLines): SalesOrder
    {
        if (! $order->canAppendProductionAmendment()
            || Carbon::parse($data['order_date'])->toDateString() !== $order->order_date->toDateString()
            || (int) ($data['branch_store_id'] ?? 0) !== (int) ($order->branch_store_id ?? 0)) {
            throw new DomainException(__('sales_ui.production_amendment_context'));
        }

        $existingByPublicId = $currentLines->keyBy('public_id');
        $beforeSnapshot = $currentLines->map->attributesToArray()->all();
        $seen = [];
        $totalsLines = [];
        $nextLineNumber = (int) $currentLines->max('line_number');
        foreach ($lines as $index => $line) {
            $publicId = $data['lines'][$index]['public_id'] ?? null;
            if ($publicId !== null) {
                $source = $existingByPublicId->get($publicId);
                if (! $source instanceof SalesOrderLine || isset($seen[$publicId])) {
                    throw new DomainException(__('sales_ui.production_amendment_line_identity'));
                }
                $seen[$publicId] = true;
                if (array_key_exists('discount_type', $line) && ($line['discount_type'] !== $source->discount_type || bccomp((string) ($line['discount_value'] ?? 0), (string) ($source->discount_value ?? 0), 4) !== 0)) {
                    throw new DomainException(__('sales_ui.production_amendment_locked_terms'));
                }
                if ($source->tax_rate !== ($line['tax_rate'] ?? null) || $source->tax_calculation_basis !== ($line['tax_calculation_basis'] ?? SalesTaxService::LegacyAmount)) {
                    throw new DomainException(__('sales_ui.production_amendment_locked_terms'));
                }
                foreach (['product_id', 'unit_id', 'description', 'unit_price', 'discount_amount', 'tax_amount', 'conversion_factor'] as $field) {
                    $same = in_array($field, ['unit_price', 'conversion_factor'], true)
                        ? bccomp((string) $line[$field], (string) $source->{$field}, 8) === 0
                        : (in_array($field, ['discount_amount', 'tax_amount'], true)
                            ? bccomp((string) $line[$field], (string) $source->{$field}, 4) === 0
                            : (string) $line[$field] === (string) $source->{$field});
                    if (! $same) {
                        throw new DomainException(__('sales_ui.production_amendment_locked_terms'));
                    }
                }
                $committedQuantity = $this->committedLineQuantity($source);
                if (bccomp((string) $line['quantity'], $committedQuantity, 8) < 0) {
                    throw new DomainException(__('sales_ui.amendment_below_commitment', [
                        'line' => $source->line_number,
                        'quantity' => app(NumericFormatService::class)->format($committedQuantity),
                    ]));
                }
                if (bccomp((string) $line['quantity'], (string) $source->quantity, 8) !== 0) {
                    if ($source->tax_rate !== null && bccomp($source->tax_rate, '0', 4) > 0) {
                        throw new DomainException(__('sales_ui.production_amendment_locked_terms'));
                    }
                    $line['line_total'] = $this->amounts->add($this->amounts->subtract($this->amounts->unitPriceTotal($line['quantity'], $line['unit_price']), $line['discount_amount']), $line['tax_amount']);
                    $source->update([
                        'quantity' => $line['quantity'],
                        'base_quantity' => $line['base_quantity'],
                        'line_total' => $line['line_total'],
                    ]);
                } else {
                    $line['line_total'] = $source->line_total;
                }
                $line['header_discount_amount'] = $source->header_discount_amount;
            } else {
                if (filled($line['discount_type'] ?? null) || bccomp((string) ($line['discount_value'] ?? 0), '0', 4) > 0) {
                    throw new DomainException(__('sales_ui.production_amendment_locked_terms'));
                }
                $line = app(SalesOrderDiscountService::class)->calculate([$line], null, '0')['lines'][0];
                $nextLineNumber++;
                $order->lines()->create([...$line, 'line_number' => $nextLineNumber]);
            }
            $totalsLines[] = $line;
        }
        if (count($seen) !== $currentLines->count()) {
            throw new DomainException(__('sales_ui.production_amendment_preserve_lines'));
        }

        $totals = $this->totals($totalsLines);
        $agreement = CustomerCommercialAgreement::query()
            ->where('company_id', $order->company_id)->where('customer_id', $order->customer_id)
            ->where(fn ($query) => $query->whereNull('currency_id')->orWhere('currency_id', $order->currency_id))
            ->effective($order->order_date->toDateString())->orderByRaw('currency_id is null')->latest('effective_from')->first();
        $agreementSnapshot = $this->creditControl->snapshotFor($agreement, (int) $order->company_id, (int) $order->customer_id, (int) $order->currency_id);
        $order->update([
            ...$totals,
            'agreement_snapshot' => $agreementSnapshot,
            'credit_limit_snapshot' => $agreementSnapshot['credit_limit'],
            'required_advance_amount' => $this->requiredAdvance($agreement, $totals['total_amount']),
            'expected_delivery_date' => $data['expected_delivery_date'] ?? $order->expected_delivery_date,
            'notes' => $data['notes'] ?? $order->notes,
            'internal_notes' => $data['internal_notes'] ?? $order->internal_notes,
            'updated_by' => auth()->id(),
        ]);
        $order->paymentSchedules()->delete();
        $this->syncPaymentSchedules($order, $data['payment_schedules'] ?? []);
        $this->audit->record($order, 'sales_order.amended', [
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'preserved_source_line_ids' => $currentLines->modelKeys(),
            'before_lines' => $beforeSnapshot,
            'after_lines' => $order->lines()->get()->map->attributesToArray()->all(),
        ]);

        return $order->refresh()->load(['lines.product', 'paymentSchedules']);
    }

    private function committedLineQuantity(SalesOrderLine $line): string
    {
        $productionBase = (string) $line->productionLines()
            ->whereHas('order', fn ($query) => $query->where('status', '!=', ProductionOrder::StatusCancelled))
            ->sum('base_quantity');
        $productionQuantity = bcdiv($productionBase, (string) $line->conversion_factor, 8);
        $invoiceQuantity = (string) $line->invoiceLines()
            ->whereHas('invoice', fn ($query) => $query
                ->where('document_type', CustomerInvoice::TypeInvoice)
                ->where('status', '!=', CustomerInvoice::StatusCancelled))
            ->sum('quantity');
        $quantities = [
            $line->activeReservedQuantity(), $line->production_requested_quantity,
            $line->produced_quantity, $line->delivered_quantity, $line->invoiced_quantity,
            $productionQuantity, $invoiceQuantity,
        ];

        return collect($quantities)->reduce(
            fn (string $maximum, mixed $quantity): string => bccomp((string) ($quantity ?? '0'), $maximum, 8) > 0 ? (string) $quantity : $maximum,
            '0.00000000',
        );
    }

    public function delete(SalesOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $record = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertReopenContext($record);
            if ($record->status === SalesOrder::StatusReopened && $record->isEditable() && $record->canCancelSafely()) {
                Gate::authorize('sales_orders.cancel');
                $record = $this->cancel($record, __('cancellation_review.archive_reopened_reason'));
                $record->delete();
                $this->audit->record($record, 'sales_order.deleted', ['archive_after_cancel' => true]);

                return;
            }
            if ($record->status !== SalesOrder::StatusDraft || $record->approved_at !== null || $record->reopened_at !== null
                || $record->quotation_id || $record->sales_request_id
                || $record->invoices()->withTrashed()->exists() || $record->deliveries()->exists()
                || $record->productionOrders()->exists() || $record->receipts()->withTrashed()->exists()
                || InventoryReservation::query()->where('sales_order_id', $record->id)->exists()) {
                throw new DomainException(__('Only unused drafts can be deleted.'));
            }
            $record->delete();
            $this->audit->record($record, 'sales_order.deleted');
        });
    }

    public function restore(SalesOrder $order): SalesOrder
    {
        return DB::transaction(function () use ($order): SalesOrder {
            $record = SalesOrder::onlyTrashed()->lockForUpdate()->findOrFail($order->id);
            $this->assertReopenContext($record);
            if ($record->status === SalesOrder::StatusCancelled
                && DB::table('activity_log')->where('company_id', $record->company_id)->where('subject_type', SalesOrder::class)
                    ->where('subject_id', $record->id)->where('event', 'sales_order.deleted')->where('properties->archive_after_cancel', true)->exists()
                && app(DocumentOwnerEffectProofService::class)->cancelledSalesOrderIsSettled($record, allowArchived: true)) {
                $record->restore();
                $this->audit->record($record, 'sales_order.restored', ['restored_as_cancelled' => true]);

                return $record;
            }
            if (! $record->isEditable() || $record->status !== SalesOrder::StatusDraft) {
                throw new DomainException(__('Only unused drafts can be restored.'));
            }
            $record->restore();
            $this->audit->record($record, 'sales_order.restored');

            return $record;
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
            Customer::query()->lockForUpdate()->findOrFail($locked->customer_id);
            if (! in_array($locked->status, [SalesOrder::StatusDraft, SalesOrder::StatusPendingApproval, SalesOrder::StatusHeldCredit], true)
                || ($locked->approved_at !== null
                    && $locked->statusHistory()->whereIn('to_status', [SalesOrder::StatusApproved, SalesOrder::StatusReopened])
                        ->reorder()->latest('id')->value('to_status') !== SalesOrder::StatusReopened)) {
                throw new DomainException(__('The sales order is not awaiting approval.'));
            }
            if ($locked->lines->isEmpty()) {
                throw new DomainException(__('A sales order must contain at least one line.'));
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
                throw new DomainException(__('Only a credit-held order can be overridden.'));
            }
            if (trim($reason) === '') {
                throw new DomainException(__('A credit override reason is required.'));
            }

            $condition = $this->creditControl->evaluate($locked);
            if (! $condition['temporary_override_allowed']) {
                throw new DomainException(__('The applicable agreement does not permit a temporary override.'));
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
                throw new DomainException(__('The sales order cannot be rejected from its current status.'));
            }
            $locked->update(['status' => SalesOrder::StatusRejected, 'rejected_by' => auth()->id(), 'rejected_at' => now(), 'rejection_reason' => trim($reason)]);
            $this->recordStatus($locked, $from, SalesOrder::StatusRejected, $reason);

            return $locked->refresh();
        });
    }

    public function reopen(SalesOrder $order, string $reason): SalesOrder
    {
        Gate::authorize('sales_orders.reopen');

        return DB::transaction(function () use ($order, $reason): SalesOrder {
            $locked = SalesOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->getKey());
            $this->assertReopenContext($locked);
            if (blank($reason)) {
                throw new DomainException(__('A reason is required for this action.'));
            }
            if (! in_array($locked->status, [SalesOrder::StatusApproved, SalesOrder::StatusRejected, SalesOrder::StatusClosed, SalesOrder::StatusPartiallyFulfilled, SalesOrder::StatusFulfilled], true)) {
                throw new DomainException(__('The sales order cannot be reopened from its current status.'));
            }
            $appendProduction = $locked->canAppendProductionAmendment();
            if (! $appendProduction && $locked->lines->contains(fn (SalesOrderLine $line): bool => collect([
                $line->reserved_quantity,
                $line->production_requested_quantity,
                $line->produced_quantity,
                $line->delivered_quantity,
                $line->invoiced_quantity,
            ])->contains(fn (mixed $quantity): bool => $this->amounts->compare($quantity, '0', 8) > 0))) {
                throw new DomainException(__('An order with reservations, production, deliveries, or invoices cannot be amended; use controlled downstream reversal documents.'));
            }
            if (! $appendProduction && $locked->hasDownstreamDocuments()) {
                throw new DomainException(__('An order with downstream documents cannot be reopened.'));
            }
            $from = $locked->status;
            $locked->update(['status' => SalesOrder::StatusReopened, 'reopened_by' => auth()->id(), 'reopened_at' => now(), 'reopen_reason' => trim($reason),
                'reopen_snapshot' => ['status' => $from, 'lines' => $locked->amendmentLineSnapshot()]]);
            $this->recordStatus($locked, $from, SalesOrder::StatusReopened, $reason);
            $this->audit->record($locked, 'sales_order.reopened', ['reason' => trim($reason)]);

            return $locked->refresh();
        });
    }

    private function assertReopenContext(SalesOrder $order): void
    {
        $request = request();
        if (! $request->hasSession()) {
            throw new DomainException(__('operating_context.messages.required'));
        }

        $context = $this->operatingContext->snapshot($request);
        if (! $context['company_id'] || ! $context['branch_id'] || ! $context['financial_period_id']) {
            throw new DomainException(__('operating_context.messages.required'));
        }
        if ((int) $order->company_id !== (int) $context['company_id']
            || (int) $order->branch_id !== (int) $context['branch_id']
            || (int) $order->financial_period_id !== (int) $context['financial_period_id']) {
            throw new DomainException(__('The document is outside the active operating context.'));
        }

        $this->periods->resolveOpenForPostingDate(
            (int) $order->company_id,
            $order->order_date,
            (int) $order->financial_period_id,
            lockForUpdate: true,
        );
    }

    public function cancel(SalesOrder $order, string $reason): SalesOrder
    {
        if (blank($reason) || mb_strlen($reason) > 2000) {
            throw new DomainException(__('open_documents.validation.reason_required'));
        }

        return DB::transaction(function () use ($order, $reason): SalesOrder {
            Company::query()->whereKey($order->company_id)->lockForUpdate()->firstOrFail();
            $locked = SalesOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->getKey());
            $this->assertReopenContext($locked);
            if ($locked->status === SalesOrder::StatusCancelled
                && app(DocumentOwnerEffectProofService::class)->cancelledSalesOrderIsSettled($locked)) {
                return $locked;
            }
            if (! $locked->canCancelSafely()) {
                throw new DomainException(__('An order with downstream documents cannot be cancelled.'));
            }
            foreach (InventoryReservation::query()->where('sales_order_id', $locked->getKey())->where('status', InventoryReservation::StatusActive)->lockForUpdate()->get() as $reservation) {
                $reservation->update(['released_quantity' => bcadd((string) $reservation->released_quantity, $reservation->remaining_quantity, 8), 'status' => InventoryReservation::StatusReleased, 'released_by' => auth()->id(), 'released_at' => now(), 'release_reason' => trim($reason)]);
            }
            $locked->lines()->where(fn ($query) => $query->where('reserved_quantity', '<>', 0)->orWhere('reserved_base_quantity', '<>', 0))
                ->update(['reserved_quantity' => 0, 'reserved_base_quantity' => 0]);
            app(SalesRequestService::class)->releaseOrderConversion($locked);
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
        app(SalesFulfillmentService::class)->refreshOrderStatus($order);
        if (($order->reopen_snapshot['status'] ?? null) === SalesOrder::StatusClosed
            && ($order->reopen_snapshot['lines'] ?? null) === $order->amendmentLineSnapshot()) {
            $order->update(['status' => SalesOrder::StatusClosed]);
        }
        $order->update(['reopen_snapshot' => null]);
        $this->recordStatus($order, $from, $order->status, $creditStatus === 'overridden' ? 'Credit hold overridden.' : null);
        $this->audit->record($order, 'sales_order.approved', ['credit_status' => $creditStatus]);

        return $order->refresh();
    }

    private function transition(SalesOrder $order, array $allowed, string $status): SalesOrder
    {
        return DB::transaction(function () use ($order, $allowed, $status): SalesOrder {
            $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->getKey());
            if (! in_array($locked->status, $allowed, true)) {
                throw new DomainException(__('Invalid sales order status transition.'));
            }
            if ($status === SalesOrder::StatusPendingApproval && ! $locked->isEditable()) {
                throw new DomainException(__('Released sales orders must be reopened before amendment.'));
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
            throw new DomainException(__('A sales order requires at least one line.'));
        }

        return collect($input)->map(function (array $line) use ($companyId): array {
            $product = Product::query()->forCompany($companyId)->active()->findOrFail($line['product_id']);
            if (! $product->isSalesEligible()) {
                throw new DomainException(__('Only finished products and services may be sold.'));
            }
            $this->amounts->assertPositive($line['quantity'], __('Line quantity must be greater than zero.'));
            $this->amounts->assertPositive($line['unit_price'] ?? '0', __('Line unit price must be greater than zero.'), 8);
            $unitSnapshot = $this->unitConversions->snapshot($product, $line['unit_id'] ?? null, $line['quantity']);
            $gross = $this->amounts->unitPriceTotal($line['quantity'], $line['unit_price']);
            $discount = (string) ($line['discount_amount'] ?? 0);
            $tax = (string) ($line['tax_amount'] ?? 0);
            $total = $this->amounts->add($this->amounts->subtract($gross, $discount), $tax);

            return [
                ...collect($line)->except(['id', 'public_id', 'line_number'])->all(),
                'description' => $line['description'] ?? $product->name,
                'unit_id' => $unitSnapshot['unit_id'],
                'conversion_factor' => $unitSnapshot['conversion_factor'],
                'base_quantity' => $unitSnapshot['base_quantity'],
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'tax_rate' => filled($line['tax_rate'] ?? null) ? app(SalesTaxService::class)->rate($line['tax_rate']) : null,
                'tax_calculation_basis' => $line['tax_calculation_basis'] ?? (filled($line['tax_rate'] ?? null) ? SalesTaxService::Rate : SalesTaxService::LegacyAmount),
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
        $subtotal = $this->amounts->sum(array_map(fn (array $line): string => $this->amounts->subtract($this->amounts->add($line['line_total'], $line['discount_amount']), $line['tax_amount']), $lines));
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
            throw new DomainException(__('Payment schedule amounts must equal the sales order total.'));
        }
        foreach ($schedules as $index => $schedule) {
            $order->paymentSchedules()->create([...$schedule, 'line_number' => $index + 1, 'status' => 'pending', 'collected_amount' => 0, 'remaining_amount' => $schedule['amount']]);
        }
    }

    /** @return list<int> */
    private function reconciledCancelledQuotationOrders(Quotation $quotation): array
    {
        $ids = [];
        foreach ($quotation->salesOrders()->withTrashed()->where('status', SalesOrder::StatusCancelled)->lazyById() as $order) {
            if (app(DocumentOwnerEffectProofService::class)->cancelledSalesOrderIsSettled($order)) {
                $ids[] = (int) $order->id;
            }
        }

        return $ids;
    }

    private function consumeQuotation(SalesOrder $order): void
    {
        if (! $order->quotation_id || ! $order->quotation_revision_id) {
            return;
        }
        $quotation = Quotation::query()->lockForUpdate()->findOrFail($order->quotation_id);
        $revision = QuotationRevision::query()->lockForUpdate()->findOrFail($order->quotation_revision_id);
        if ($quotation->status !== Quotation::StatusAccepted || $quotation->current_revision_id !== $revision->getKey()) {
            throw new DomainException(__('Only the accepted current quotation revision can be converted.'));
        }
        $reconciledCancelledOrders = $this->reconciledCancelledQuotationOrders($quotation);
        $complete = true;
        foreach ($revision->lines as $line) {
            $converted = (string) SalesOrderLine::query()->whereNotIn('sales_order_id', $reconciledCancelledOrders)->where('quotation_revision_line_id', $line->id)->sum('quantity');
            $this->amounts->assertNotGreaterThan($converted, $line->quantity, __('Converted quantity exceeds the remaining quotation quantity.'));
            $complete = $complete && bccomp($converted, (string) $line->quantity, 8) === 0;
        }
        $quotation->update(['status' => $complete ? Quotation::StatusConverted : Quotation::StatusAccepted, 'updated_by' => auth()->id()]);
    }

    /** @return list<array<string, mixed>> */
    private function quotationLines(QuotationRevision $revision): array
    {
        $baseDiscount = $this->amounts->sum($revision->lines->pluck('discount_amount'));
        $documentDiscount = $this->amounts->subtract($revision->discount_amount, $baseDiscount);
        $bases = $revision->lines->values()->map(fn ($line): string => $this->amounts->subtract($this->amounts->unitPriceTotal($line->quantity, $line->unit_price), $line->discount_amount))->all();
        $shares = app(SalesOrderDiscountService::class)->headerShares($documentDiscount, $bases);

        return $revision->lines->values()->map(function ($line, int $index) use ($shares): array {
            $share = $shares[$index];

            return [
                'quotation_revision_line_id' => $line->getKey(),
                'sales_request_line_id' => $line->sales_request_line_id,
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'description' => $line->description ?: $line->product_name_snapshot,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'price_list_line_id' => $line->price_list_line_id,
                'allowed_discount_type' => $line->allowed_discount_type,
                'allowed_discount_value' => $line->allowed_discount_value,
                'discount_type' => $line->discount_type,
                'discount_value' => $line->discount_value,
                'header_discount_amount' => $share,
                'discount_amount' => $this->amounts->add($line->discount_amount, $share),
                'tax_amount' => $line->tax_amount,
                'tax_rate' => $line->tax_rate,
                'tax_calculation_basis' => SalesTaxService::SourceAllocation,
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
                throw new DomainException(__('Missing required sales order context: :key.', ['key' => $key]));
            }
        }
    }
}
