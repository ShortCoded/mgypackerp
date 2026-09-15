<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Modules\Core\Services\OperationalNotificationService;
use Modules\Finance\Models\FundTransfer;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenancePlanDue;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;

class OperationalNotificationObserver
{
    public function __construct(
        private readonly OperationalNotificationService $notifications,
    ) {}

    public function created(Model $subject): void
    {
        if ($this->notifyOnCreation($subject)) {
            $this->notify($subject);
        }
    }

    public function updated(Model $subject): void
    {
        if ($subject->wasChanged('status')) {
            $this->notify($subject);
        }
    }

    private function notifyOnCreation(Model $subject): bool
    {
        return $this->definition($subject, (string) $subject->getAttribute('status')) !== null;
    }

    private function notify(Model $subject): void
    {
        $definition = $this->definition($subject, (string) $subject->getAttribute('status'));

        if ($definition === null) {
            return;
        }

        $module = $definition['module'];
        $status = (string) $subject->getAttribute('status');
        $documentNumber = (string) ($subject->getAttribute('doc_num') ?: $subject->getAttribute('public_uuid') ?: $subject->getKey());
        $urgent = ($subject instanceof MaintenanceRequest || $subject instanceof MaintenanceWorkOrder)
            && ((bool) $subject->getAttribute('is_machine_stopped') || $subject->getAttribute('priority') === 'urgent');
        $severity = $urgent ? 'urgent' : $definition['severity'];
        $soundKey = $severity === 'urgent' ? 'urgent' : ($definition['requires_action'] ? 'action' : null);

        $this->notifications->send(
            $subject,
            "{$module}.{$status}",
            __('notifications.operational.title', [
                'module' => __("notifications.modules.{$module}"),
                'status' => __("notifications.statuses.{$status}"),
            ]),
            __('notifications.operational.body', ['document' => $documentNumber]),
            $this->url($subject),
            $definition['permission'],
            $this->explicitUserIds($subject),
            [
                'document_number' => $documentNumber,
                'status' => $status,
                'financial_period_id' => $subject->getAttribute('financial_period_id'),
                'result' => $subject instanceof GoodsReceiptInspection || $subject instanceof ProductionQualityInspection
                    ? $subject->getAttribute('result')
                    : null,
            ],
            $severity,
            $definition['requires_action'],
            $soundKey,
            __('notifications.operational.external_body', ['document' => $documentNumber]),
            $this->viewPermission($subject),
        );
    }

