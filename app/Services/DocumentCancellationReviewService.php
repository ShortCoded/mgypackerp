<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperatingScopeAccessService;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\RequestForQuotation;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Models\SupplierQuotation;
use Modules\Purchases\Models\SupplierSelection;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Purchases\Services\ProcurementCorrectionPlanService;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\CustomerWithholdingSettlement;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;

final class DocumentCancellationReviewService
{
    public function __construct(
        private readonly OperatingContextService $context,
        private readonly OperatingScopeAccessService $scope,
    ) {}

    /** @return array{url: string, label: string}|null */
    public function navigation(Model $record, Request $request): ?array
    {
        $context = $this->context->snapshot($request);
        if ($record instanceof CashVoucher || $record instanceof Cheque) {
            $resource = $record instanceof CashVoucher
                ? ($record->isReceipt() ? 'cash_receipt_vouchers' : 'cash_payment_vouchers') : 'cheques';
            $route = $record instanceof CashVoucher
                ? ($record->isReceipt() ? 'admin.finance.cash-receipt-vouchers.show' : 'admin.finance.cash-payment-vouchers.show') : 'admin.finance.cheques.show';
            if (! $request->user()?->can($resource.'.view') || ! $context['company_id']
                || (int) $record->company_id !== (int) $context['company_id'] || $record->trashed()) {
                return null;
            }

            return ['url' => route($route, [$record->doc_num, 'review_cancellation' => 1]), 'label' => __('cancellation_review.menu_action')];
        }
        $definition = $this->definitions()[$record::class] ?? null;
        if ($definition === null || ! $request->user()?->canAny($definition['view'])
            || ! $context['company_id'] || (int) $record->company_id !== (int) $context['company_id']
            || (method_exists($record, 'trashed') && $record->trashed())) {
            return null;
        }
        $route = match (true) {
            $record instanceof ProductionOrder => 'admin.production.work-orders.cancellation-owner',
            $record instanceof ProductionRun => 'admin.production.runs.cancellation-owner',
            $record instanceof ProductionMaterialRequest => 'admin.production.material-requests.show',
            $record instanceof ProductionExpenseRequest => 'admin.production.expenses.show',
            $record instanceof ProductionQualityInspection => 'admin.production.quality.show',
            $record instanceof SalesRequest => 'admin.sales.customer-requests.show',
            $record instanceof Quotation => 'admin.sales.quotations.show',
            $record instanceof SalesOrder => 'admin.sales.sales-orders.show',
            $record instanceof CustomerInvoice => 'admin.sales.sales-invoices.show',
            $record instanceof CustomerReceipt => 'admin.sales.customer-receipts.show',
            $record instanceof SalesReturn => 'admin.sales.sales-returns.show',
            $record instanceof PurchaseOrder => 'admin.purchases.purchase-orders.show',
            $record instanceof PurchaseRequisition => 'admin.purchases.purchase-requisitions.show',
            $record instanceof PurchaseInvoice => 'admin.purchases.purchase-invoices.show',
            $record instanceof RequestForQuotation => 'admin.purchases.request-for-quotations.show',
            $record instanceof SupplierQuotation => 'admin.purchases.supplier-quotation-entry.show',
            $record instanceof SupplierSelection => 'admin.purchases.supplier-selection.show',
            $record instanceof SupplyOrder => 'admin.purchases.supply-orders.show',
            $record instanceof SupplierPaymentContext => 'admin.purchases.supplier-payments.show',
            $record instanceof PurchaseReturn => 'admin.purchases.purchase-returns.show',
            $record instanceof UnpricedInventoryReceipt => 'admin.purchases.goods-receipt-notes.show',
            $record instanceof InventoryDocument && $record->document_type === InventoryDocument::TypeProductionHandover
                && $request->user()->canAny(['production.handovers.view', 'inventory.production_receipts.view']) => 'admin.production.handovers.show',
            $record instanceof InventoryDocument && $record->isHandoverWarehouseReceipt()
                && $request->user()->can('inventory.production_receipts.view') => 'admin.inventory.production-receipts.show',
            $record instanceof InventoryDocument && $record->document_type === InventoryDocument::TypeSalesIssue
                && $request->user()->can('sales_deliveries.view') => 'admin.sales.delivery-notes.show',
            $record instanceof InventoryDocument && $request->user()->can('inventory.documents.view') => 'admin.inventory.documents.show',
            default => null,
        };
        if ($route === null) {
            return null;
        }

        return ['url' => route($route, [$record instanceof ProductionQualityInspection ? $record->getKey() : $record, 'review_cancellation' => 1]),
            'label' => __('cancellation_review.menu_action')];
    }

