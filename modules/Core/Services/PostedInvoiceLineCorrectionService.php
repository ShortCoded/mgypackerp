<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\PostedInvoiceLineCorrection;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\UnpricedInventoryReceiptLine;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerInvoiceCorrection;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\CustomerInvoiceBalanceService;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\PriceListPricingService;
use Modules\Sales\Services\SalesAmountService;
use Modules\Sales\Services\SalesUnitConversionService;

final class PostedInvoiceLineCorrectionService
{
    /** @return list<string> */
    public function permissions(string $kind, bool $approve = false): array
    {
        return $kind === 'sales'
            ? [$approve ? 'customer_invoices.correct_approve' : 'customer_invoices.correct_prepare', 'customer_invoices.create', ...($approve ? ['customer_invoices.post'] : [])]
            : ['purchases.prices.view', 'purchase_invoices.reverse', 'purchase_invoices.create', ...($approve ? ['purchase_invoices.approve'] : [])];
    }

    public function authorize(string $kind, bool $approve = false): void
    {
        abort_unless(in_array($kind, ['sales', 'purchase'], true), 404);
        foreach ($this->permissions($kind, $approve) as $permission) {
            Gate::authorize($permission);
        }
        if ($kind === 'purchase') {
            $context = app(OperatingContextService::class)->snapshot(request());
            abort_unless(Branch::query()->where('company_id', $context['company_id'])->whereKey($context['branch_id'])
                ->where('type', Branch::TypeAdministrative)->exists(), 403);
        }
    }

    public function invoice(string $kind, string $docNum): CustomerInvoice|PurchaseInvoice
    {
        abort_unless(in_array($kind, ['sales', 'purchase'], true), 404);
        $class = $kind === 'sales' ? CustomerInvoice::class : PurchaseInvoice::class;
        $context = app(OperatingContextService::class)->snapshot(request());
        $invoice = $class::query()->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
            ->where('financial_period_id', $context['financial_period_id'])->where('doc_num', $docNum)->lockForUpdate()->firstOrFail();
        $company = Company::query()->findOrFail($invoice->company_id);
        $access = app(OperatingScopeAccessService::class);
        abort_unless($access->allowedBranchQuery(auth()->user(), [$company->doc_num])->where('branches.id', $invoice->branch_id)->exists(), 404);
        abort_unless($access->allowedFinancialPeriodQuery(auth()->user(), [$company->doc_num])->where('financial_periods.id', $invoice->financial_period_id)->exists(), 404);
        if (FinancialPeriod::query()->lockForUpdate()->findOrFail($invoice->financial_period_id)->is_closed) {
            throw new DomainException(__('posted_invoice_correction.period_closed'));
        }

        return $invoice;
    }

    /** @return array<string,mixed> */
    public function preview(string $kind, string $docNum): array
    {
        Gate::authorize($kind === 'sales' ? 'customer_invoices.view' : 'purchase_invoices.view');
        $canPrepare = collect($this->permissions($kind))->every(fn (string $ability): bool => Gate::allows($ability));
        $canApprove = collect($this->permissions($kind, true))->every(fn (string $ability): bool => Gate::allows($ability));
        abort_unless($canPrepare || $canApprove, 403);

        return DB::transaction(function () use ($kind, $docNum, $canPrepare, $canApprove): array {
            $invoice = $this->invoice($kind, $docNum);
            $source = $this->source($kind, $invoice);
            $sourceDocument = $kind === 'purchase' ? $invoice->purchaseOrder : ($invoice->order
                ?? ($invoice->source_type === 'sales_request' ? SalesRequest::query()->findOrFail($invoice->source_id) : null));
            $sourceLines = $sourceDocument?->lines()->with(['product', 'unit'])->get() ?? collect();
            $sourceOptions = $sourceLines->map(fn ($line): array => ['id' => $line->public_id,
                'text' => $sourceDocument->doc_num.' / '.$line->line_number.' / '.$line->product?->name.' / '.$line->unit?->name])->all();
            $receiptOptions = $kind === 'purchase' ? UnpricedInventoryReceiptLine::query()
                ->with(['receipt', 'product', 'unit'])->whereIn('purchase_order_line_id', $sourceLines->pluck('id'))
                ->whereHas('receipt', fn ($query) => $query->where('approved', true)->where('posting_status', 'posted')->whereNotIn('status', ['cancelled', 'reversed']))
                ->orderBy('id')->get()->map(fn ($line): array => ['id' => $line->public_id,
                    'text' => $line->receipt->doc_num.' / '.$line->line_number.' / '.$line->product?->name])->all() : [];

            return ['kind' => $kind, 'invoice' => $invoice, 'fingerprint' => $this->digest($source),
                'blockers' => $this->blockers($kind, $invoice, $source), 'canPrepare' => $canPrepare, 'canApprove' => $canApprove,
                'sourceOptions' => $sourceOptions, 'receiptOptions' => $receiptOptions,
                'history' => PostedInvoiceLineCorrection::query()->with(['preparer', 'approver'])->where('company_id', $invoice->company_id)
                    ->where('kind', $kind)->where('invoice_id', $invoice->id)->latest('id')->paginate(20)];
        });
    }