    /**
     * @return array{module: string, permission: string|null, severity: string, requires_action: bool}|null
     */
    private function definition(Model $subject, string $status): ?array
    {
        return match (true) {
            $subject instanceof PurchaseRequisition => $this->byStatus('purchases', $status, [
                PurchaseRequisition::StatusSubmitted => ['purchases.purchase_requisition_approvals.approve', true],
                PurchaseRequisition::StatusApproved => ['purchases.request_for_quotations.create', true],
                PurchaseRequisition::StatusRejected => ['purchases.purchase_requisitions.view', false],
                PurchaseRequisition::StatusPartiallyConverted => ['purchases.purchase_requisitions.view', false],
                PurchaseRequisition::StatusFullyConverted => ['purchases.purchase_requisitions.view', false],
                PurchaseRequisition::StatusClosed => ['purchases.purchase_requisitions.view', false],
                PurchaseRequisition::StatusCancelled => ['purchases.purchase_requisitions.view', false],
            ]),
            $subject instanceof PurchaseOrder => $this->byStatus('purchases', $status, [
                PurchaseOrder::StatusSubmitted => ['purchase_orders.approve', true],
                PurchaseOrder::StatusApproved => ['purchases.goods_receipt_inspection.create', true],
                PurchaseOrder::StatusRejected => ['purchase_orders.view', false],
                PurchaseOrder::StatusClosed => ['purchase_orders.view', false],
                PurchaseOrder::StatusCancelled => ['purchase_orders.view', false],
            ]),
            $subject instanceof GoodsReceiptInspection && $status === 'finalized' => [
                'module' => 'quality',
                'permission' => 'purchases.goods_receipt_notes.create',
                'severity' => in_array($subject->getAttribute('result'), ['rejected', 'partially_accepted'], true) ? 'urgent' : 'action',
                'requires_action' => true,
            ],
            $subject instanceof SupplyOrder => $this->byStatus('purchases', $status, [
                SupplyOrder::StatusIssued => ['purchases.goods_receipt_inspection.create', true],
                SupplyOrder::StatusPartiallyReceived => ['purchases.goods_receipt_inspection.create', true],
                SupplyOrder::StatusFullyReceived => ['purchases.supply_orders.view', false],
                SupplyOrder::StatusClosed => ['purchases.supply_orders.view', false],
            ]),
            $subject instanceof ProductionMaterialRequest => $this->byStatus('inventory', $status, [
                ProductionMaterialRequest::StatusSubmitted => ['production.material_requests.approve', true],
                ProductionMaterialRequest::StatusApproved => ['production.material_requests.issue', true],
                ProductionMaterialRequest::StatusShortage => ['production.material_requests.approve', true],
                ProductionMaterialRequest::StatusPartiallyIssued => ['production.material_requests.issue', true],
                ProductionMaterialRequest::StatusIssued => ['production.material_requests.view', false],
                ProductionMaterialRequest::StatusRejected => ['production.material_requests.view', false],
                ProductionMaterialRequest::StatusCancelled => ['production.material_requests.view', false],
            ]),
            $subject instanceof ProductionOrder => $this->byStatus('production', $status, [
                ProductionOrder::StatusReleased => ['production.runs.plan', true],
                ProductionOrder::StatusInProgress => ['production.orders.view', false],
                ProductionOrder::StatusPartiallyCompleted => ['production.quality.create', true],
                ProductionOrder::StatusCompleted => ['production.quality.create', true],
                ProductionOrder::StatusShortClosed => ['production.orders.view', false],
                ProductionOrder::StatusCancelled => ['production.orders.view', false],
            ]),
            $subject instanceof ProductionRun => $this->byStatus('production', $status, [
                ProductionRun::StatusPlanned => ['production.runs.setup', true],
                ProductionRun::StatusSetup => ['production.runs.setup', true],
                ProductionRun::StatusReady => ['production.runs.setup', true],
                ProductionRun::StatusRunning => ['production.runs.view', false],
                ProductionRun::StatusHeld => ['production.runs.qc', true],
                ProductionRun::StatusCompleted => ['production.runs.receive', true],
                ProductionRun::StatusCancelled => ['production.runs.view', false],
            ]),
            $subject instanceof ProductionQualityInspection => $this->byStatus('quality', $status, [
                ProductionQualityInspection::StatusDraft => ['production.quality.start', true],
                ProductionQualityInspection::StatusReceived => ['production.quality.start', true],
                ProductionQualityInspection::StatusSubmitted => ['production.quality.review', true],
                ProductionQualityInspection::StatusApproved => ['production.runs.receive', true],
                ProductionQualityInspection::StatusRejected => ['production.runs.qc', true],
                ProductionQualityInspection::StatusClosed => ['production.quality.view', false],
            ], $status === ProductionQualityInspection::StatusRejected ? 'urgent' : 'action'),
            $subject instanceof ProductionExpenseRequest => $this->byStatus('finance', $status, [
                ProductionExpenseRequest::StatusSubmitted => ['production.expenses.approve', true],
                ProductionExpenseRequest::StatusApproved => ['production.expenses.pay', true],
                ProductionExpenseRequest::StatusPaid => ['production.expenses.view', false],
                ProductionExpenseRequest::StatusRejected => ['production.expenses.view', false],
                ProductionExpenseRequest::StatusReversed => ['production.expenses.view', false],
            ]),
            $subject instanceof MaintenanceRequest => $this->byStatus('maintenance', $status, [
                MaintenanceRequest::StatusOpen => ['maintenance.orders.create', true],
                MaintenanceRequest::StatusConverted => ['maintenance.requests.view', false],
                MaintenanceRequest::StatusClosed => ['maintenance.requests.view', false],
            ]),
            $subject instanceof MaintenanceWorkOrder => $this->byStatus('maintenance', $status, [
                MaintenanceWorkOrder::StatusApproved => ['maintenance.orders.start', true],
                MaintenanceWorkOrder::StatusInProgress => ['maintenance.orders.view', false],
                MaintenanceWorkOrder::StatusCompleted => ['maintenance.orders.close', true],
                MaintenanceWorkOrder::StatusClosed => ['maintenance.orders.view', false],
                MaintenanceWorkOrder::StatusCancelled => ['maintenance.orders.view', false],
            ]),
            $subject instanceof MaintenancePlanDue => $this->byStatus('maintenance', $status, [
                MaintenancePlanDue::StatusOpen => ['maintenance.plans.execute', true],
                MaintenancePlanDue::StatusConverted => ['maintenance.plans.view', false],
                MaintenancePlanDue::StatusCompleted => ['maintenance.plans.view', false],
            ]),
            $subject instanceof MaintenanceMaterialRequest => $this->byStatus('inventory', $status, [
                MaintenanceMaterialRequest::StatusSubmitted => ['maintenance.material_requests.approve', true],
                MaintenanceMaterialRequest::StatusApproved => ['maintenance.material_requests.issue', true],
                MaintenanceMaterialRequest::StatusIssued => ['maintenance.material_requests.view', false],
                MaintenanceMaterialRequest::StatusPartiallyReturned => ['maintenance.material_requests.return', true],
                MaintenanceMaterialRequest::StatusReturned => ['maintenance.material_requests.view', false],
            ]),
            $subject instanceof InventoryDocument => $this->byStatus('inventory', $status, [
                InventoryDocument::StatusPosted => ['inventory.documents.view', true],
                InventoryDocument::StatusReversed => ['inventory.documents.view', false],
                InventoryDocument::StatusCancelled => ['inventory.documents.view', false],
            ]),
            $subject instanceof FundTransfer => $this->byStatus('finance', $status, [
                FundTransfer::StatusApproved => ['fund_transfers.view', false],
                FundTransfer::StatusCancelled => ['fund_transfers.view', false],
            ]),
            $subject instanceof SalesOrder => $this->byStatus('sales', $status, [
                SalesOrder::StatusPendingApproval => ['sales_orders.approve', true],
                SalesOrder::StatusHeldCredit => ['sales_orders.credit_override', true],
                SalesOrder::StatusApproved => ['sales_orders.reserve', true],
                SalesOrder::StatusPartiallyFulfilled => ['sales_orders.view', true],
                SalesOrder::StatusFulfilled => ['sales_orders.view', false],
                SalesOrder::StatusRejected => ['sales_orders.view', false],
                SalesOrder::StatusReopened => ['sales_orders.edit', true],
                SalesOrder::StatusCancelled => ['sales_orders.view', false],
                SalesOrder::StatusClosed => ['sales_orders.view', false],
            ]),
            $subject instanceof SalesReturn => $this->byStatus('sales', $status, [
                SalesReturn::StatusPendingAuthorization => ['sales_returns.authorize', true],
                SalesReturn::StatusAuthorized => ['sales_returns.receive', true],
                SalesReturn::StatusReceived => ['sales_returns.inspect', true],
                SalesReturn::StatusInspected => ['sales_returns.close', true],
                SalesReturn::StatusClosed => ['sales_returns.view', false],
                SalesReturn::StatusCancelled => ['sales_returns.view', false],
            ]),
            $subject instanceof HrEmployeeServiceRequest => $this->byStatus('hr', $status, [
                HrEmployeeServiceRequest::StatusSubmitted => ['hr.hr_requests.manage', true],
                HrEmployeeServiceRequest::StatusApproved => ['hr.hr_requests.view', false],
                HrEmployeeServiceRequest::StatusRejected => ['hr.hr_requests.view', false],
                HrEmployeeServiceRequest::StatusCancelled => ['hr.hr_requests.view', false],
            ]),
            $subject instanceof FixedAsset => $this->byStatus('assets', $status, [
                FixedAsset::StatusSuspended => ['fixed_assets.view', true],
                FixedAsset::StatusDisposed => ['fixed_assets.view', false],
                FixedAsset::StatusSold => ['fixed_assets.view', false],
                FixedAsset::StatusWrittenOff => ['fixed_assets.view', false],
            ]),
            default => null,
        };
    }