    /** @return array<string, mixed>|null */
    public function review(Model $record, Request $request): ?array
    {
        $definition = $this->definitions()[$record::class] ?? null;
        $user = $request->user();
        $context = $this->context->snapshot($request);
        if ($definition === null || $user === null || ! $user->canAny($definition['view'])
            || ! $context['company_id'] || (int) $record->company_id !== (int) $context['company_id']) {
            return null;
        }

        $company = Company::query()->find($context['company_id']);
        $period = FinancialPeriod::query()->where('company_id', $record->company_id)->find($record->financial_period_id);
        $periodUnbound = $record instanceof Quotation && $record->financial_period_id === null;
        if ($company === null || ($period === null && ! $periodUnbound) || ! $this->scope->canAccessCompany($user, $company)
            || ($record->branch_id && ! $this->scope->allowedBranchQuery($user, [$company->doc_num])->whereKey($record->branch_id)->exists())
            || ($period !== null && ! $this->scope->canAccessFinancialPeriod($user, $period, $company))) {
            return null;
        }

        $blockers = [];
        $links = $this->relatedDocuments($record, $definition['relations'], $request, $company);
        $action = null;
        $sameBranch = (int) $record->branch_id === (int) $context['branch_id'];
        $samePeriod = $periodUnbound || (int) $record->financial_period_id === (int) $context['financial_period_id'];
        $historicalReversal = ($record instanceof PurchaseInvoice && ($record->isApproved() || $record->isClosed()))
            || $record instanceof RequestForQuotation || $record instanceof SupplierQuotation || $record instanceof SupplierSelection
            || ($record instanceof CustomerReceipt && $record->status === CustomerReceipt::StatusApproved)
            || ($record instanceof SupplierPaymentContext && $record->isApproved()) || $record instanceof PurchaseReturn;
        if (! $sameBranch || (! $samePeriod && ! $historicalReversal)) {
            $blockers[] = __('cancellation_review.context_required');
        }
        if (method_exists($record, 'trashed') && $record->trashed()) {
            $blockers[] = __('cancellation_review.deleted');
        } elseif (in_array($record->status, ['cancelled', 'reversed'], true)) {
            $blockers[] = __('cancellation_review.already_cancelled');
        } elseif ($sameBranch) {
            [$action, $ownerBlockers, $ownerLinks] = $this->ownerPlan($record, $request, $period);
            $blockers = [...$blockers, ...$ownerBlockers];
            $links = [...$links, ...$ownerLinks];
        }

        if ($action !== null && ! $user->can($action['permission'])) {
            $blockers[] = __('cancellation_review.permission_required');
            $action = null;
        }
        if ($blockers !== []) {
            $action = null;
        }

        return [
            'document' => $record->doc_num ?? $record->run_number ?? $record->public_id ?? $record->getRouteKey(),
            'status' => (string) $record->status,
            'period' => $period?->name ?? $period?->doc_num ?? __('cancellation_review.period_unbound'),
            'period_unbound' => $periodUnbound,
            'period_open' => $period !== null && ! $period->is_closed,
            'blockers' => array_values(array_unique($blockers)),
            'links' => collect($links)->unique(fn (array $link): string => $link['label'].'|'.$link['url'])->values()->all(),
            'action' => $action,
            'delete_action' => $sameBranch && $samePeriod && $period !== null && ! $period->is_closed
                && (! method_exists($record, 'trashed') || ! $record->trashed()) ? $this->deleteAction($record, $request) : null,
            'steps' => $this->manualOwnerSteps($record),
            'cancellation_audit' => ($record instanceof RequestForQuotation || $record instanceof SupplierQuotation || $record instanceof SupplierSelection || $record instanceof ProductionExpenseRequest)
                ? DB::table('activity_log')->where('subject_type', $record::class)->where('subject_id', $record->getKey())
                    ->where('company_id', $record->company_id)->where('event', $record instanceof ProductionExpenseRequest
                        ? 'production.expense.approval_withdrawn' : 'sourcing_document.cancelled')->latest('id')->first()
                : null,
        ];
    }

