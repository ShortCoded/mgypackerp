<?php

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Services\SettingService;
use Modules\Inventory\Exports\InventoryReportExport;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Maintenance\Exports\MaintenanceOperationsReportExport;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Exports\ProductionReportExport;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Exports\SalesCycleReportExport;
use PhpOffice\PhpSpreadsheet\IOFactory;

test('operational report date cells follow configured formats in actual workbooks', function (string $dateFormat, string $timeFormat, string $expectedDate, string $expectedTime): void {
    app(SettingService::class)->set('date_format', $dateFormat);
    app(SettingService::class)->set('date_time_format', $timeFormat);
    $date = Carbon::parse('2026-05-10 15:04:00');
    $transaction = new InventoryTransaction(['transaction_date' => $date, 'source_doc_num' => 'SYNTHETIC-DATE',
        'transaction_type' => 'purchase_receipt', 'stock_status' => 'available', 'quantity_in' => '1', 'quantity_out' => '0']);
    $layer = (object) ['original_receipt_date' => $date, 'expiry_date' => $date, 'manufacture_date' => $date,
        'age_days' => 0, 'age_bucket' => '0-30', 'source_doc_num' => 'SYNTHETIC-DATE', 'branchStore' => null,
        'product' => null, 'stock_status' => 'available', 'batch_lot' => null, 'remaining_quantity' => '1',
        'expiry_state' => 'expiring', 'days_to_expiry' => 0];
    $variance = (object) ['stockCount' => (object) ['doc_num' => 'SYNTHETIC-COUNT', 'count_date' => $date, 'branchStore' => null],
        'product' => null, 'stock_status' => 'available', 'batch_lot' => null, 'system_quantity' => '1',
        'physical_quantity' => '0', 'variance_quantity' => '-1', 'variance_reason' => 'SYNTHETIC'];
    $inventory = new InventoryReportExport(['balances' => collect(), 'reservations' => collect(),
        'movements' => collect([$transaction]), 'qualityBalances' => collect(), 'damageAndScrap' => collect([$transaction]),
        'stockCountVariances' => collect([$variance]), 'reorder' => collect(), 'agingLayers' => collect([$layer]),
        'expiryLayers' => collect([$layer]), 'reportTotals' => ['on_hand' => '1', 'quantity_in' => '1', 'quantity_out' => '0']]);
    $run = new ProductionRun(['run_number' => 'SYNTHETIC-RUN', 'actual_start_at' => $date, 'actual_end_at' => $date,
        'planned_start_at' => $date, 'status' => 'completed', 'report_recorded_base_quantity' => '0', 'report_received_base_quantity' => '0']);
    $order = (object) ['doc_num' => 'SYNTHETIC-ORDER', 'production_order_date' => $date, 'source_type' => 'manual',
        'salesOrder' => null, 'status' => 'approved', 'lines' => collect()];
    $inspection = (object) ['doc_num' => 'SYNTHETIC-QC', 'sampled_at' => $date, 'run' => null, 'stageSnapshot' => null,
        'qualityType' => null, 'result' => 'pass', 'disposition' => null, 'affected_base_quantity' => '1', 'status' => 'approved',
        'defect_code' => null, 'notes' => null, 'corrective_action' => null, 'evidence' => []];
    $receipt = (object) ['doc_num' => 'SYNTHETIC-RECEIPT', 'document_date' => $date, 'productionRun' => null,
        'branchStore' => null, 'lines' => collect([(object) ['product' => null, 'base_quantity' => '1']])];
    $productionReport = ['runs' => collect([$run]), 'orders' => collect([$order]), 'qualityInspections' => collect([$inspection]),
        'materials' => collect(), 'finishedGoodsReceipts' => collect([$receipt]),
        'kpis' => ['planned_base_quantity' => '1', 'good_base_quantity' => '1', 'loss_base_quantity' => '0']];
    $control = new ProductionReportExport(['controlProducts' => collect(), 'controlDaily' => collect(),
        'controlDailyMaterials' => collect(), 'controlMachines' => collect(), 'controlMaterialSummary' => collect(),
        'controlRuns' => collect([$run]), 'controlMaterials' => collect()], 'control');
    $maintenanceOrder = new MaintenanceWorkOrder(['doc_num' => 'SYNTHETIC-MAINTENANCE',
        'planned_start_at' => $date, 'actual_start_at' => $date, 'actual_end_at' => $date, 'machine_released_at' => $date,
        'next_due_date' => $date, 'maintenance_type' => 'corrective', 'service_mode' => 'internal', 'priority' => 'normal', 'status' => 'completed']);
    $maintenanceOrder->setRelations(['materialRequests' => collect(), 'expenses' => collect(), 'asset' => null, 'mold' => null, 'supplier' => null]);
    $maintenance = new MaintenanceOperationsReportExport(['orders' => collect([$maintenanceOrder]), 'canViewFinancial' => true, 'kpis' => [],
        'requests' => collect([(object) ['doc_num' => 'SYNTHETIC-REQUEST', 'reported_at' => $date, 'asset' => null, 'mold' => null,
            'request_type' => 'breakdown', 'priority' => 'normal', 'is_machine_stopped' => false, 'status' => 'completed']]),
        'planDues' => collect([(object) ['plan' => null, 'due_at' => $date, 'status' => 'completed']]), 'materialQuantityTotals' => collect(), 'expenseTotals' => collect()]);
    $salesOrder = (object) ['doc_num' => 'SYNTHETIC-SALE', 'customer' => null, 'expected_delivery_date' => $date,
        'status' => 'approved', 'ordered_quantity' => '1', 'delivered_quantity' => '0', 'lines' => collect()];
    $sales = new SalesCycleReportExport(['reportType' => 'fulfillment', 'openOrders' => collect([$salesOrder])]);
    $salesCost = new SalesCycleReportExport(['reportType' => 'cost_of_sales', 'costOfSalesRows' => [[
        'movement_kind' => 'delivery', 'document' => 'SYNTHETIC-DELIVERY', 'posting_date' => $date, 'reconciliation_status' => 'reconciled']]]);
    $samples = [
        [$inventory, [[2, 'A2', $expectedDate], [4, 'A2', $expectedDate], [5, 'B2', $expectedDate], [7, 'A2', $expectedDate], [8, 'C2', $expectedDate], [8, 'D2', $expectedDate]]],
        [new ProductionReportExport($productionReport, 'orders'), [[0, 'B2', $expectedDate]]],
        [new ProductionReportExport($productionReport, 'runs'), [[0, 'H2', $expectedTime], [0, 'I2', $expectedTime]]],
        [new ProductionReportExport($productionReport, 'quality'), [[0, 'B2', $expectedTime]]],
        [new ProductionReportExport($productionReport, 'receipts'), [[0, 'B2', $expectedDate]]],
        [$control, [[5, 'B2', $expectedTime]]],
        [$maintenance, [[1, 'B2', $expectedTime], [2, 'C2', $expectedTime], [4, 'H2', $expectedTime], [4, 'I2', $expectedTime], [4, 'J2', $expectedTime], [4, 'K2', $expectedTime], [4, 'AD2', $expectedDate]]],
        [$sales, [[0, 'C2', $expectedDate]]],
        [$salesCost, [[1, 'K2', $expectedDate]]],
    ];
    foreach ($samples as [$export, $cells]) {
        $temporary = tmpfile();
        try {
            fwrite($temporary, Excel::raw($export, Maatwebsite\Excel\Excel::XLSX));
            $workbook = IOFactory::load(stream_get_meta_data($temporary)['uri']);
            foreach ($cells as [$sheet, $cell, $expected]) {
                expect($workbook->getSheet($sheet)->getCell($cell)->getValue())->toBe($expected);
            }
            $workbook->disconnectWorksheets();
        } finally {
            fclose($temporary);
        }
    }
})->with([
    'day first with twelve hour time' => ['d/m/Y', 'd/m/Y h:i A', '10/05/2026', '10/05/2026 03:04 PM'],
    'month first with twenty four hour time' => ['m-d-Y', 'm-d-Y H:i', '05-10-2026', '05-10-2026 15:04'],
]);