    /**
     * @param  array<string, array{0: string, 1: bool}>  $definitions
     * @return array{module: string, permission: string, severity: string, requires_action: bool}|null
     */
    private function byStatus(string $module, string $status, array $definitions, string $severity = 'action'): ?array
    {
        $definition = $definitions[$status] ?? null;

        if ($definition === null) {
            return null;
        }

        return [
            'module' => $module,
            'permission' => $definition[0],
            'severity' => $definition[1] ? $severity : 'information',
            'requires_action' => $definition[1],
        ];
    }

    /** @return list<int|null> */
    private function explicitUserIds(Model $subject): array
    {
        $ids = [];

        foreach (['created_by', 'requested_by', 'submitted_by', 'reported_by', 'inspected_by', 'inspector_id'] as $attribute) {
            $value = $subject->getAttribute($attribute);
            $ids[] = is_numeric($value) ? (int) $value : null;
        }

        if ($subject instanceof HrEmployeeServiceRequest) {
            $ids[] = $subject->employee()->value('user_id');
        }

        return $ids;
    }

    private function url(Model $subject): ?string
    {
        [$routeName, $parameter] = match (true) {
            $subject instanceof PurchaseRequisition => ['admin.purchases.purchase-requisitions.show', $subject],
            $subject instanceof PurchaseOrder => ['admin.purchases.purchase-orders.show', $subject],
            $subject instanceof GoodsReceiptInspection => ['admin.purchases.goods-receipt-inspection.show', $subject],
            $subject instanceof SupplyOrder => ['admin.purchases.supply-orders.show', $subject],
            $subject instanceof ProductionMaterialRequest => ['admin.production.material-requests.index', null],
            $subject instanceof ProductionOrder => ['admin.production.work-orders.show', $subject],
            $subject instanceof ProductionRun => ['admin.production.runs.show', $subject],
            $subject instanceof ProductionQualityInspection => ['admin.production.quality.show', $subject],
            $subject instanceof ProductionExpenseRequest => ['admin.production.expenses.index', null],
            $subject instanceof MaintenanceRequest => ['admin.maintenance.requests.index', null],
            $subject instanceof MaintenanceWorkOrder => ['admin.maintenance.orders.show', $subject],
            $subject instanceof MaintenancePlanDue => ['admin.maintenance.plans.index', null],
            $subject instanceof MaintenanceMaterialRequest => ['admin.maintenance.material-requests.index', null],
            $subject instanceof InventoryDocument => ['admin.inventory.documents.show', $subject],
            $subject instanceof FundTransfer => ['admin.finance.fund-transfers.show', $subject],
            $subject instanceof SalesOrder => ['admin.sales.sales-orders.show', $subject],
            $subject instanceof SalesReturn => ['admin.sales.sales-returns.show', $subject],
            $subject instanceof HrEmployeeServiceRequest => ['admin.hr.hr-requests.index', null],
            $subject instanceof FixedAsset => ['admin.fixed-assets.assets.show', $subject],
            default => [null, null],
        };

        if (! is_string($routeName) || ! Route::has($routeName)) {
            return route('dashboard', [], false);
        }

        return $parameter instanceof Model
            ? route($routeName, $parameter, false)
            : route($routeName, [], false);
    }