    /** @return array{0: array<string, mixed>|null, 1: list<string>, 2: list<array<string, mixed>>} */
    private function ownerPlan(Model $record, Request $request, ?FinancialPeriod $period): array
    {
        $links = [];
        $blockers = [];
        $action = null;
        $open = $period !== null && ! $period->is_closed;
        $branchType = Branch::query()->where('company_id', $record->company_id)->whereKey($record->branch_id)->value('type');

        if ($record instanceof Quotation) {
            if ($record->canCancel()) {
                $action = $this->action('admin.sales.quotations.cancel', $record, 'quotations.cancel');
            } else {
                $blockers[] = __('cancellation_review.downstream_or_approved');
            }
        } elseif ($record instanceof RequestForQuotation || $record instanceof SupplierQuotation || $record instanceof SupplierSelection) {
            $sourcing = app(ProcurementSourcingService::class);
            $blockers = $sourcing->sourcingCancellationBlockers($record);
            if ($record instanceof SupplierQuotation && $request->user()->can('purchases.supplier_selection.view')) {
                $companyDocNum = Company::query()->whereKey($record->company_id)->value('doc_num');
                foreach (SupplierSelection::query()->where('company_id', $record->company_id)
                    ->whereIn('branch_id', $this->scope->allowedBranchQuery($request->user(), [$companyDocNum])->select('branches.id'))
                    ->whereIn('financial_period_id', $this->scope->allowedFinancialPeriodQuery($request->user(), [$companyDocNum])->select('financial_periods.id'))
                    ->whereHas('lines.quotationLine', fn ($query) => $query->where('supplier_quotation_id', $record->getKey()))->limit(20)->get() as $selection) {
                    $links[] = ['label' => $selection->doc_num, 'url' => route('admin.purchases.supplier-selection.show', $selection), 'status' => $selection->status];
                }
            }
            $currentPeriod = $this->context->snapshot($request)['financial_period_id'];
            if (! FinancialPeriod::query()->where('company_id', $record->company_id)->whereKey($currentPeriod)->where('is_closed', false)->exists()) {
                $blockers[] = __('cancellation_review.current_period_open_required');
            }
            if ($blockers === []) {
                $route = match (true) {
                    $record instanceof RequestForQuotation => 'admin.purchases.request-for-quotations.cancel',
                    $record instanceof SupplierQuotation => 'admin.purchases.supplier-quotation-entry.cancel',
                    default => 'admin.purchases.supplier-selection.cancel',
                };
                $action = $this->action($route, $record, $sourcing->sourcingCancellationPermission($record));
            }
        } elseif ($record instanceof PurchaseInvoice && ($record->isApproved() || $record->isClosed())) {
            if ($request->user()->can('purchase_invoices.reverse') && $request->user()->can('purchases.prices.view')) {
                $plan = app(PurchaseInvoiceService::class)->reversalPlan($record);
                $blockers = $plan['blockers'];
                foreach (app(ProcurementCorrectionPlanService::class)->forInvoice($record, $request) as $step) {
                    if ($step['source_url'] && $step['kind'] !== 'invoice'
                        && $this->scope->allowedBranchQuery($request->user(), [$record->company->doc_num])->whereKey($step['branch_id'])->exists()
                        && $this->scope->allowedFinancialPeriodQuery($request->user(), [$record->company->doc_num])->whereKey($step['financial_period_id'])->exists()) {
                        $links[] = ['label' => $step['action'].' — '.$step['doc_num'], 'url' => $step['source_url'], 'status' => $step['status']];
                    }
                }
                if ($plan['can_reverse']) {
                    $action = $this->action('admin.purchases.purchase-invoices.reverse', $record, 'purchase_invoices.reverse', 'cancel_reason', true);
                }
            } else {
                $blockers[] = __('cancellation_review.permission_required');
            }
        } elseif ($record instanceof CustomerInvoice) {
            if ($open && $record->canCancelDraft()) {
                $action = $this->action('admin.sales.sales-invoices.cancel-draft', $record, 'customer_invoices.cancel');
            } elseif ($open && $record->canCancelDirectService()) {
                $action = $this->action('admin.sales.sales-invoices.cancel-direct-service', $record, 'customer_invoices.cancel');
            } else {
                $blockers[] = __('sales_ui.direct_service_cancel_ineligible');
            }
            if ($request->user()->canAny(['customer_invoices.correct_prepare', 'customer_invoices.correct_approve'])
                && $record->posting_status === 'posted') {
                $links[] = $this->link('cancellation_review.invoice_correction', 'admin.sales.sales-invoices.corrections.index', $record);
            }
        } elseif ($record instanceof CustomerReceipt && $record->status === CustomerReceipt::StatusApproved) {
            $withholding = CustomerWithholdingSettlement::query()->where('company_id', $record->company_id)
                ->where('customer_receipt_id', $record->getKey())->where('status', 'approved');
            if ($withholding->exists()) {
                $blockers[] = __('sales_ui.wht.recovery_required');
                if ($request->user()->can('customer_withholding_settlements.view')) {
                    $companyDocNum = Company::query()->whereKey($record->company_id)->value('doc_num');
                    foreach ($withholding->with('invoice')->whereHas('invoice', fn ($query) => $query
                        ->whereIn('branch_id', $this->scope->allowedBranchQuery($request->user(), [$companyDocNum])->select('branches.id'))
                        ->whereIn('financial_period_id', $this->scope->allowedFinancialPeriodQuery($request->user(), [$companyDocNum])->select('financial_periods.id')))->limit(20)->get() as $settlement) {
                        $links[] = $this->link('sales_ui.wht.reverse', 'admin.sales.sales-invoices.withholding.index', $settlement->invoice);
                    }
                }
            } elseif ($record->cheque && ! in_array($record->cheque->status, ['received', 'deposited'], true)) {
                $blockers[] = __('cancellation_review.cheque_owner_required');
            } else {
                $action = $this->action('admin.sales.customer-receipts.reverse', $record, 'customer_receipts.cancel', reversal: true);
            }
        } elseif ($record instanceof SupplierPaymentContext && in_array($record->status, ['draft', 'approved'], true)) {
            if ($record->cheque && $record->cheque->status === 'cleared') {
                $blockers[] = __('cancellation_review.cheque_owner_required');
            } elseif ($request->user()->can('purchases.prices.view')) {
                $action = $this->action('admin.purchases.supplier-payments.cancel', $record, 'supplier_payments.cancel', 'cancel_reason', $record->isApproved());
            } else {
                $blockers[] = __('cancellation_review.permission_required');
            }
        } elseif ($record instanceof PurchaseReturn) {
            if ($record->status === PurchaseReturn::StatusDraft) {
                $action = $this->action('admin.purchases.purchase-returns.cancel', $record, 'purchases.purchase_returns.cancel', 'cancel_reason');
            } elseif ($record->status === PurchaseReturn::StatusPosted) {
                $action = $this->action('admin.purchases.purchase-returns.reverse', $record, 'purchases.purchase_returns.reverse', 'reversal_reason', true);
            } else {
                $blockers[] = __('cancellation_review.owner_workflow');
            }
        } elseif ($record instanceof SalesOrder) {
            if ($open && $record->canCancelSafely()) {
                $action = $this->action('admin.sales.sales-orders.cancel', $record, 'sales_orders.cancel');
            } else {
                $blockers[] = __('cancellation_review.downstream_or_approved');
            }
        } elseif ($record instanceof SalesRequest) {
            if ($open && in_array($record->status, ['draft', 'submitted', 'rejected', 'reopened'], true)
                && (($record->approved_at === null && $record->closed_at === null)
                    || ($record->status === SalesRequest::StatusReopened && $record->isEditable()))
                && ! $record->hasConversionHistory()) {
                $action = $this->action('admin.sales.customer-requests.transition', $record, 'sales_requests.cancel');
                $action['fields'] = ['status' => 'cancelled'];
            } else {
                $blockers[] = __('cancellation_review.downstream_or_approved');
            }
        } elseif ($record instanceof PurchaseInvoice && $record->isDraft() && $open) {
            $action = $this->action('admin.purchases.purchase-invoices.cancel', $record, 'purchase_invoices.cancel', 'cancel_reason');
        } elseif ($record instanceof PurchaseOrder) {
            if ($open && $record->canCancelSafely()) {
                $action = $this->action('admin.purchases.purchase-orders.cancel', $record, 'purchase_orders.cancel', 'cancel_reason');
            } else {
                $blockers[] = __('cancellation_review.downstream_or_approved');
            }
        } elseif ($record instanceof PurchaseRequisition) {
            if ($open && $record->canCancelSafely()) {
                $action = $this->action('admin.purchases.purchase-requisitions.cancel', $record, 'purchases.purchase_requisitions.cancel', 'cancel_reason');
            } else {
                $blockers[] = __('cancellation_review.downstream_or_approved');
            }
        } elseif ($record instanceof SupplyOrder) {
            if ($open && $record->canCancelSafely()) {
                $action = $this->action('admin.purchases.supply-orders.cancel', $record, 'purchases.supply_orders.cancel', 'cancel_reason');
            } else {
                $blockers[] = __('cancellation_review.downstream_or_approved');
            }
        } elseif ($record instanceof SalesReturn) {
            if ($open && in_array($record->status, [SalesReturn::StatusPendingAuthorization, SalesReturn::StatusAuthorized], true)) {
                $action = $this->action('admin.sales.sales-returns.cancel', $record, 'sales_returns.cancel');
            } else {
                $blockers[] = __('cancellation_review.downstream_or_approved');
                if ($request->user()->canAny(['sales_returns.correct_prepare', 'sales_returns.correct_approve'])
                    && $request->user()->can('sales_returns.correct_later_period')) {
                    $links[] = $this->link('sales_return_plan.title', 'admin.sales.sales-returns.corrections.index', $record);
                }
            }
        } elseif ($record instanceof UnpricedInventoryReceipt && $record->purchase_order_id !== null) {
            if ($request->user()->can('purchases.goods_receipt_notes.reverse')) {
                $plan = app(ProcurementReceivingService::class)->receiptReversalPlan($record);
                $blockers = $plan['blockers'];
                if ($open && $plan['can_reverse'] && in_array($branchType, [Branch::TypeFactory, Branch::TypeWarehouse], true)) {
                    $action = $this->action('admin.purchases.goods-receipt-notes.reverse', $record, 'purchases.goods_receipt_notes.reverse', 'reversal_reason', true);
                } elseif ($blockers === []) {
                    $blockers[] = __('procurement.ui.inventory_context_required');
                }
            } else {
                $blockers[] = __('cancellation_review.owner_workflow');
            }
            foreach (app(ProcurementCorrectionPlanService::class)->forReceipt($record, $request) as $step) {
                if ($step['source_url'] && $step['kind'] !== 'receipt'
                    && $this->scope->allowedBranchQuery($request->user(), [$record->company->doc_num])->whereKey($step['branch_id'])->exists()
                    && $this->scope->allowedFinancialPeriodQuery($request->user(), [$record->company->doc_num])->whereKey($step['financial_period_id'])->exists()) {
                    $links[] = ['label' => $step['action'].' — '.$step['doc_num'], 'url' => $step['source_url'], 'status' => $step['status']];
                }
            }
        } elseif ($record instanceof ProductionMaterialRequest) {
            if ($open && $record->canCancelUnissued()) {
                $action = $this->action('admin.production.material-requests.cancel', $record, 'production.material_requests.cancel');
            } else {
                $blockers[] = __('cancellation_review.unissued_only');
                if ($record->lines()->where('issued_quantity', '>', 0)->exists() && $record->run
                    && $request->user()->can('production.runs.view')) {
                    $links[] = $this->link('cancellation_review.production_correction', 'admin.production.runs.cancellation-owner', $record->run);
                    if ($request->user()->can('production.runs.issue')) {
                        $links[] = ['label' => __('cancellation_review.return_unused_materials'),
                            'url' => route('admin.production.runs.operation', [$record->run, 'return'])];
                    }
                    $blockers[] = __('cancellation_review.issued_material_owner');
                }
            }
        } elseif ($record instanceof ProductionOrder) {
            if ($request->user()->can('production.orders.view')) {
                $links[] = $this->link('cancellation_review.menu_action', 'admin.production.work-orders.cancellation-owner', $record);
            }
            if ($open && $record->canAmendBeforeExecution()) {
                $action = $this->action('admin.production.work-orders.cancel', $record, 'production.orders.cancel');
            } else {
                $blockers[] = __('cancellation_review.unexecuted_only');
            }
        } elseif ($record instanceof ProductionRun) {
            if ($request->user()->can('production.runs.view')) {
                $links[] = $this->link('cancellation_review.menu_action', 'admin.production.runs.cancellation-owner', $record);
                if ($request->user()->canAny(['production.runs.correct', 'production.runs.correct_approve'])) {
                    $links[] = $this->link('production_stage_transfer.title', 'admin.production.runs.stage-transfers.index', $record);
                    $links[] = $this->link('production_stage_transfer.output_cost_title', 'admin.production.runs.stage-output-costs.index', $record);
                    foreach ($record->progressEntries()->whereNotNull('production_shift_entry_id')->whereNull('production_run_correction_id')->latest('id')->limit(20)->get() as $entry) {
                        $links[] = ['label' => __('production_daily_report.correction.title').' — '.$entry->public_id,
                            'url' => route('admin.production.runs.daily-reports.correction', [$record, $entry->public_id]), 'status' => ''];
                    }
                }
            }
            $record->loadMissing('requirements');
            $netIssued = $record->requirements->contains(fn ($line): bool => bccomp(
                bcadd((string) $line->issued_quantity, (string) $line->additional_issued_quantity, 8),
                (string) $line->returned_quantity, 8,
            ) > 0);
            if ($netIssued) {
                $blockers[] = __('cancellation_review.measured_return_required');
            }
            if (bccomp((string) $record->total_output_base_quantity, '0', 8) > 0) {
                $blockers[] = __('cancellation_review.recorded_output');
            }
            if ($record->expenseRequests()->whereNotIn('status', ['rejected', 'reversed'])->exists()) {
                $blockers[] = __('cancellation_review.expenses_active');
            }
            if ($record->materialRequests()->whereIn('status', ['draft', 'submitted', 'approved', 'shortage'])->exists()) {
                $blockers[] = __('cancellation_review.material_requests_active');
            }
            if ($record->status === ProductionRun::StatusCompleted) {
                $blockers[] = __('cancellation_review.completed_run');
                if ($record->material_accounting_mode === 'output_evidence') {
                    $blockers[] = __('production_execution.evidence.correction_requires_review');
                }
                if ($request->user()->canAny(['production.runs.correct', 'production.runs.correct_approve'])) {
                    $links[] = $this->link('cancellation_review.production_correction', 'admin.production.runs.corrections.index', $record);
                }
            } elseif ($open && $blockers === []) {
                $action = $this->action('admin.production.runs.cancel', $record, 'production.runs.cancel');
            }
        } elseif ($record instanceof InventoryDocument) {
            if ($record->document_type === InventoryDocument::TypeProductionHandover) {
                if ($open && in_array($record->status, [InventoryDocument::StatusDraft, InventoryDocument::StatusApproved], true)) {
                    $action = $this->action('admin.production.handovers.cancel', $record, 'production.handovers.cancel');
                } else {
                    $blockers[] = __('cancellation_review.production_owner');
                }
                if ($request->user()->can('production.handovers.view')) {
                    $links[] = $this->link('production_handover.title', 'admin.production.handovers.show', $record);
                }
            } elseif ($record->isHandoverWarehouseReceipt()) {
                if ($open && $record->status === InventoryDocument::StatusDraft) {
                    $action = $this->action('admin.inventory.production-receipts.cancel', $record, 'inventory.production_receipts.cancel');
                } else {
                    $blockers[] = __('cancellation_review.production_owner');
                }
                if ($record->status === InventoryDocument::StatusPosted && $request->user()->canAny(['inventory.production_receipts.correct_prepare', 'inventory.production_receipts.correct_approve'])) {
                    $links[] = $this->link('production_receipt_cancellation.title', 'admin.inventory.production-receipts.corrections.index', $record);
                }
            } elseif ($record->production_run_id !== null) {
                $blockers[] = __('cancellation_review.production_owner');
                if ($request->user()->can('production.runs.view') && $record->productionRun) {
                    $links[] = $this->link('cancellation_review.production_correction', 'admin.production.runs.show', $record->productionRun);
                }
                if ($record->document_type === InventoryDocument::TypeProductionReceipt && $record->productionRun
                    && $request->user()->canAny(['production.runs.correct', 'production.runs.correct_approve'])) {
                    $links[] = ['label' => __('production_receipt_cancellation.title'),
                        'url' => route('admin.production.runs.receipt-cancellations.index', [$record->productionRun, $record->doc_num])];
                }
            } elseif ($record->status === InventoryDocument::StatusPosted
                && $request->user()->can('inventory.documents.view') && $request->user()->can('inventory.documents.reverse')) {
                $links[] = $this->link('cancellation_review.exact_reversal', 'admin.inventory.documents.reversal-preview', $record);
                $blockers[] = __('cancellation_review.review_exact_reversal');
            } else {
                $blockers[] = __('cancellation_review.owner_workflow');
            }
        } elseif ($record instanceof ProductionExpenseRequest) {
            if ($open && $record->production_run_id !== null && $record->status === ProductionExpenseRequest::StatusApproved && $record->paid_at === null
                && $record->cash_voucher_id === null && $record->journal_entry_id === null && $record->cost_accounting_snapshot === null) {
                $action = $this->action('admin.production.expenses.withdraw-approval', $record, 'production.expenses.reverse');
                $action['label'] = __('cancellation_review.withdraw_expense_approval');
            } elseif ($open && $record->status === ProductionExpenseRequest::StatusPaid && $record->journal_entry_id !== null) {
                $action = $this->action('admin.production.expenses.reverse', $record, 'production.expenses.reverse', reversal: true);
            } else {
                $blockers[] = __('cancellation_review.owner_workflow');
            }
            if ($record->run && $request->user()->can('production.runs.view')) {
                $links[] = $this->link('production_stage_transfer.title', 'admin.production.runs.stage-transfers.index', $record->run);
                $links[] = $this->link('production_stage_transfer.output_cost_title', 'admin.production.runs.stage-output-costs.index', $record->run);
            }
        } elseif ($record instanceof ProductionQualityInspection && $record->run && $request->user()->can('production.runs.view')) {
            $blockers[] = __('cancellation_review.owner_workflow');
            $links[] = $this->link('cancellation_review.menu_action', 'admin.production.runs.cancellation-owner', $record->run);
        } else {
            $blockers[] = __('cancellation_review.owner_workflow');
        }

        if (! $open && ! ($record instanceof Quotation) && ! ($record instanceof PurchaseInvoice && ($record->isApproved() || $record->isClosed()))
            && ! ($record instanceof RequestForQuotation) && ! ($record instanceof SupplierQuotation) && ! ($record instanceof SupplierSelection)
            && ! ($record instanceof CustomerReceipt && $record->status === CustomerReceipt::StatusApproved)
            && ! ($record instanceof SupplierPaymentContext && $record->isApproved()) && ! ($record instanceof PurchaseReturn)) {
            $blockers[] = __('cancellation_review.closed_period');
        }
        if ($action !== null && ($record instanceof PurchaseInvoice || $record instanceof PurchaseOrder || $record instanceof SupplyOrder
            || $record instanceof RequestForQuotation || $record instanceof SupplierQuotation || $record instanceof SupplierSelection)) {
            if ($branchType !== Branch::TypeAdministrative) {
                $blockers[] = __('cancellation_review.administrative_branch');
            }
        }
        if ($action !== null && $record instanceof ProductionOrder && $branchType !== Branch::TypeFactory) {
            $blockers[] = __('production_execution.messages.factory_context_required');
        }
        if ($action !== null && $record instanceof SupplierPaymentContext && $branchType !== Branch::TypeAdministrative) {
            $blockers[] = __('cancellation_review.administrative_branch');
        }
        if ($action !== null && $record instanceof PurchaseReturn && ! in_array($branchType, [Branch::TypeFactory, Branch::TypeWarehouse], true)) {
            $blockers[] = __('procurement.ui.inventory_context_required');
        }
        if ($action !== null && ($record instanceof CustomerReceipt || $record instanceof SupplierPaymentContext || $record instanceof PurchaseReturn)) {
            $context = $this->context->snapshot($request);
            $companyDocNum = Company::query()->whereKey($record->company_id)->value('doc_num');
            if (! $this->scope->allowedFinancialPeriodQuery($request->user(), [$companyDocNum], openOnly: true)->whereKey($context['financial_period_id'])->exists()) {
                $blockers[] = __('cancellation_review.current_period_open_required');
            }
        }

        return [$action, $blockers, $links];
    }