    /** @param array<string,mixed> $data */
    public function prepare(string $kind, string $docNum, array $data): PostedInvoiceLineCorrection
    {
        $this->authorize($kind);

        return DB::transaction(function () use ($kind, $docNum, $data): PostedInvoiceLineCorrection {
            $this->lockCompany();
            $invoice = $this->invoice($kind, $docNum);
            $source = $this->source($kind, $invoice);
            if (! hash_equals($this->digest($source), (string) $data['source_fingerprint'])) {
                throw new DomainException(__('posted_invoice_correction.stale'));
            }
            $this->assertNoBlockers($kind, $invoice, $source);
            $input = $this->replacementInput($kind, $invoice, $data);
            $impact = $this->impact($kind, $invoice, $input);
            $pending = PostedInvoiceLineCorrection::query()->where('company_id', $invoice->company_id)->where('kind', $kind)
                ->where('invoice_id', $invoice->id)->where('status', 'prepared')->lockForUpdate()->first();
            if ($pending !== null) {
                $this->assertSeal($pending);
                if ($pending->replacement_input === $input && $pending->reason === trim($data['reason']) && $pending->source_fingerprint === $this->digest($source)) {
                    return $pending;
                }
                throw new DomainException(__('posted_invoice_correction.pending'));
            }
            $values = ['company_id' => $invoice->company_id, 'branch_id' => $invoice->branch_id,
                'financial_period_id' => $invoice->financial_period_id, 'kind' => $kind, 'invoice_id' => $invoice->id,
                'doc_num' => $invoice->doc_num, 'posting_date' => $input['invoice_date'], 'reason' => trim($data['reason']),
                'source_snapshot' => $source, 'source_fingerprint' => $this->digest($source), 'replacement_input' => $input,
                'impact' => $impact, 'prepared_by' => auth()->id(), 'status' => 'prepared'];
            if ($kind === 'sales') {
                $sales = app(CustomerInvoiceCorrectionService::class)->prepare($invoice, ['posting_date' => $input['invoice_date'],
                    'reason' => $values['reason'], 'recovery_reference' => __('posted_invoice_correction.recording_error'),
                    'source_fingerprint' => $source['fingerprint']]);
                $values['sales_correction_id'] = $sales->id;
            }
            $proposal = new PostedInvoiceLineCorrection($values);
            $proposal->proposal_fingerprint = $this->proposalDigest($proposal);
            $proposal->save();
            $this->audit($proposal, 'prepared', ['impact' => $impact]);

            return $proposal;
        }, 3);
    }

