<?php

use Carbon\Carbon;
use Modules\Core\Models\Currency;
use Modules\Core\Models\Product;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\Production\Services\ProductionReceiptCancellationService;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ProductionPartialOutputEvidenceSupport.php';

/** @return array<string, mixed> */
function receiptCancellationFixture(array $parts = ['4', '6'], bool $factoryWorkflow = false, bool $tracksExpiry = false, bool $withPiece = false, bool $forSales = false): array
{
    $f = partialOutputFixture(factoryWorkflow: $factoryWorkflow, issueInitialMaterials: ! $forSales);
    if ($forSales) {
        $f['cycle']->cancelRun($f['run'], 'SYNTHETIC unused fixture run; sales demand gets its native run');
        $number = (int) Customer::withTrashed()->max('doc_number') + 1;
        $customer = Customer::query()->create(['company_id' => $f['company']->id, 'doc_number' => $number,
            'doc_num' => 'SYNTHETIC-RECEIPT-CUSTOMER-'.$number, 'name' => 'SYNTHETIC receipt reservation customer', 'status' => 'active']);
        $salesOrder = SalesOrder::query()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
            'branch_id' => $f['branch']->id, 'branch_store_id' => $f['store']->id, 'doc_number' => $number, 'doc_num' => 'SYNTHETIC-RECEIPT-SO-'.$number,
            'customer_id' => $customer->id, 'currency_id' => Currency::query()->where('company_id', $f['company']->id)->firstOrFail()->id,
            'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(),
            'status' => SalesOrder::StatusApproved, 'credit_status' => 'approved', 'subtotal_amount' => '100', 'total_amount' => '100']);
        $salesLine = $salesOrder->lines()->create(['line_number' => 1, 'product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id,
            'description' => $f['finished']->name, 'quantity' => '10', 'unit_price' => '10', 'line_total' => '100', 'conversion_factor' => '1', 'base_quantity' => '10',
            'product_classification_snapshot' => Product::ClassificationFinishedProduct]);
        $order = app(SalesProductionDemandService::class)->create($salesOrder, [['sales_order_line_id' => $salesLine->id, 'quantity' => '10']]);
        $order = $f['cycle']->releaseOrder($order);
        $orderLine = $order->lines->sole();
        $run = $f['cycle']->createRun($orderLine, ['planned_quantity' => '10', 'planned_start_at' => now(), 'planned_end_at' => now()->addHour(),
            'production_machine_id' => $f['machine']->id, 'production_mold_id' => $f['mold']->id, 'batch_lot' => 'SYNTHETIC-RECEIPT-SALES-LOT']);
        $requirement = $run->requirements->sole();
        $run = $f['cycle']->enableOutputEvidence($run, [['requirement_public_id' => $requirement->public_id, 'basis' => 'output_components']], 'physical_route');
        $f['cycle']->reserveRun($run, $f['store']->id);
        $f['cycle']->issueMaterials($run->fresh(), $f['store']->id);
        $run = $f['cycle']->startRun($f['cycle']->completeSetup($f['cycle']->startSetup($run->fresh())));
        $f = [...$f, ...compact('run', 'order', 'orderLine', 'requirement', 'salesLine', 'salesOrder')];
    }
    if ($withPiece) {
        $number = max(99271, (int) HrEmployee::withTrashed()->max('doc_number') + 1);
        $employee = HrEmployee::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
            'doc_number' => $number, 'doc_num' => 'SYNTHETIC-RECEIPT-PIECE-'.$number, 'employee_code' => 'SYNTHETIC-RECEIPT-PIECE-'.$number,
            'full_name' => 'SYNTHETIC piece receipt operator', 'name' => 'SYNTHETIC piece receipt operator', 'person_type' => 'regular_labor', 'status' => 'active',
            'hire_date' => '2026-01-01', 'contract_start_date' => '2026-01-01', 'pay_basis' => 'piece_rate', 'piece_rate' => '10']);
        HrPayrollAttendancePolicy::query()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
            'branch_scope_key' => 'branch:'.$f['branch']->id, 'effective_from' => '2026-01-01', 'piece_accrual_method' => HrPayrollAttendancePolicy::PieceApprovedOutput,
            'salary_day_divisor' => 30, 'standard_day_minutes' => 480, 'status' => 'active']);
        $f['cycle']->recordLabor($f['run'], ['actual_labor_count' => 1, 'labor_details' => [['employee_id' => $employee->id, 'actual_hours' => '1', 'piece_quantity' => '10']]]);
        $f['employee'] = $employee;
    }
    if ($tracksExpiry) {
        $f['finished']->update(['tracks_expiry' => true, 'default_shelf_life_days' => 180]);
    }
    foreach (['production.runs.correct', 'production.runs.correct_approve', 'production.runs.complete', 'production.runs.view', 'inventory.documents.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $receipts = [];
    foreach ($parts as $part) {
        if (is_array($part)) {
            test()->travelTo(Carbon::parse($part['date']));
        }
        $quantity = is_array($part) ? $part['quantity'] : $part;
        $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => $quantity]);
        partialOutputApprove($f, $quantity);
        $receipts[] = $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, $quantity);
    }
    $f['cycle']->completeRun($f['run']->fresh());
    $approver = closureSyntheticUser();
    $approver->givePermissionTo(['production.runs.correct_approve', 'production.runs.view', 'inventory.documents.view']);

    return [...$f, 'run' => $f['run']->fresh(), 'receipts' => $receipts, 'receipt' => $receipts[0], 'approver' => $approver];
}

/** @return array<string, mixed> */
function receiptCancellationPayload(array $f): array
{
    $preview = app(ProductionReceiptCancellationService::class)->preview($f['run']->fresh(), $f['receipt']->doc_num);

    return ['fingerprint' => $preview['fingerprint'], 'reason' => 'SYNTHETIC erroneous finished-goods receipt neutralization',
        'posting_date' => now()->toDateString(), 'correction_mode' => 'original_period'];
}
