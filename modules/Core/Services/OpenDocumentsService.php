<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Finance\Models\OpeningBalance;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionMaterialRequestService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceCorrectionService;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Throwable;

class OpenDocumentsService
{
    public const OpeningBalances = 'opening_balances';

    public const OpeningStocks = 'opening_stocks';

    public const OpeningStockCosts = 'opening_stock_cost_corrections';

    public const OpeningStockQuantities = 'opening_stock_quantity_corrections';

    public const OpeningStockPricings = 'opening_stock_pricings';

    public const SalesOrders = 'sales_orders';

    public const SalesRequests = 'sales_requests';

    public const CustomerInvoices = 'customer_invoices';

    public const PurchaseOrders = 'purchase_orders';

    public const PurchaseRequisitions = 'purchase_requisitions';

    public const PurchaseReceipts = 'purchase_receipts';

    public const PurchaseInvoices = 'purchase_invoices';

    public const InventoryMovements = 'inventory_movements';

    public const InventoryCorrections = 'inventory_movement_corrections';

    public const ProductionMaterialRequests = 'production_material_requests';

    public const ProductionRuns = 'production_runs';

    public const SalesReturns = 'sales_returns';

    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly ActivityLogger $activityLogger,
        private readonly OperatingContextService $operatingContext,
    ) {}

    /**
     * @return array<string, string>
     */
    public function documentTypes(?Request $request = null): array
    {
        return collect($this->handlers())
            ->filter(fn (array $handler, string $key): bool => isset($handler['workflow']) || ($handler['navigation_only'] ?? false)
                ? ($request !== null && $this->canExecute($request, $key))
                : (bool) $request?->user()?->can('tools.open_documents.view'))
            ->mapWithKeys(fn (array $handler, string $key): array => [$key => __($handler['label'])])
            ->all();
    }

    public function canView(Request $request): bool
    {
        return (bool) $request->user()?->can('tools.open_documents.view')
            || $this->hasWorkflowPermission($request);
    }

    public function canExecuteAny(Request $request): bool
    {
        return (bool) $request->user()?->can('tools.open_documents.execute')
            || $this->hasWorkflowPermission($request);
    }

    public function canExecute(Request $request, string $documentType): bool
    {
        $handler = $this->handlers()[$documentType] ?? null;
        if ($handler === null) {
            return (bool) $request->user()?->can('tools.open_documents.execute');
        }

        if (! isset($handler['workflow']) && ! ($handler['navigation_only'] ?? false)) {
            return (bool) $request->user()?->can('tools.open_documents.execute');
        }

        return (bool) $request->user()?->canAny($handler['permissions'] ?? [$handler['permission']])
            && collect($handler['required_permissions'] ?? [])->every(
                fn (string $permission): bool => (bool) $request->user()?->can($permission),
            );
    }

    private function hasWorkflowPermission(Request $request): bool
    {
        return collect($this->handlers())
            ->contains(fn (array $handler, string $key): bool => (isset($handler['workflow']) || ($handler['navigation_only'] ?? false))
                && $this->canExecute($request, $key));
    }

    /**
     * @return list<string>
     */
    public function supportedTypeKeys(): array
    {
        return array_keys($this->handlers());
    }

    /** @return array<string, mixed> */
    public function preview(string $documentType, int $fromNumber, int $toNumber, Request $request): array
    {
        $handler = $this->handler($documentType);
        $context = $this->currentContext($request);
        if (! $this->canExecute($request, $documentType)) {
            abort(403);
        }

        $context = $this->purchaseSourceContext($handler, $context, $request);

        $period = FinancialPeriod::query()->where('company_id', $context['company_id'])
            ->findOrFail($context['financial_period_id']);
        $records = $this->recordsQuery($handler, $context, $fromNumber, $toNumber)
            ->orderBy($handler['order_column'] ?? 'doc_number')->get();
        $correctionPlans = $this->correctionPlans($handler, $records);

        return [
            'document_type' => $documentType,
            'period' => $period->doc_num,
            'period_open' => ! $period->is_closed,
            'documents' => $records->map(fn (Model $record): array => $this->previewRecord(
                $record, $handler, $request, ! $period->is_closed, $correctionPlans[$record->getKey()] ?? null,
            ))->all(),
            'not_found' => max(0, ($toNumber - $fromNumber + 1) - ($documentType === self::ProductionRuns
                ? $records->pluck('order.doc_number')->unique()->count() : $records->count())),
            'navigation_only' => (bool) ($handler['navigation_only'] ?? false),
            'range_hint' => $documentType === self::ProductionRuns ? __('open_documents.messages.production_order_range') : null,
            'preview_token' => $this->previewToken($handler, $context, $fromNumber, $toNumber, $period, $records, $correctionPlans),
        ];
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     messages: list<string>,
     *     summary: array<string, int|string>
     * }
     */
    public function reopen(string $documentType, int $fromNumber, int $toNumber, Request $request, string $previewToken, ?string $reason = null): array
    {
        $handler = $this->handler($documentType);
        $context = $this->currentContext($request);

        if (! $this->canExecute($request, $documentType)) {
            abort(403);
        }

        if ($handler['navigation_only'] ?? false) {
            throw new \DomainException(__('open_documents.validation.use_correction_workflow'));
        }

        return DB::transaction(function () use ($handler, $context, $fromNumber, $toNumber, $request, $reason, $previewToken): array {
            if (in_array($handler['type'], [self::PurchaseReceipts, self::PurchaseInvoices], true)) {
                Company::query()->whereKey($context['company_id'])->lockForUpdate()->firstOrFail();
            }
            $context = $this->purchaseSourceContext($handler, $context, $request, true);
            $periodQuery = FinancialPeriod::query()->where('company_id', $context['company_id']);
            if (! isset($handler['workflow'])) {
                $periodQuery->lockForUpdate();
            }
            $period = $periodQuery->findOrFail($context['financial_period_id']);
            if ($period->is_closed) {
                throw new \DomainException(__('journal_entries.messages.period_closed'));
            }

            $records = $this->recordsQuery($handler, $context, $fromNumber, $toNumber)
                ->lockForUpdate()
                ->orderBy('doc_number')
                ->get();

            if (! hash_equals(
                $this->previewToken($handler, $context, $fromNumber, $toNumber, $period, $records),
                $previewToken,
            )) {
                throw new \DomainException(__('open_documents.validation.preview_stale'));
            }

            $summary = [
                'document_type' => $handler['type'],
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
                'total_found' => $records->count(),
                'opened' => 0,
                'skipped_approved' => 0,
                'skipped_already_open' => 0,
                'skipped_deleted' => 0,
                'skipped_blocked' => 0,
                'not_found' => max(0, ($toNumber - $fromNumber + 1) - $records->count()),
            ];

            foreach ($records as $record) {
                if ($record->trashed()) {
                    $summary['skipped_deleted']++;

                    continue;
                }

                if ($handler['type'] === self::PurchaseReceipts) {
                    if ($record->status === UnpricedInventoryReceipt::StatusReversed) {
                        $summary['skipped_already_open']++;

                        continue;
                    }
                    $plan = app(ProcurementReceivingService::class)->receiptReversalPlan($record);
                    if (! $plan['can_reverse']) {
                        $summary['skipped_blocked']++;

                        continue;
                    }
                    app(ProcurementReceivingService::class)->reverseReceipt($record, trim((string) $reason));
                    $summary['opened']++;

                    continue;
                }
                if ($handler['type'] === self::PurchaseInvoices) {
                    if ($record->reversal_journal_entry_id !== null) {
                        $summary['skipped_already_open']++;

                        continue;
                    }
                    $invoiceService = app(PurchaseInvoiceService::class);
                    if (! $invoiceService->reversalPlan($record)['can_reverse']) {
                        $summary['skipped_blocked']++;

                        continue;
                    }
                    $invoiceService->reverse($record, trim((string) $reason));
                    $summary['opened']++;

                    continue;
                }
                if ($handler['type'] === self::InventoryMovements) {
                    if ($record->status === InventoryDocument::StatusReversed) {
                        $summary['skipped_already_open']++;

                        continue;
                    }
                    if ($record->status !== InventoryDocument::StatusPosted
                        || ! app(InventoryDocumentPostingService::class)->reversalPlan($record)['can_reverse']) {
                        $summary['skipped_blocked']++;

                        continue;
                    }
                    app(InventoryDocumentPostingService::class)->reverse($record, trim((string) $reason));
                    $summary['opened']++;

                    continue;
                }

                if (isset($handler['workflow'])) {
                    if (in_array((string) $record->getAttribute('status'), ['draft', 'reopened'], true)) {
                        if (method_exists($record, 'isEditable') && ! $record->isEditable()) {
                            $summary['skipped_blocked']++;
                        } else {
                            $summary['skipped_already_open']++;
                        }

                        continue;
                    }

                    if (! $record->canReopenSafely()) {
                        $summary['skipped_blocked']++;

                        continue;
                    }

                    $oldStatus = (string) $record->getAttribute('status');
                    $this->reopenWorkflowDocument($handler['workflow'], $record, trim((string) $reason));
                    $summary['opened']++;
                    $this->logOpened($request, $record->refresh(), $handler, $context, $oldStatus, false);

                    continue;
                }

                if ($this->isApproved($record, $handler)) {
                    $summary['skipped_approved']++;

                    continue;
                }

                if (! $this->canReopen($record, $handler)) {
                    $summary['skipped_already_open']++;

                    continue;
                }

                $oldStatus = (string) $record->getAttribute('status');
                $oldIsClosed = (bool) $record->getAttribute('is_closed');

                $this->audit->saveUpdate($record, [
                    'is_closed' => false,
                    'status' => $handler['open_status'],
                ], $request->user()?->getKey());

                $summary['opened']++;
                $this->logOpened($request, $record->refresh(), $handler, $context, $oldStatus, $oldIsClosed);
            }

            $messages = $this->resultMessages($summary);
            if ($handler['type'] === self::PurchaseReceipts) {
                $messages[0] = $summary['opened'] > 0
                    ? __('open_documents.messages.receipts_reversed', ['count' => $summary['opened']])
                    : __('open_documents.messages.no_receipts_reversed');
            }
            if ($handler['type'] === self::InventoryMovements) {
                $messages[0] = $summary['opened'] > 0
                    ? __('open_documents.messages.movements_reversed', ['count' => $summary['opened']])
                    : __('open_documents.messages.no_movements_reversed');
            }
            if ($handler['type'] === self::PurchaseInvoices) {
                $messages[0] = $summary['opened'] > 0
                    ? __('open_documents.messages.invoices_reversed', ['count' => $summary['opened']])
                    : __('open_documents.messages.no_invoices_reversed');
            }

            return [
                'success' => $summary['opened'] > 0,
                ...($summary['opened'] === 0 ? ['type' => 'no_changes'] : []),
                'message' => $messages[0] ?? __('open_documents.messages.none_reopenable'),
                'messages' => $messages,
                'summary' => $summary,
            ];
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $handler
     * @return Builder<Model>
     */
    private function recordsQuery(array $handler, array $context, int $fromNumber, int $toNumber): Builder
    {
        /** @var class-string<Model> $model */
        $model = $handler['model'];

        $query = $model::withTrashed()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['source_financial_period_id'] ?? $context['financial_period_id'])
            ->when(isset($handler['workflow']) || ($handler['navigation_only'] ?? false), fn (Builder $query): Builder => $query->where('branch_id', $context['branch_id']));

        return $handler['type'] === self::ProductionRuns
            ? $query->with('order')->whereHas('order', fn (Builder $order): Builder => $order
                ->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])
                ->where('financial_period_id', $context['source_financial_period_id'] ?? $context['financial_period_id'])->whereBetween('doc_number', [$fromNumber, $toNumber]))
            : $query->whereBetween('doc_number', [$fromNumber, $toNumber]);
    }

    /**
     * @param  array<string, mixed>  $handler
     * @return array<string, mixed>
     */
    private function previewRecord(Model $record, array $handler, Request $request, bool $periodOpen, ?array $correctionPlan = null): array
    {
        $status = (string) $record->getAttribute('status');
        if ($handler['navigation_only'] ?? false) {
            return $this->correctionNavigationRecord($record, $handler, $request, $periodOpen);
        }
        if ($handler['type'] === self::CustomerInvoices && ! $record->canReopenSafely()
            && $request->user()?->canAny(['customer_invoices.correct_prepare', 'customer_invoices.correct_approve'])
            && ($periodOpen || $request->user()?->can('customer_invoices.correct_later_period'))) {
            $plan = app(CustomerInvoiceCorrectionService::class)->preview($record);
            $url = route('admin.sales.sales-invoices.corrections.index', $record);

            return ['doc_num' => $record->doc_num, 'doc_number' => (int) $record->doc_number, 'status' => $status,
                'status_label' => __('open_documents.statuses.'.$status), 'decision' => 'correction_workflow',
                'decision_label' => __('open_documents.decisions.correction_workflow'), 'source_url' => $url, 'correction_url' => $url,
                'current_total' => (string) $record->total_amount, 'line_count' => $record->lines()->count(),
                'dependent_documents' => [__('invoice_correction.invoices') => array_column($plan['snapshot']['invoices'], 'doc_num'),
                    __('invoice_correction.deliveries') => array_column($plan['snapshot']['documents'], 'doc_num')],
                'posting_effect' => __('open_documents.effects.review_correction'),
                'correction_steps' => array_map(fn ($step): array => ['doc_num' => $step['document'], 'action' => $step['action'],
                    'source_url' => $step['url'], 'correction_url' => $step['url'], 'permitted' => $request->user()->can($step['permission'])], $plan['dependencies'])];
        }
        if ($handler['type'] === self::PurchaseInvoices) {
            $plan = $correctionPlan ?? app(PurchaseInvoiceService::class)->reversalPlan($record);
            $decision = match (true) {
                ! $periodOpen => 'closed_period',
                $record->trashed() => 'deleted',
                $record->reversal_journal_entry_id !== null => 'already_corrected',
                $plan['can_reverse'] => 'ready_reverse',
                default => 'blocked',
            };

            return [
                'doc_num' => (string) $record->getAttribute('doc_num'),
                'doc_number' => (int) $record->getAttribute('doc_number'),
                'status' => $status,
                'status_label' => __('open_documents.statuses.'.$status),
                'decision' => $decision,
                'decision_label' => __('open_documents.decisions.'.$decision).($plan['blockers'] === [] ? '' : ' — '.implode('؛ ', $plan['blockers'])),
                'source_url' => $this->sourceUrl($record, self::PurchaseInvoices, $request),
                'current_total' => (string) $record->total_amount,
                'line_count' => $record->lines()->count(),
                'dependent_documents' => $plan['dependent_documents'],
                'posting_effect' => $decision === 'ready_reverse'
                    ? __('open_documents.effects.reverse_purchase_invoice')
                    : __('open_documents.effects.no_immediate_change'),
                'correction_lines' => $plan['lines'],
                'correction_steps' => $plan['correction_steps'] ?? [],
            ];
        }
        if ($handler['type'] === self::InventoryMovements) {
            $plan = $status === InventoryDocument::StatusPosted
                ? ($correctionPlan ?? app(InventoryDocumentPostingService::class)->reversalPlan($record))
                : ['can_reverse' => false, 'blockers' => [__('open_documents.corrections.movement_not_posted')], 'lines' => []];
            $dependentDocuments = collect($plan['lines'])
                ->flatMap(fn (array $line): array => $line['dependent_documents'] ?? [])
                ->unique()->values()->all();
            $decision = match (true) {
                ! $periodOpen => 'closed_period',
                $record->trashed() => 'deleted',
                $status === InventoryDocument::StatusReversed => 'already_corrected',
                $plan['can_reverse'] => 'ready_reverse',
                default => 'blocked',
            };

            return [
                'doc_num' => (string) $record->getAttribute('doc_num'),
                'doc_number' => (int) $record->getAttribute('doc_number'),
                'status' => $status,
                'status_label' => __('open_documents.statuses.'.$status),
                'decision' => $decision,
                'decision_label' => __('open_documents.decisions.'.$decision).($plan['blockers'] === [] ? '' : ' — '.implode('؛ ', $plan['blockers'])),
                'source_url' => $this->sourceUrl($record, self::InventoryMovements, $request),
                'current_total' => null,
                'line_count' => $record->lines()->count(),
                'dependent_documents' => $dependentDocuments === [] ? [] : [__('open_documents.dependents.inventoryDocuments') => $dependentDocuments],
                'posting_effect' => $decision === 'ready_reverse'
                    ? __('open_documents.effects.reverse_inventory_movement')
                    : __('open_documents.effects.no_immediate_change'),
                'correction_lines' => collect($plan['lines'])->map(fn (array $line): array => [
                    'product' => $line['product'],
                    'quantity' => bccomp($line['quantity_in'], '0', 8) > 0 ? '-'.$line['quantity_in'] : $line['quantity_out'],
                    'before_quantity' => $line['before_quantity'],
                    'after_quantity' => $line['after_quantity'],
                    'layer_available' => $line['original_layer_available'],
                    'value_delta' => $line['value_delta'],
                ])->all(),
            ];
        }
        if ($handler['type'] === self::PurchaseReceipts) {
            $plan = $correctionPlan ?? app(ProcurementReceivingService::class)->receiptReversalPlan($record);
            $decision = match (true) {
                ! $periodOpen => 'closed_period',
                $record->trashed() => 'deleted',
                $status === UnpricedInventoryReceipt::StatusReversed => 'already_corrected',
                $plan['can_reverse'] => 'ready_reverse',
                default => 'blocked',
            };

            return [
                'doc_num' => (string) $record->getAttribute('doc_num'),
                'doc_number' => (int) $record->getAttribute('doc_number'),
                'status' => $status,
                'status_label' => __('open_documents.statuses.'.$status),
                'decision' => $decision,
                'decision_label' => __('open_documents.decisions.'.$decision).($plan['blockers'] === [] ? '' : ' — '.implode('؛ ', $plan['blockers'])),
                'source_url' => $this->sourceUrl($record, self::PurchaseReceipts, $request),
                'current_total' => null,
                'line_count' => $record->lines()->count(),
                'dependent_documents' => $plan['dependent_documents'],
                'posting_effect' => $decision === 'ready_reverse'
                    ? __('open_documents.effects.reverse_purchase_receipt')
                    : __('open_documents.effects.no_immediate_change'),
                'correction_lines' => $plan['lines'],
                'correction_steps' => $plan['correction_steps'] ?? [],
            ];
        }
        $decision = match (true) {
            ! $periodOpen => 'closed_period',
            $record->trashed() => 'deleted',
            isset($handler['workflow']) && in_array($status, ['draft', 'reopened'], true) => method_exists($record, 'isEditable') && ! $record->isEditable()
                ? 'blocked'
                : 'already_open',
            isset($handler['workflow']) => $record->canReopenSafely() ? 'ready' : 'blocked',
            $this->isApproved($record, $handler) => 'approved',
            $this->canReopen($record, $handler) => 'ready',
            default => 'already_open',
        };

        return [
            'doc_num' => (string) $record->getAttribute('doc_num'),
            'doc_number' => (int) $record->getAttribute('doc_number'),
            'status' => $status,
            'status_label' => __('open_documents.statuses.'.$status),
            'decision' => $decision,
            'decision_label' => __('open_documents.decisions.'.$decision),
            'source_url' => $this->sourceUrl($record, (string) $handler['type'], $request),
            'current_total' => array_key_exists('total_amount', $record->getAttributes()) ? (string) $record->getAttribute('total_amount') : null,
            'line_count' => method_exists($record, 'lines') ? $record->lines()->count() : null,
            'dependent_documents' => $this->dependentDocuments($record, (string) $handler['type']),
            'posting_effect' => $decision === 'ready' && $handler['type'] === self::CustomerInvoices
                ? __('open_documents.effects.reverse_original_journal')
                : __('open_documents.effects.no_immediate_change'),
        ];
    }

    private function sourceUrl(Model $record, string $type, Request $request): ?string
    {
        [$routeName, $permissions] = match ($type) {
            self::OpeningBalances => ['admin.finance.opening-balances.show', ['opening_balances.view']],
            self::OpeningStocks => ['admin.inventory.opening-stocks.show', ['inventory.opening_stocks.view']],
            self::OpeningStockPricings => ['admin.inventory.opening-stock-pricings.show', ['inventory.opening_stock_pricings.view']],
            self::SalesOrders => ['admin.sales.sales-orders.show', ['sales_orders.view']],
            self::SalesRequests => ['admin.sales.customer-requests.show', ['sales_requests.view']],
            self::CustomerInvoices => ['admin.sales.sales-invoices.show', ['customer_invoices.view']],
            self::PurchaseOrders => ['admin.purchases.purchase-orders.show', ['purchases.prices.view', 'purchase_orders.view']],
            self::PurchaseReceipts => ['admin.purchases.goods-receipt-notes.show', ['purchases.goods_receipt_notes.view']],
            self::PurchaseInvoices => ['admin.purchases.purchase-invoices.show', ['purchases.prices.view', 'purchase_invoices.view']],
            self::InventoryMovements => ['admin.inventory.documents.show', ['inventory.documents.view']],
            self::PurchaseRequisitions => ['admin.purchases.purchase-requisitions.show', ['purchases.purchase_requisitions.view']],
            self::ProductionMaterialRequests => ['admin.production.material-requests.show', ['production.material_requests.view']],
            default => [null, []],
        };

        return $routeName !== null && ! $record->trashed()
            && collect($permissions)->every(fn (string $permission): bool => (bool) $request->user()?->can($permission))
            ? route($routeName, $record)
            : null;
    }

    /** @param array<string, mixed> $handler @return array<string, mixed> */
    private function correctionNavigationRecord(Model $record, array $handler, Request $request, bool $periodOpen): array
    {
        if ($handler['type'] === self::InventoryCorrections) {
            $available = ! $record->trashed() && in_array($record->status, [InventoryDocument::StatusPosted, InventoryDocument::StatusReversed], true)
                && app(InventoryMovementCorrectionService::class)->supportsSource($record);
            $url = $available ? route('admin.inventory.documents.corrections.index', $record) : null;

            return ['doc_num' => $record->doc_num, 'doc_number' => (int) $record->doc_number, 'status' => $record->status,
                'status_label' => __('open_documents.statuses.'.$record->status), 'decision' => $available ? 'correction_workflow' : 'blocked',
                'decision_label' => __('open_documents.decisions.'.($available ? 'correction_workflow' : 'blocked')),
                'source_url' => $url, 'correction_url' => $url, 'current_total' => null, 'line_count' => $record->lines()->count(),
                'dependent_documents' => [], 'correction_lines' => [], 'posting_effect' => __('inventory_correction.intro')];
        }
        if (in_array($handler['type'], [self::OpeningStockCosts, self::OpeningStockQuantities], true)) {
            $quantity = $handler['type'] === self::OpeningStockQuantities;
            $available = ! $record->trashed() && $record->isApproved();
            $decision = $record->trashed() ? 'deleted' : ($available ? 'correction_workflow' : 'blocked');
            $url = $available ? route($quantity ? 'admin.inventory.opening-stock-quantity-corrections.show'
                : 'admin.inventory.opening-stock-cost-corrections.show', $record) : null;

            return [
                'doc_num' => (string) $record->doc_num, 'doc_number' => (int) $record->doc_number,
                'status' => (string) $record->status, 'status_label' => __('open_documents.statuses.'.$record->status),
                'decision' => $decision, 'decision_label' => __('open_documents.decisions.'.$decision),
                'source_url' => $url, 'correction_url' => $url, 'current_total' => null,
                'line_count' => $record->lines()->count(), 'dependent_documents' => [],
                'posting_effect' => __($quantity ? 'open_documents.effects.review_opening_quantity' : 'open_documents.effects.review_opening_cost'), 'correction_lines' => [],
            ];
        }
        $production = $handler['type'] === self::ProductionRuns;
        $status = (string) $record->status;
        $permission = $production ? null : match ($status) {
            SalesReturn::StatusReceived => 'sales_returns.correct_receipt',
            SalesReturn::StatusInspected => 'sales_returns.correct_disposition',
            SalesReturn::StatusClosed => 'sales_returns.correct_closed',
            default => null,
        };
        $laterSalesCorrection = ! $production && ! $periodOpen && $request->user()?->can('sales_returns.correct_later_period')
            && ($request->user()?->can('sales_returns.correct_prepare') || $request->user()?->can('sales_returns.correct_approve'));
        $allowsPeriod = $periodOpen || $laterSalesCorrection || ($production && $request->user()?->can('production.runs.correct_later_period'));
        $available = ! $record->trashed() && $allowsPeriod
            && ($production ? $request->user()?->can('production.runs.view') : ($permission !== null && $request->user()?->can($permission)));
        $decision = match (true) {
            $record->trashed() => 'deleted',
            ! $allowsPeriod => 'closed_period',
            $available => 'correction_workflow',
            default => 'blocked',
        };
        $url = $available ? route($production ? 'admin.production.runs.cancellation-owner' : ($laterSalesCorrection ? 'admin.sales.sales-returns.corrections.index' : 'admin.sales.sales-returns.show'), $record) : null;
        $dependencies = [];
        $lines = [];
        $correctionSteps = [];
        if ($production && $available) {
            $plan = app(ProductionRunCorrectionService::class)->preview($record);
            $correctionSteps = $plan['correction_steps'];
            $documents = $plan['snapshot']['documents']->pluck('doc_num')->filter()->values()->all();
            if ($documents !== []) {
                $dependencies[__('open_documents.dependents.inventoryDocuments')] = $documents;
            }
            $lines = collect($plan['impact']['stock'])->map(fn (array $line): array => [
                'product' => $line['product'], 'quantity' => $line['quantity_change'], 'value_delta' => $line['value_change'],
            ])->all();
        } elseif (! $production) {
            foreach (['invoice', 'creditNote', 'delivery', 'returnInventoryDocument'] as $relation) {
                $document = $record->{$relation};
                if ($document !== null) {
                    $dependencies[__('open_documents.dependents.'.$relation)] = [(string) $document->doc_num];
                }
            }
        }

        return [
            'doc_num' => $production ? (string) $record->run_number : (string) $record->doc_num,
            'doc_number' => $production ? (int) $record->order->doc_number : (int) $record->doc_number,
            'status' => $status, 'status_label' => __('open_documents.statuses.'.$status),
            'decision' => $decision, 'decision_label' => __('open_documents.decisions.'.$decision),
            'source_url' => $url, 'correction_url' => $url, 'current_total' => $production ? null : (string) $record->total_amount,
            'line_count' => $production ? $record->requirements()->count() : $record->lines()->count(),
            'dependent_documents' => $dependencies, 'posting_effect' => __('open_documents.effects.review_correction'),
            'correction_lines' => $lines,
            'correction_steps' => $correctionSteps,
        ];
    }

    /** @return array<string, list<string>> */
    private function dependentDocuments(Model $record, string $type): array
    {
        $relations = match ($type) {
            self::SalesOrders => ['productionOrders', 'invoices', 'deliveries', 'receipts'],
            self::SalesRequests => ['quotations', 'orders', 'directInvoices'],
            self::CustomerInvoices => ['deliveries', 'returns', 'creditNotes'],
            self::PurchaseOrders => ['receipts', 'purchaseInvoices'],
            self::ProductionMaterialRequests => ['inventoryDocuments'],
            default => [],
        };
        $documents = [];
        foreach ($relations as $relation) {
            $numbers = $record->{$relation}()->limit(6)->get()->pluck('doc_num')->filter()->values()->all();
            if ($numbers !== []) {
                $documents[__('open_documents.dependents.'.$relation)] = $numbers;
            }
        }

        if ($type === self::PurchaseOrders) {
            $inspections = DB::table('goods_receipt_inspections')
                ->where('purchase_order_id', $record->getKey())
                ->limit(6)->pluck('doc_num')->filter()->values()->all();
            if ($inspections !== []) {
                $documents[__('open_documents.dependents.inspections')] = $inspections;
            }
        }

        return $documents;
    }

    /**
     * @param  array<string, mixed>  $handler
     * @param  array<string, int|string|null>  $context
     * @param  Collection<int, Model>  $records
     * @param  array<int, array<string, mixed>|null>|null  $correctionPlans
     */
    private function previewToken(array $handler, array $context, int $fromNumber, int $toNumber, FinancialPeriod $period, Collection $records, ?array $correctionPlans = null): string
    {
        $correctionPlans ??= $this->correctionPlans($handler, $records);
        $payload = [
            $handler['type'], $context['company_id'], $context['branch_id'], $context['financial_period_id'],
            $context['source_period_snapshot'] ?? null,
            $fromNumber, $toNumber, $period->getRawOriginal(),
            $records->map(fn (Model $record): array => [
                $record->getKey(), $record->getRawOriginal(),
                $correctionPlans[$record->getKey()] ?? null,
            ])->all(),
        ];

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $handler
     * @param  Collection<int, Model>  $records
     * @return array<int, array<string, mixed>|null>
     */
    private function correctionPlans(array $handler, Collection $records): array
    {
        return $records->mapWithKeys(fn (Model $record): array => [
            $record->getKey() => match ($handler['type']) {
                self::PurchaseReceipts => app(ProcurementReceivingService::class)->receiptReversalPlan($record),
                self::PurchaseInvoices => app(PurchaseInvoiceService::class)->reversalPlan($record),
                self::InventoryMovements => $record->status === InventoryDocument::StatusPosted
                    ? app(InventoryDocumentPostingService::class)->reversalPlan($record) : null,
                default => null,
            },
        ])->all();
    }

    private function reopenWorkflowDocument(string $workflow, Model $record, string $reason): void
    {
        if ($reason === '') {
            throw new \DomainException(__('open_documents.validation.reason_required'));
        }

        match ($workflow) {
            self::SalesRequests => app(SalesRequestService::class)->reopen($record, $reason),
            self::SalesOrders => app(SalesOrderService::class)->reopen($record, $reason),
            self::CustomerInvoices => app(CustomerInvoiceService::class)->reopen($record, $reason),
            self::PurchaseOrders => app(PurchaseOrderService::class)->reopen($record, $reason),
            self::PurchaseRequisitions => app(ProcurementSourcingService::class)->reopenRequisition($record, $reason),
            self::ProductionMaterialRequests => app(ProductionMaterialRequestService::class)->reopen($record, $reason),
            default => throw new \DomainException(__('open_documents.validation.invalid_document_type')),
        };
    }

    /**
     * @param  array<string, mixed>  $handler
     */
    private function isApproved(Model $record, array $handler): bool
    {
        if (array_key_exists('approved', $record->getAttributes()) && (bool) $record->getAttribute('approved')) {
            return true;
        }

        $approvedStatus = $handler['approved_status'] ?? null;

        return is_string($approvedStatus) && $approvedStatus !== '' && (string) $record->getAttribute('status') === $approvedStatus;
    }

    /**
     * @param  array<string, mixed>  $handler
     */
    private function canReopen(Model $record, array $handler): bool
    {
        if (! (bool) $record->getAttribute('is_closed')) {
            return false;
        }

        if ($this->hasJournalEntry($record)) {
            return false;
        }

        $status = (string) $record->getAttribute('status');

        if (in_array($status, $handler['blocked_statuses'] ?? [], true)) {
            return false;
        }

        $closedStatus = $handler['closed_status'] ?? null;

        return ! is_string($closedStatus) || $closedStatus === '' || $status === $closedStatus;
    }

    private function hasJournalEntry(Model $record): bool
    {
        return array_key_exists('journal_entry_id', $record->getAttributes())
            && $record->getAttribute('journal_entry_id') !== null;
    }

    /**
     * @param  array<string, int|string>  $summary
     * @return list<string>
     */
    private function resultMessages(array $summary): array
    {
        $messages = [];

        if ((int) $summary['opened'] > 0) {
            $messages[] = __('open_documents.messages.opened', ['count' => $summary['opened']]);
        } else {
            $messages[] = __('open_documents.messages.none_reopenable');
        }

        if ((int) $summary['skipped_approved'] > 0) {
            $messages[] = __('open_documents.messages.skipped_approved', ['count' => $summary['skipped_approved']]);
        }

        if ((int) $summary['skipped_already_open'] > 0) {
            $messages[] = __('open_documents.messages.skipped_already_open', ['count' => $summary['skipped_already_open']]);
        }

        if ((int) $summary['skipped_deleted'] > 0) {
            $messages[] = __('open_documents.messages.skipped_deleted', ['count' => $summary['skipped_deleted']]);
        }

        if ((int) $summary['skipped_blocked'] > 0) {
            $messages[] = __('open_documents.messages.skipped_blocked', ['count' => $summary['skipped_blocked']]);
        }

        if ((int) $summary['not_found'] > 0) {
            $messages[] = __('open_documents.messages.not_found', ['count' => $summary['not_found']]);
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $handler
     * @param  array<string, int|string|null>  $context
     */
    private function logOpened(Request $request, Model $record, array $handler, array $context, string $oldStatus, bool $oldIsClosed): void
    {
        try {
            $this->activityLogger->log($request, 'core', 'tools.open_documents.open', 'success', [
                'properties_only' => true,
                'properties' => ActivityLogProperties::statusChanged(
                    'tools.open_documents',
                    __($handler['label']),
                    $record->getAttribute('doc_num'),
                    $oldStatus,
                    $handler['open_status'],
                    [
                        'document_type' => $handler['type'],
                        'document_number' => $record->getAttribute('doc_number'),
                        'old_is_closed' => $oldIsClosed,
                        'new_is_closed' => false,
                        'company_doc_num' => $context['company_doc_num'],
                        'financial_period_doc_num' => $context['financial_period_doc_num'],
                    ],
                ),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array{company_id: int, branch_id: int, financial_period_id: int, company_doc_num: string|null, financial_period_doc_num: string|null}
     */
    private function currentContext(Request $request): array
    {
        $this->operatingContext->current($request);
        $context = $this->operatingContext->snapshot($request);

        if (! $context['company_id'] || ! $context['branch_id'] || ! $context['financial_period_id']) {
            throw new \DomainException(__('operating_context.messages.required'));
        }

        return [
            'company_id' => (int) $context['company_id'],
            'branch_id' => (int) $context['branch_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'company_doc_num' => $context['company_doc_num'],
            'financial_period_doc_num' => $context['financial_period_doc_num'],
        ];
    }

    /** @param array<string, mixed> $handler @param array<string, mixed> $context @return array<string, mixed> */
    private function purchaseSourceContext(array $handler, array $context, Request $request, bool $lock = false): array
    {
        $docNum = $request->input('source_period_doc_num');
        if ($docNum === null || $docNum === '') {
            return $context;
        }
        if (! is_string($docNum) || ! in_array($handler['type'], [self::PurchaseReceipts, self::PurchaseInvoices, self::ProductionRuns, self::InventoryCorrections], true)) {
            throw new \DomainException(__('open_documents.validation.source_period_invalid'));
        }
        $period = FinancialPeriod::query()->where('company_id', $context['company_id'])->where('doc_num', trim($docNum))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $company = Company::query()->findOrFail($context['company_id']);
        if (! $period || ! $request->user() || ! app(OperatingScopeAccessService::class)->canAccessFinancialPeriod($request->user(), $period, $company)) {
            throw new \DomainException(__('open_documents.validation.source_period_invalid'));
        }

        return [...$context, 'source_financial_period_id' => (int) $period->id, 'source_period_snapshot' => $period->getRawOriginal()];
    }

    /**
     * @return array{
     *     type: string,
     *     label: string,
     *     model: class-string<Model>,
     *     open_status: string,
     *     closed_status?: string|null,
     *     approved_status?: string|null,
     *     blocked_statuses?: list<string>
     * }
     */
    private function handler(string $documentType): array
    {
        $handlers = $this->handlers();

        if (! array_key_exists($documentType, $handlers)) {
            throw new \DomainException(__('open_documents.validation.invalid_document_type'));
        }

        return $handlers[$documentType];
    }

    /**
     * @return array<string, array{
     *     type: string,
     *     label: string,
     *     model: class-string<Model>,
     *     open_status: string,
     *     closed_status?: string|null,
     *     approved_status?: string|null,
     *     blocked_statuses?: list<string>
     * }>
     */
    private function handlers(): array
    {
        return [
            self::OpeningStockQuantities => [
                'type' => self::OpeningStockQuantities, 'label' => 'open_documents.documents.opening_stock_quantity_corrections',
                'model' => OpeningStock::class, 'navigation_only' => true,
                'permission' => 'inventory.opening_stock_quantity_corrections.prepare',
                'permissions' => ['inventory.opening_stock_quantity_corrections.prepare', 'inventory.opening_stock_quantity_corrections.approve'],
            ],
            self::OpeningStockCosts => [
                'type' => self::OpeningStockCosts, 'label' => 'open_documents.documents.opening_stock_cost_corrections',
                'model' => OpeningStock::class, 'navigation_only' => true,
                'permission' => 'inventory.opening_stock_cost_corrections.prepare',
                'permissions' => ['inventory.opening_stock_cost_corrections.prepare', 'inventory.opening_stock_cost_corrections.approve'],
            ],
            self::ProductionRuns => [
                'type' => self::ProductionRuns, 'label' => 'open_documents.documents.production_runs',
                'model' => ProductionRun::class, 'navigation_only' => true, 'order_column' => 'run_number',
                'permission' => 'production.runs.correct',
                'permissions' => ['production.runs.correct', 'production.runs.correct_approve'],
            ],
            self::SalesReturns => [
                'type' => self::SalesReturns, 'label' => 'open_documents.documents.sales_returns',
                'model' => SalesReturn::class, 'navigation_only' => true, 'permission' => 'sales_returns.correct_receipt',
                'permissions' => ['sales_returns.correct_receipt', 'sales_returns.correct_disposition', 'sales_returns.correct_closed', 'sales_returns.correct_prepare', 'sales_returns.correct_approve'],
                'required_permissions' => ['sales_returns.view'],
            ],
            self::OpeningBalances => [
                'type' => self::OpeningBalances,
                'label' => 'open_documents.documents.opening_balances',
                'model' => OpeningBalance::class,
                'open_status' => OpeningBalance::StatusDraft,
                'closed_status' => null,
                'approved_status' => OpeningBalance::StatusApproved,
                'blocked_statuses' => [
                    OpeningBalance::StatusCancelled,
                    OpeningBalance::StatusReversed,
                ],
            ],
            self::OpeningStocks => [
                'type' => self::OpeningStocks,
                'label' => 'open_documents.documents.opening_stocks',
                'model' => OpeningStock::class,
                'open_status' => OpeningStock::StatusDraft,
                'closed_status' => OpeningStock::StatusClosed,
                'approved_status' => OpeningStock::StatusApproved,
                'blocked_statuses' => [],
            ],
            self::OpeningStockPricings => [
                'type' => self::OpeningStockPricings,
                'label' => 'open_documents.documents.opening_stock_pricings',
                'model' => OpeningStockPricing::class,
                'open_status' => OpeningStockPricing::StatusDraft,
                'closed_status' => OpeningStockPricing::StatusClosed,
                'approved_status' => null,
                'blocked_statuses' => [],
            ],
            self::SalesOrders => [
                'type' => self::SalesOrders,
                'label' => 'open_documents.documents.sales_orders',
                'model' => SalesOrder::class,
                'open_status' => SalesOrder::StatusReopened,
                'workflow' => self::SalesOrders,
                'permission' => 'sales_orders.reopen',
            ],
            self::SalesRequests => [
                'type' => self::SalesRequests,
                'label' => 'open_documents.documents.sales_requests',
                'model' => SalesRequest::class,
                'open_status' => SalesRequest::StatusReopened,
                'workflow' => self::SalesRequests,
                'permission' => 'sales_requests.reopen',
            ],
            self::CustomerInvoices => [
                'type' => self::CustomerInvoices,
                'label' => 'open_documents.documents.customer_invoices',
                'model' => CustomerInvoice::class,
                'open_status' => CustomerInvoice::StatusReopened,
                'workflow' => self::CustomerInvoices,
                'permission' => 'customer_invoices.reopen',
                'permissions' => ['customer_invoices.reopen', 'customer_invoices.correct_prepare', 'customer_invoices.correct_approve'],
            ],
            self::PurchaseOrders => [
                'type' => self::PurchaseOrders,
                'label' => 'open_documents.documents.purchase_orders',
                'model' => PurchaseOrder::class,
                'open_status' => PurchaseOrder::StatusDraft,
                'workflow' => self::PurchaseOrders,
                'permission' => 'purchase_orders.reopen',
            ],
            self::PurchaseRequisitions => [
                'type' => self::PurchaseRequisitions,
                'label' => 'open_documents.documents.purchase_requisitions',
                'model' => PurchaseRequisition::class,
                'open_status' => PurchaseRequisition::StatusDraft,
                'workflow' => self::PurchaseRequisitions,
                'permission' => 'purchases.purchase_requisitions.reopen',
            ],
            self::ProductionMaterialRequests => [
                'type' => self::ProductionMaterialRequests,
                'label' => 'open_documents.documents.production_material_requests',
                'model' => ProductionMaterialRequest::class,
                'open_status' => ProductionMaterialRequest::StatusSubmitted,
                'workflow' => self::ProductionMaterialRequests,
                'permission' => 'production.material_requests.reopen',
            ],
            self::PurchaseReceipts => [
                'type' => self::PurchaseReceipts,
                'label' => 'open_documents.documents.purchase_receipts',
                'model' => UnpricedInventoryReceipt::class,
                'open_status' => UnpricedInventoryReceipt::StatusReversed,
                'workflow' => self::PurchaseReceipts,
                'permission' => 'purchases.goods_receipt_notes.reverse',
            ],
            self::PurchaseInvoices => [
                'type' => self::PurchaseInvoices,
                'label' => 'open_documents.documents.purchase_invoices',
                'model' => PurchaseInvoice::class,
                'open_status' => PurchaseInvoice::StatusCancelled,
                'workflow' => self::PurchaseInvoices,
                'permission' => 'purchase_invoices.reverse',
                'required_permissions' => ['purchases.prices.view'],
            ],
            self::InventoryMovements => [
                'type' => self::InventoryMovements,
                'label' => 'open_documents.documents.inventory_movements',
                'model' => InventoryDocument::class,
                'open_status' => InventoryDocument::StatusReversed,
                'workflow' => self::InventoryMovements,
                'permission' => 'inventory.documents.reverse',
            ],
            self::InventoryCorrections => [
                'type' => self::InventoryCorrections, 'label' => 'inventory_correction.title', 'model' => InventoryDocument::class,
                'open_status' => InventoryDocument::StatusReversed, 'navigation_only' => true,
                'permission' => 'inventory.documents.correct_prepare',
                'permissions' => ['inventory.documents.correct_prepare', 'inventory.documents.correct_approve'],
                'required_permissions' => ['inventory.documents.view'],
            ],
        ];
    }
}