    public function approve(string $kind, string $docNum, int $id, string $reason): PostedInvoiceLineCorrection
    {
        $this->authorize($kind, true);

        return DB::transaction(function () use ($kind, $docNum, $id, $reason): PostedInvoiceLineCorrection {
            $this->lockCompany();
            $invoice = $this->invoice($kind, $docNum);
            $proposal = PostedInvoiceLineCorrection::query()->where('company_id', $invoice->company_id)->where('kind', $kind)
                ->where('invoice_id', $invoice->id)->lockForUpdate()->findOrFail($id);
            $this->assertSeal($proposal);
            if ((int) $proposal->prepared_by === (int) auth()->id() || blank($reason)) {
                throw new DomainException(__('posted_invoice_correction.independent'));
            }
            if ($proposal->status === 'approved') {
                $this->assertExecution($proposal);

                return $proposal;
            }
            $source = $this->source($kind, $invoice);
            if ($proposal->status !== 'prepared' || ! hash_equals($proposal->source_fingerprint, $this->digest($source))) {
                throw new DomainException(__('posted_invoice_correction.stale'));
            }
            $this->assertNoBlockers($kind, $invoice, $source);
            $input = $this->replacementInput($kind, $invoice, ['posting_date' => $proposal->posting_date->toDateString(), 'lines' => $proposal->replacement_input['lines']]);
            if ($input !== $proposal->replacement_input || $this->impact($kind, $invoice, $input) !== $proposal->impact) {
                throw new DomainException(__('posted_invoice_correction.stale'));
            }
            $schedules = $this->correctedSchedules($invoice, $proposal->impact['after_total'], $input['invoice_date']);
            if ($kind === 'sales') {
                app(CustomerInvoiceCorrectionService::class)->approve($invoice, (int) $proposal->sales_correction_id, $reason, (int) $proposal->id);
                $service = app(CustomerInvoiceService::class);
                if ($invoice->sales_order_id !== null) {
                    Gate::authorize('sales_orders.invoice');
                    $replacement = $service->createFromOrder($invoice->order, $input['lines'], $schedules, invoiceDate: $input['invoice_date']);
                } else {
                    $request = $invoice->source_type === 'sales_request' ? SalesRequest::query()->findOrFail($invoice->source_id) : null;
                    $replacement = $service->createDirect($input, $request);
                    $replacement = $service->amend($replacement, $replacement->lines->map(fn ($line): array => ['invoice_line_public_id' => $line->public_id, 'quantity' => (string) $line->quantity])->all(), $schedules);
                }
                $replacement = $service->post($replacement);
            } else {
                $service = app(PurchaseInvoiceService::class);
                $service->reverse($invoice, $proposal->reason);
                $replacement = $service->approve($service->create([...$input, 'payment_schedules' => $schedules])['record']);
            }
            if (bccomp((string) $replacement->total_amount, $proposal->impact['after_total'], 4) !== 0) {
                throw new DomainException(__('posted_invoice_correction.stale'));
            }
            $execution = ['replacement' => $replacement->only(['id', 'doc_num', 'company_id', 'branch_id', 'financial_period_id',
                'invoice_date', 'currency_id', 'total_amount', 'journal_entry_id']),
                'lines' => $replacement->lines()->orderBy('id')->get()->map->getAttributes()->all(),
                'journal' => $replacement->journalEntry->getAttributes(),
                'journal_lines' => $replacement->journalEntry->lines()->orderBy('id')->get()->map->getAttributes()->all(),
                'source_reversal_id' => $invoice->fresh()->reversal_journal_entry_id, 'approved_by' => auth()->id(), 'approval_reason' => trim($reason)];
            $proposal->forceFill(['status' => 'approved', 'replacement_invoice_id' => $replacement->id, 'approved_by' => auth()->id(),
                'approved_at' => now(), 'approval_reason' => trim($reason), 'execution_snapshot' => $execution,
                'execution_fingerprint' => $this->digest($execution)])->save();
            $this->audit($proposal, 'approved', ['impact' => $proposal->impact, 'execution' => $execution]);

            return $proposal->refresh();
        }, 3);
    }

    public function reject(string $kind, string $docNum, int $id): void
    {
        $this->authorize($kind, true);
        DB::transaction(function () use ($kind, $docNum, $id): void {
            $this->lockCompany();
            $invoice = $this->invoice($kind, $docNum);
            $proposal = PostedInvoiceLineCorrection::query()->where('company_id', $invoice->company_id)->where('kind', $kind)
                ->where('invoice_id', $invoice->id)->lockForUpdate()->findOrFail($id);
            $this->assertSeal($proposal);
            if ($kind === 'sales') {
                app(CustomerInvoiceCorrectionService::class)->reject($invoice, (int) $proposal->sales_correction_id, (int) $proposal->id);
            }
            $proposal->update(['status' => 'rejected']);
            $this->audit($proposal, 'rejected');
        }, 3);
    }