    /** @return array{url: string, success_url: string}|null */
    private function deleteAction(Model $record, Request $request): ?array
    {
        $prefix = match (true) {
            $record instanceof CustomerInvoice && $record->canDeleteDraft()
                && ($record->status !== CustomerInvoice::StatusReopened || $request->user()->can('customer_invoices.cancel')) => ['customer_invoices', 'admin.sales.sales-invoices'],
            $record instanceof SalesOrder && $record->status === SalesOrder::StatusReopened && $record->isEditable()
                && $record->canCancelSafely() && $request->user()->can('sales_orders.cancel') => ['sales_orders', 'admin.sales.sales-orders'],
            $record instanceof SalesRequest && in_array($record->status, [SalesRequest::StatusDraft, SalesRequest::StatusReopened], true)
                && $record->isEditable() && ! $record->hasConversionHistory() => ['sales_requests', 'admin.sales.customer-requests'],
            $record instanceof PurchaseOrder && $record->isDeletable() => ['purchase_orders', 'admin.purchases.purchase-orders'],
            default => null,
        };
        if ($prefix === null || ! $request->user()->can($prefix[0].'.delete')) {
            return null;
        }

        return ['url' => route($prefix[1].'.destroy', $record), 'success_url' => route($prefix[1].'.index')];
    }