    private function viewPermission(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof PurchaseRequisition => 'purchases.purchase_requisitions.view',
            $subject instanceof PurchaseOrder => 'purchase_orders.view',
            $subject instanceof GoodsReceiptInspection => 'purchases.goods_receipt_inspection.view',
            $subject instanceof SupplyOrder => 'purchases.supply_orders.view',
            $subject instanceof ProductionMaterialRequest => 'production.material_requests.view',
            $subject instanceof ProductionOrder => 'production.orders.view',
            $subject instanceof ProductionRun => 'production.runs.view',
            $subject instanceof ProductionQualityInspection => 'production.quality.view',
            $subject instanceof ProductionExpenseRequest => 'production.expenses.view',
            $subject instanceof MaintenanceRequest => 'maintenance.requests.view',
            $subject instanceof MaintenanceWorkOrder => 'maintenance.orders.view',
            $subject instanceof MaintenancePlanDue => 'maintenance.plans.view',
            $subject instanceof MaintenanceMaterialRequest => 'maintenance.material_requests.view',
            $subject instanceof InventoryDocument => 'inventory.documents.view',
            $subject instanceof FundTransfer => 'fund_transfers.view',
            $subject instanceof SalesOrder => 'sales_orders.view',
            $subject instanceof SalesReturn => 'sales_returns.view',
            $subject instanceof HrEmployeeServiceRequest => 'hr.hr_requests.view',
            $subject instanceof FixedAsset => 'fixed_assets.view',
            default => null,
        };
    }
}