    private function lockCompany(): void
    {
        Company::query()->lockForUpdate()->findOrFail(app(OperatingContextService::class)->snapshot(request())['company_id']);
    }

    /** @return array<string,mixed> */
    private function source(string $kind, CustomerInvoice|PurchaseInvoice $invoice): array
    {
        $invoice->load(['lines.product', 'lines.unit', 'currency', 'journalEntry.lines']);
        if ($kind === 'sales') {
            $preview = app(CustomerInvoiceCorrectionService::class)->preview($invoice);

            return ['snapshot' => $preview['snapshot'], 'fingerprint' => $preview['fingerprint'], 'dependencies' => $preview['dependencies']];
        }
        $invoice->load(['financialPeriod', 'purchaseOrder.lines', 'supplier', 'lines.purchaseOrderLine', 'lines.receiptLine.receipt',
            'paymentAllocations.paymentContext', 'paymentSchedules.cashVoucher', 'purchaseReturns']);
        $receipts = $invoice->lines->pluck('receipt_line_id')->filter()->unique()->sort()->values()->all();
        $rows = fn (string $table, array $ids): array => DB::table($table)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->map(fn ($row): array => (array) $row)->all();

        return ['header' => $invoice->getAttributes(), 'lines' => $invoice->lines->map->getAttributes()->all(),
            'schedules' => $invoice->paymentSchedules->map->getAttributes()->all(), 'allocations' => $invoice->paymentAllocations->map->getAttributes()->all(),
            'payments' => $invoice->paymentAllocations->pluck('paymentContext')->filter()->map->getAttributes()->all(),
            'vouchers' => $invoice->paymentSchedules->pluck('cashVoucher')->filter()->map->getAttributes()->all(),
            'returns' => $invoice->purchaseReturns->map->getAttributes()->all(), 'receipts' => $rows('unpriced_inventory_receipt_lines', $receipts),
            'receipt_headers' => $invoice->lines->pluck('receiptLine.receipt')->filter()->unique('id')->map->getAttributes()->all(),
            'order' => $invoice->purchaseOrder?->getAttributes(), 'order_lines' => $invoice->purchaseOrder?->lines->map->getAttributes()->all(),
            'journal' => $invoice->journalEntry?->getAttributes(), 'journal_lines' => $invoice->journalEntry?->lines->map->getAttributes()->all(),
            'period' => $invoice->financialPeriod->getAttributes()];
    }