    /** @return array<string, mixed> */
    private function action(string $route, Model $record, string $permission, string $reasonField = 'reason', bool $reversal = false): array
    {
        return ['url' => route($route, $record), 'permission' => $permission, 'reason_field' => $reasonField,
            'reversal' => $reversal, 'fields' => []];
    }

    /** @return array{label: string, url: string, status: string} */
    private function link(string $label, string $route, Model $record): array
    {
        return ['label' => __($label).' — '.($record->doc_num ?? $record->run_number ?? $record->getRouteKey()),
            'url' => route($route, $record), 'status' => (string) $record->status];
    }

    /** @param list<array{0: string, 1: string, 2: string}> $relations @return list<array<string, mixed>> */
    private function relatedDocuments(Model $record, array $relations, Request $request, Company $company): array
    {
        $links = [];
        foreach ($relations as [$relation, $route, $permission]) {
            if (! $request->user()->can($permission) || ! method_exists($record, $relation)) {
                continue;
            }
            $query = $record->{$relation}();
            $related = $query->getRelated();
            $query->where($related->qualifyColumn('company_id'), $company->id);
            $query->where(function (Builder $query) use ($related, $request, $company): void {
                $query->whereNull($related->qualifyColumn('branch_id'))
                    ->orWhereIn($related->qualifyColumn('branch_id'),
                        $this->scope->allowedBranchQuery($request->user(), [$company->doc_num])->select('branches.id'));
            });
            if (! ($related instanceof Quotation)) {
                $query->where(function (Builder $query) use ($related, $request, $company): void {
                    $query->whereNull($related->qualifyColumn('financial_period_id'))
                        ->orWhereIn($related->qualifyColumn('financial_period_id'),
                            $this->scope->allowedFinancialPeriodQuery($request->user(), [$company->doc_num])->select('financial_periods.id'));
                });
            }
            foreach ($query->limit(20)->get() as $document) {
                $links[] = ['label' => (string) ($document->doc_num ?? $document->run_number ?? $document->getRouteKey()),
                    'url' => route($route, $document instanceof ProductionQualityInspection ? $document->getKey() : $document), 'status' => (string) $document->status];
            }
        }

        return $links;
    }