    /** @param array<string,mixed> $source @return list<string> */
    private function blockers(string $kind, CustomerInvoice|PurchaseInvoice $invoice, array $source): array
    {
        $blockers = [];
        if ($kind === 'sales') {
            if ($invoice->document_type !== CustomerInvoice::TypeInvoice || $invoice->posting_status !== 'posted' || $invoice->hasApprovedCorrection()) {
                $blockers[] = __('posted_invoice_correction.posted_required');
            }
            if ($source['snapshot']['documents'] !== [] || $invoice->lines->contains(fn ($line): bool => $line->delivery_line_id !== null)
                || $invoice->issueOrder()->where('status', '<>', 'pending')->exists()) {
                $blockers[] = __('posted_invoice_correction.delivery_conflict', ['document' => $invoice->doc_num]);
            }
            app(CustomerInvoiceBalanceService::class)->assertNoActiveWithholding($invoice);
            if ($source['dependencies'] !== [] || bccomp($invoice->paid_amount, '0', 4) !== 0 || bccomp($invoice->credited_amount, '0', 4) !== 0 || bccomp($invoice->applied_advance_amount, '0', 4) !== 0) {
                $blockers[] = __('posted_invoice_correction.settlement_conflict', ['document' => $invoice->doc_num]);
                foreach ($source['dependencies'] as $step) {
                    $blockers[] = $step['document'].' — '.$step['action'];
                }
            }
            if (($invoice->sales_order_id === null && ! in_array($invoice->source_type, ['direct', 'sales_request'], true))
                || filled($invoice->electronic_invoice_uuid) || ! in_array($invoice->electronic_invoice_status, ['not_configured', 'draft', 'rejected'], true)) {
                $blockers[] = __('posted_invoice_correction.source_workflow');
            }
            if (collect($source['snapshot']['periods'])->contains(fn ($period): bool => (bool) $period['is_closed'])) {
                $blockers[] = __('posted_invoice_correction.period_closed');
            }
        } else {
            $plan = app(PurchaseInvoiceService::class)->reversalPlan($invoice);
            if (! $plan['can_reverse']) {
                $blockers[] = $plan['dependent_documents'] === [] ? __('posted_invoice_correction.lineage_conflict', ['document' => $invoice->doc_num])
                    : __('posted_invoice_correction.settlement_conflict', ['document' => $invoice->doc_num]);
                foreach ($plan['dependent_documents'] as $documents) {
                    $blockers[] = implode(', ', $documents);
                }
            }
            if ($invoice->lines->contains(fn ($line): bool => (filled($line->asset_treatment) && $line->asset_treatment !== 'none') || $line->target_fixed_asset_id !== null)
                || $invoice->lines()->whereHas('fixedAssets')->exists() || $invoice->lines()->whereHas('assetImprovementMovement')->exists()) {
                $blockers[] = __('posted_invoice_correction.source_workflow');
            }
            if ($invoice->payment_type !== 'credit' || $invoice->paymentSchedules->contains(fn ($schedule): bool => $schedule->cash_voucher_id !== null)) {
                $blockers[] = __('posted_invoice_correction.settlement_conflict', ['document' => $invoice->doc_num]);
            }
            $periodIds = collect($source['receipt_headers'])->pluck('financial_period_id')->push($invoice->purchaseOrder?->financial_period_id)->filter()->unique();
            if (FinancialPeriod::query()->whereIn('id', $periodIds)->where('is_closed', true)->exists()) {
                $blockers[] = __('posted_invoice_correction.period_closed');
            }
        }

        return array_values(array_unique($blockers));
    }