    /** @return list<string> */
    private function manualOwnerSteps(Model $record): array
    {
        if ($record instanceof CustomerInvoice || $record instanceof CustomerReceipt || $record instanceof SalesReturn) {
            return [__('cancellation_review.sales_step_evidence'), __('cancellation_review.sales_step_wht'), __('cancellation_review.sales_step_receipts'),
                __('cancellation_review.sales_step_returns'), __('cancellation_review.sales_step_invoice')];
        }
        if ($record instanceof PurchaseInvoice || $record instanceof PurchaseReturn || $record instanceof SupplierPaymentContext || $record instanceof UnpricedInventoryReceipt) {
            return [__('cancellation_review.purchase_step_evidence'), __('cancellation_review.purchase_step_returns'), __('cancellation_review.purchase_step_payments'),
                __('cancellation_review.purchase_step_invoice'), __('cancellation_review.purchase_step_receipt')];
        }

        return ($record instanceof RequestForQuotation || $record instanceof SupplierQuotation || $record instanceof SupplierSelection)
            ? [__('cancellation_review.sourcing_step_orders'), __('cancellation_review.sourcing_step_selection'), __('cancellation_review.sourcing_step_quotes'), __('cancellation_review.sourcing_step_rfq')]
            : [];
    }

    /** @return array<class-string<Model>, array{view: list<string>, relations: list<array{0: string, 1: string, 2: string}>}> */
    private function definitions(): array
    {
        return [
            SalesRequest::class => ['view' => ['sales_requests.view'], 'relations' => [
                ['quotations', 'admin.sales.quotations.show', 'quotations.view'],
                ['orders', 'admin.sales.sales-orders.show', 'sales_orders.view']]],
            Quotation::class => ['view' => ['quotations.view'], 'relations' => [
                ['salesOrders', 'admin.sales.sales-orders.show', 'sales_orders.view']]],
            SalesOrder::class => ['view' => ['sales_orders.view'], 'relations' => [
                ['invoices', 'admin.sales.sales-invoices.show', 'customer_invoices.view'],
                ['deliveries', 'admin.inventory.documents.show', 'inventory.documents.view'],
                ['productionOrders', 'admin.production.work-orders.show', 'production.orders.view']]],
            CustomerInvoice::class => ['view' => ['customer_invoices.view'], 'relations' => [
                ['order', 'admin.sales.sales-orders.show', 'sales_orders.view'],
                ['returns', 'admin.sales.sales-returns.show', 'sales_returns.view'],
                ['creditNotes', 'admin.sales.sales-invoices.show', 'customer_invoices.view'],
                ['deliveries', 'admin.inventory.documents.show', 'inventory.documents.view']]],
            CustomerReceipt::class => ['view' => ['customer_receipts.view'], 'relations' => [
                ['cashVoucher', 'admin.finance.cash-receipt-vouchers.show', 'cash_receipt_vouchers.view'],
                ['cheque', 'admin.finance.cheques.show', 'cheques.view']]],
            SalesReturn::class => ['view' => ['sales_returns.view'], 'relations' => [
                ['invoice', 'admin.sales.sales-invoices.show', 'customer_invoices.view'],
                ['creditNote', 'admin.sales.sales-invoices.show', 'customer_invoices.view']]],
            PurchaseRequisition::class => ['view' => ['purchases.purchase_requisitions.view'], 'relations' => [
                ['supplierQuotations', 'admin.purchases.supplier-quotation-entry.show', 'purchases.supplier_quotation_entry.view'],
                ['requestsForQuotation', 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view']]],
            RequestForQuotation::class => ['view' => ['purchases.request_for_quotations.view'], 'relations' => [
                ['supplierSelections', 'admin.purchases.supplier-selection.show', 'purchases.supplier_selection.view'],
                ['quotations', 'admin.purchases.supplier-quotation-entry.show', 'purchases.supplier_quotation_entry.view']]],
            SupplierQuotation::class => ['view' => ['purchases.supplier_quotation_entry.view'], 'relations' => [
                ['generatedPurchaseOrders', 'admin.purchases.purchase-orders.show', 'purchase_orders.view'],
                ['purchaseOrder', 'admin.purchases.purchase-orders.show', 'purchase_orders.view']]],
            SupplierSelection::class => ['view' => ['purchases.supplier_selection.view'], 'relations' => [
                ['purchaseOrders', 'admin.purchases.purchase-orders.show', 'purchase_orders.view'],
                ['requestForQuotation', 'admin.purchases.request-for-quotations.show', 'purchases.request_for_quotations.view']]],
            SupplyOrder::class => ['view' => ['purchases.supply_orders.view'], 'relations' => [
                ['purchaseInvoice', 'admin.purchases.purchase-invoices.show', 'purchase_invoices.view'],
                ['receipts', 'admin.purchases.goods-receipt-notes.show', 'purchases.goods_receipt_notes.view']]],
            PurchaseOrder::class => ['view' => ['purchase_orders.view'], 'relations' => [
                ['supplyOrders', 'admin.purchases.supply-orders.show', 'purchases.supply_orders.view'],
                ['purchaseInvoices', 'admin.purchases.purchase-invoices.show', 'purchase_invoices.view'],
                ['receipts', 'admin.purchases.goods-receipt-notes.show', 'purchases.goods_receipt_notes.view']]],
            PurchaseInvoice::class => ['view' => ['purchase_invoices.view'], 'relations' => [
                ['purchaseOrder', 'admin.purchases.purchase-orders.show', 'purchase_orders.view']]],
            UnpricedInventoryReceipt::class => ['view' => ['purchases.goods_receipt_notes.view', 'unpriced_inventory_receipts.view'], 'relations' => [
                ['supplyOrder', 'admin.purchases.supply-orders.show', 'purchases.supply_orders.view'],
                ['purchaseOrder', 'admin.purchases.purchase-orders.show', 'purchase_orders.view']]],
            PurchaseReturn::class => ['view' => ['purchases.purchase_returns.view'], 'relations' => [
                ['purchaseInvoice', 'admin.purchases.purchase-invoices.show', 'purchase_invoices.view']]],
            SupplierPaymentContext::class => ['view' => ['supplier_payments.view'], 'relations' => [
                ['purchaseOrder', 'admin.purchases.purchase-orders.show', 'purchase_orders.view'],
                ['cashVoucher', 'admin.finance.cash-payment-vouchers.show', 'cash_payment_vouchers.view'],
                ['cheque', 'admin.finance.cheques.show', 'cheques.view']]],
            ProductionMaterialRequest::class => ['view' => ['production.material_requests.view'], 'relations' => [
                ['run', 'admin.production.runs.show', 'production.runs.view'],
                ['purchaseRequisition', 'admin.purchases.purchase-requisitions.show', 'purchases.purchase_requisitions.view'],
                ['inventoryDocuments', 'admin.inventory.documents.show', 'inventory.documents.view']]],
            ProductionOrder::class => ['view' => ['production.orders.view'], 'relations' => [
                ['runs', 'admin.production.runs.show', 'production.runs.view']]],
            ProductionRun::class => ['view' => ['production.runs.view'], 'relations' => [
                ['inventoryDocuments', 'admin.inventory.documents.show', 'inventory.documents.view'],
                ['materialRequests', 'admin.production.material-requests.show', 'production.material_requests.view'],
                ['expenseRequests', 'admin.production.expenses.show', 'production.expenses.view'],
                ['inspections', 'admin.production.quality.show', 'production.quality.view']]],
            ProductionQualityInspection::class => ['view' => ['production.quality.view'], 'relations' => [
                ['run', 'admin.production.runs.show', 'production.runs.view']]],
            ProductionExpenseRequest::class => ['view' => ['production.expenses.view'], 'relations' => [
                ['run', 'admin.production.runs.show', 'production.runs.view']]],
            InventoryDocument::class => ['view' => ['inventory.documents.view', 'sales_deliveries.view', 'production.handovers.view', 'inventory.production_receipts.view'], 'relations' => [
                ['productionRun', 'admin.production.runs.show', 'production.runs.view'],
                ['productionMaterialRequest', 'admin.production.material-requests.show', 'production.material_requests.view']]],
        ];
    }
}