    /** @param array<string,mixed> $source */
    private function assertNoBlockers(string $kind, CustomerInvoice|PurchaseInvoice $invoice, array $source): void
    {
        if (($blockers = $this->blockers($kind, $invoice, $source)) !== []) {
            throw new DomainException(implode(' ', $blockers));
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function replacementInput(string $kind, CustomerInvoice|PurchaseInvoice $invoice, array $data): array
    {
        if ($kind === 'purchase' && $invoice->direct_procurement_override) {
            Gate::authorize('purchases.direct_procurement.override');
        }
        $date = (string) $data['posting_date'];
        app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $invoice->company_id, $date, (int) $invoice->financial_period_id, lockForUpdate: true);
        if ($date < $invoice->invoice_date->toDateString() || ($kind === 'purchase' && $date !== now()->toDateString())) {
            throw new DomainException(__('posted_invoice_correction.posting_date_invalid'));
        }
        $rows = [];
        $evidence = [];
        $seen = [];
        foreach ($data['lines'] as $line) {
            $publicId = trim((string) ($line['original_line_public_id'] ?? ''));
            $original = $publicId === '' ? null : $invoice->lines->firstWhere('public_id', $publicId);
            if ($publicId !== '' && ($original === null || in_array($publicId, $seen, true))) {
                throw new DomainException(__('posted_invoice_correction.invalid_line'));
            }
            $seen[] = $publicId;
            $product = Product::query()->forCompany((int) $invoice->company_id)->active()->where('doc_num', $line['product_doc_num'])->lockForUpdate()->firstOrFail();
            $unit = ItemUnit::query()->forCompany((int) $invoice->company_id)->active()->where('doc_num', $line['unit_doc_num'])->firstOrFail();
            app(SalesUnitConversionService::class)->snapshot($product, (int) $unit->id, (string) $line['quantity']);
            $row = ['original_line_public_id' => $publicId ?: null, 'product_doc_num' => $product->doc_num,
                'unit_doc_num' => $unit->doc_num, 'quantity' => (string) $line['quantity']];
            $facts = ['product' => $product->getAttributes(), 'unit' => $unit->getAttributes()];
            if ($kind === 'sales') {
                if (! $product->isSalesEligible()) {
                    throw new DomainException(__('posted_invoice_correction.invalid_line'));
                }
                if ($invoice->sales_order_id !== null || $invoice->source_type === 'sales_request') {
                    $sourceDocument = $invoice->sales_order_id !== null ? $invoice->order : SalesRequest::query()->where('company_id', $invoice->company_id)->lockForUpdate()->findOrFail($invoice->source_id);
                    $source = $sourceDocument->lines()->where('public_id', $line['source_line_public_id'] ?? null)->lockForUpdate()->first();
                    if ($source === null || (int) $source->product_id !== (int) $product->id || (int) $source->unit_id !== (int) $unit->id
                        || ($invoice->sales_order_id !== null && ! $source->isService())) {
                        throw new DomainException(__('posted_invoice_correction.source_conflict', ['document' => $sourceDocument->doc_num]));
                    }
                    $row['source_line_public_id'] = $source->public_id;
                    $row[$invoice->sales_order_id !== null ? 'sales_order_line_id' : 'source_request_line_public_id'] = $invoice->sales_order_id !== null ? $source->id : $source->public_id;
                    $facts['source'] = $source->getAttributes();
                    $facts['source_document'] = $sourceDocument->getAttributes();
                    if ($sourceDocument->financial_period_id !== null && FinancialPeriod::query()->findOrFail($sourceDocument->financial_period_id)->is_closed) {
                        throw new DomainException(__('posted_invoice_correction.period_closed'));
                    }
                }
                $row['discount_amount'] = (string) ($line['discount_amount'] ?? '0');
                $row['tax_amount'] = (string) ($line['tax_amount'] ?? '0');
            } else {
                if (! $product->isPurchasable() || ($line['asset_treatment'] ?? 'none') !== 'none') {
                    throw new DomainException(__('posted_invoice_correction.source_workflow'));
                }
                $row += ['unit_price' => (string) $line['unit_price'], 'discount_type' => $line['discount_type'] ?? null,
                    'discount_value' => (string) ($line['discount_value'] ?? '0'), 'tax_rate' => (string) ($line['tax_rate'] ?? '0'),
                    'cost_center_doc_num' => $original?->costCenter?->doc_num, 'asset_treatment' => 'none'];
                if ($invoice->purchase_order_id !== null) {
                    $source = $invoice->purchaseOrder->lines()->where('public_id', $line['source_line_public_id'] ?? null)->lockForUpdate()->first();
                    if ($source === null || (int) $source->product_id !== (int) $product->id || (int) $source->unit_id !== (int) $unit->id) {
                        throw new DomainException(__('posted_invoice_correction.source_conflict', ['document' => $invoice->purchaseOrder->doc_num]));
                    }
                    $row['purchase_order_line_public_id'] = $source->public_id;
                    $row['source_line_public_id'] = $source->public_id;
                    $receipt = $source->receiptLines()->where('public_id', $line['receipt_line_public_id'] ?? $original?->receiptLine?->public_id)->lockForUpdate()->first();
                    if (! $product->isService() && ($receipt === null || (int) $receipt->product_id !== (int) $product->id || (int) $receipt->unit_id !== (int) $unit->id)) {
                        throw new DomainException(__('posted_invoice_correction.receipt_conflict', ['document' => $original?->receiptLine?->receipt?->doc_num ?? $invoice->purchaseOrder->doc_num]));
                    }
                    $row['receipt_line_public_id'] = $receipt?->public_id;
                    $facts['source'] = $source->getAttributes();
                    $facts['receipt'] = $receipt?->getAttributes();
                    $facts['receipt_header'] = $receipt?->receipt?->getAttributes();
                    if ($receipt !== null && FinancialPeriod::query()->findOrFail($receipt->receipt->financial_period_id)->is_closed) {
                        throw new DomainException(__('posted_invoice_correction.period_closed'));
                    }
                } elseif ($original?->receipt_line_id !== null || filled($line['receipt_line_public_id'] ?? null)) {
                    throw new DomainException(__('posted_invoice_correction.receipt_conflict', ['document' => $invoice->doc_num]));
                }
            }
            $rows[] = $row;
            $evidence[] = $facts;
        }
        $input = ['company_id' => $invoice->company_id, 'branch_id' => $invoice->branch_id, 'invoice_date' => $date,
            'currency_doc_num' => $invoice->currency->doc_num, 'exchange_rate' => (string) $invoice->exchange_rate, 'notes' => $invoice->notes, 'lines' => $rows, 'source_evidence' => $evidence];
        if ($kind === 'sales') {
            return [...$input, 'customer_doc_num' => $invoice->customer->doc_num, 'due_date' => max($date, $invoice->due_date?->toDateString() ?? $date)];
        }

        return [...$input, 'supplier_doc_num' => $invoice->supplier->doc_num, 'purchase_order_doc_num' => $invoice->purchaseOrder?->doc_num,
            'supplier_invoice_number' => $invoice->supplier_invoice_number, 'supplier_invoice_date' => $invoice->supplier_invoice_date?->toDateString(),
            'purchase_type' => $invoice->purchase_type, 'payment_type' => 'credit', 'direct_procurement_override' => (bool) $invoice->direct_procurement_override,
            'direct_procurement_reason' => $invoice->direct_procurement_reason, 'internal_notes' => $invoice->internal_notes,
            'header_discount_type' => $invoice->header_discount_type, 'header_discount_value' => (string) $invoice->header_discount_value,
            'freight_amount' => (string) $invoice->freight_amount, 'freight_tax_rate' => (string) $invoice->freight_tax_rate];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function impact(string $kind, CustomerInvoice|PurchaseInvoice $invoice, array $input): array
    {
        $rows = [];
        if ($kind === 'purchase') {
            $calculation = app(PurchaseInvoiceCalculationService::class)->calculate($input['lines'], $input['header_discount_type'], $input['header_discount_value'], $input['freight_amount'], $input['freight_tax_rate']);
            $total = $calculation['invoice']['total_amount'];
            foreach ($calculation['lines'] as $line) {
                $rows[] = ['product' => Product::query()->forCompany((int) $invoice->company_id)->where('doc_num', $line['product_doc_num'])->value('name'),
                    'unit' => ItemUnit::query()->forCompany((int) $invoice->company_id)->where('doc_num', $line['unit_doc_num'])->value('name'),
                    'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'total' => $line['total_after_tax']];
            }
        } elseif ($invoice->sales_order_id !== null) {
            $quoted = app(CustomerInvoiceService::class)->quoteOrderCorrection($invoice->order, $input['lines'], (int) $invoice->id);
            $rows = $quoted['lines'];
            $total = $quoted['total'];
        } else {
            $amounts = app(SalesAmountService::class);
            foreach ($input['lines'] as $line) {
                $product = Product::query()->forCompany((int) $invoice->company_id)->where('doc_num', $line['product_doc_num'])->firstOrFail();
                $unit = ItemUnit::query()->forCompany((int) $invoice->company_id)->where('doc_num', $line['unit_doc_num'])->firstOrFail();
                $price = app(PriceListPricingService::class)->resolve((int) $invoice->company_id, (int) $invoice->customer_id,
                    (int) $invoice->currency_id, $product, (int) $unit->id, $line['quantity'], $input['invoice_date'], true);
                $gross = $amounts->unitPriceTotal($line['quantity'], $price['unit_price']);
                $amounts->assertNotGreaterThan($line['discount_amount'], $price['maximum_discount_amount'], __('posted_invoice_correction.discount_invalid'));
                $rows[] = ['product' => $product->name, 'unit' => $unit->name, 'quantity' => $line['quantity'], 'unit_price' => $price['unit_price'],
                    'total' => $amounts->add($amounts->subtract($gross, $line['discount_amount']), $line['tax_amount'])];
            }
            $total = $amounts->sum(array_column($rows, 'total'));
        }

        return ['before_lines' => $invoice->lines->map(fn ($line): array => ['product' => $line->product?->name ?? $line->description,
            'unit' => $line->unit?->name, 'quantity' => (string) $line->quantity, 'unit_price' => (string) $line->unit_price,
            'total' => (string) ($line->line_total ?? $line->total_after_tax)])->all(), 'after_lines' => $rows,
            'before_total' => (string) $invoice->total_amount, 'after_total' => $total, 'journal' => $invoice->journalEntry?->doc_num,
            'stock_quantity_delta' => '0.00000000', 'after_schedules' => $this->correctedSchedules($invoice, $total, $input['invoice_date'])];
    }

    private function assertSeal(PostedInvoiceLineCorrection $proposal): void
    {
        if (! hash_equals($proposal->source_fingerprint, $this->digest($proposal->source_snapshot)) || ! hash_equals($proposal->proposal_fingerprint, $this->proposalDigest($proposal))) {
            throw new DomainException(__('posted_invoice_correction.stale'));
        }
    }

    /** @return list<array<string,mixed>> */
    private function correctedSchedules(CustomerInvoice|PurchaseInvoice $invoice, string $total, string $date): array
    {
        $schedules = $invoice->paymentSchedules()->orderBy('id')->lockForUpdate()->get();
        if ($schedules->isEmpty()) {
            return [['due_date' => $date, 'amount' => $total, ...($invoice instanceof PurchaseInvoice ? ['payment_source_type' => PurchaseInvoice::SourceScheduled] : [])]];
        }
        $before = app(SalesAmountService::class)->sum($schedules->pluck('amount'));
        if (bccomp($before, '0', 4) <= 0) {
            throw new DomainException(__('posted_invoice_correction.stale'));
        }
        $allocated = '0.0000';
        $rows = [];
        foreach ($schedules as $index => $schedule) {
            $amount = $index === $schedules->count() - 1 ? bcsub($total, $allocated, 4)
                : app(SalesAmountService::class)->round(bcdiv(bcmul($total, (string) $schedule->amount, 12), $before, 12));
            if (bccomp($amount, '0', 4) <= 0) {
                throw new DomainException(__('posted_invoice_correction.schedule_conflict'));
            }
            $rows[] = ['due_date' => max($date, $schedule->due_date->toDateString()), 'amount' => $amount, 'notes' => $schedule->notes,
                ...($invoice instanceof PurchaseInvoice ? ['payment_source_type' => $schedule->payment_source_type,
                    'cashbox_doc_num' => $schedule->cashbox?->doc_num, 'bank_account_doc_num' => $schedule->bankAccount?->doc_num] : [])];
            $allocated = bcadd($allocated, $amount, 4);
        }

        return $rows;
    }

    private function assertExecution(PostedInvoiceLineCorrection $proposal): void
    {
        $execution = $proposal->execution_snapshot;
        if (! is_array($execution) || ! hash_equals((string) $proposal->execution_fingerprint, $this->digest($execution))
            || (int) $execution['approved_by'] !== (int) $proposal->approved_by || $execution['approval_reason'] !== $proposal->approval_reason) {
            throw new DomainException(__('posted_invoice_correction.stale'));
        }
        if ($proposal->kind === 'sales') {
            app(CustomerInvoiceCorrectionService::class)->assertApproved(CustomerInvoiceCorrection::query()->findOrFail($proposal->sales_correction_id));
        }
        $replacement = $this->invoice($proposal->kind, $execution['replacement']['doc_num']);
        if ($this->digest($replacement->only(array_keys($execution['replacement']))) !== $this->digest($execution['replacement'])
            || $replacement->lines()->orderBy('id')->get()->map->getAttributes()->all() !== $execution['lines']
            || $replacement->journalEntry?->getAttributes() !== $execution['journal']
            || $replacement->journalEntry?->lines()->orderBy('id')->get()->map->getAttributes()->all() !== $execution['journal_lines']) {
            throw new DomainException(__('posted_invoice_correction.stale'));
        }
    }

    private function proposalDigest(PostedInvoiceLineCorrection $proposal): string
    {
        return $this->digest($proposal->only(['company_id', 'branch_id', 'financial_period_id', 'kind', 'invoice_id', 'doc_num', 'posting_date',
            'reason', 'source_fingerprint', 'replacement_input', 'impact', 'prepared_by', 'sales_correction_id']));
    }

    /** @param array<mixed> $data */
    private function digest(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param array<string,mixed> $properties */
    private function audit(PostedInvoiceLineCorrection $proposal, string $event, array $properties = []): void
    {
        app(ActivityLogger::class)->log(request(), $proposal->kind === 'sales' ? 'sales' : 'purchases', 'invoice_line_correction.'.$event, 'success',
            ['subject' => $proposal, 'company_id' => $proposal->company_id, 'properties_only' => true, 'properties' => $properties]);
    }
}
