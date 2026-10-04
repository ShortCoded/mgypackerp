<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Inventory\Exports\InventoryPeriodicCostCloseExport;
use Modules\Inventory\Exports\InventoryReportExport;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventorySerialIdentity;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/InventoryCostTransitionSupport.php';
require_once __DIR__.'/ClosurePostgresRaceSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('prepare isolated synthetic serial units for actual browser and concurrent issue verification', function (): void {
    if (getenv('MGYPACK_SERIAL_RUNTIME_CREATE') !== '1') {
        $this->markTestSkipped('Explicit isolated synthetic fixture only.');
    }
    $path = '/tmp/mgypack-serial-runtime-20261003.json';
    if (file_exists($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect($manifest['synthetic'])->toBeTrue()->and(InventoryDocument::findOrFail($manifest['receipt_id'])->company_id)->toBe($manifest['company_id']);

        return;
    }
    $manifest = DB::transaction(function (): array {
        $fixture = costTransitionFixture(isolatedCompany: true);
        $fixture['product']->update(['tracks_serials' => true]);
        foreach (['inventory.documents.view', 'inventory.documents.create', 'inventory.documents.reverse', 'inventory.documents.print',
            'inventory.documents.issue', 'inventory.documents.post', 'inventory.reports.operations.view', 'inventory.reports.operations.export',
            'inventory.reports.operations.print', 'products.view', 'products.edit'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $fixture['preparer']->givePermissionTo($permission);
        }
        app(DefaultLoginContextService::class)->update($fixture['preparer'], ['company_doc_num' => $fixture['company']->doc_num,
            'branch_doc_num' => $fixture['branch']->doc_num, 'financial_period_doc_num' => $fixture['period']->doc_num]);
        $day = now()->toDateString();
        $receipt = costTransitionMovement($fixture, $day, InventoryDocument::TypeAdjustmentIn, '2', '7.12345678',
            ['serial_numbers' => ['SYNTHETIC-RACE-UNIT', 'SYNTHETIC-BROWSER-UNIT']]);
        $layers = InventoryReceiptLayer::whereIn('receipt_transaction_id', $receipt->transactions->modelKeys())->orderBy('id')->get();

        return ['synthetic' => true, 'database' => 'mgypack_acceptance_closure_20261003',
            'company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id, 'period_id' => $fixture['period']->id,
            'store_id' => $fixture['store']->id, 'store_uuid' => $fixture['store']->public_uuid, 'product_id' => $fixture['product']->id,
            'product_code' => $fixture['product']->doc_num, 'product_uuid' => $fixture['product']->public_uuid,
            'preparer_id' => $fixture['preparer']->id, 'preparer' => $fixture['preparer']->username, 'day' => $day,
            'context' => session()->all(), 'receipt_id' => $receipt->id, 'receipt_doc_num' => $receipt->doc_num,
            'race_layer_id' => $layers->first()->id, 'race_serial_id' => $layers->first()->inventory_serial_identity_id,
            'browser_layer_id' => $layers->last()->id, 'browser_serial_id' => $layers->last()->inventory_serial_identity_id];
    });
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
});

test('two actual PostgreSQL connections issue the same selected serial exactly once', function (): void {
    if (getenv('MGYPACK_SERIAL_RUNTIME_RACE') !== '1') {
        $this->markTestSkipped('Explicit once-only concurrent issue verification.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-serial-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue();
    session($manifest['context']);
    if (bccomp(InventoryReceiptLayer::findOrFail($manifest['race_layer_id'])->remaining_quantity, '0', 8) === 0) {
        $user = User::findOrFail($manifest['preparer_id']);
        auth()->login($user);
        request()->setUserResolver(fn () => $user);
        request()->setLaravelSession(app('session.store'));
        session($manifest['context']);
        $fixture = ['company' => Company::findOrFail($manifest['company_id']),
            'branch' => Branch::findOrFail($manifest['branch_id']), 'period' => FinancialPeriod::findOrFail($manifest['period_id']),
            'store' => BranchStore::findOrFail($manifest['store_id']), 'product' => Product::findOrFail($manifest['product_id'])];
        $receipt = costTransitionMovement($fixture, $manifest['day'], InventoryDocument::TypeAdjustmentIn, '1', '7.12345678',
            ['serial_number' => 'SYNTHETIC-RACE-RETRY-'.Str::random(8)]);
        $layer = InventoryReceiptLayer::where('receipt_transaction_id', $receipt->transactions->sole()->id)->sole();
        $manifest['previous_race_layers'][] = $manifest['race_layer_id'];
        $manifest['race_layer_id'] = $layer->id;
        $manifest['race_serial_id'] = $layer->inventory_serial_identity_id;
        file_put_contents('/tmp/mgypack-serial-runtime-20261003.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    $header = ['company_id' => $manifest['company_id'], 'financial_period_id' => $manifest['period_id'],
        'branch_id' => $manifest['branch_id'], 'branch_store_id' => $manifest['store_id'], 'source_stock_status' => InventoryTransaction::StatusAvailable,
        'document_type' => InventoryDocument::TypeIssue, 'document_date' => $manifest['day']];
    $operation = ['operation' => 'serial-issue', 'user' => $manifest['preparer_id'], 'header' => $header,
        'lines' => [['product_id' => $manifest['product_id'], 'quantity' => '1', 'selected_receipt_layer_id' => $manifest['race_layer_id']]]];
    $results = closurePostgresRace([$operation, $operation]);
    expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['applied', 'blocked']);
    $issues = InventoryTransaction::where('inventory_serial_identity_id', $manifest['race_serial_id'])->where('quantity_out', '>', 0)->get();
    expect($issues)->toHaveCount(1)->and($issues->sole()->total_cost)->toBe('7.12345678')
        ->and(InventoryReceiptLayer::findOrFail($manifest['race_layer_id'])->remaining_quantity)->toBe('0.00000000')
        ->and(InventorySerialIdentity::findOrFail($manifest['race_serial_id'])->current_receipt_layer_id)->toBeNull()
        ->and(InventorySerialIdentity::findOrFail($manifest['browser_serial_id'])->current_receipt_layer_id)->toBe($manifest['browser_layer_id']);
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($manifest['company_id'], $manifest['period_id'], $manifest['branch_id']));
    expect($reconciliation->every(fn (array $row): bool => bccomp((string) $row['difference'], '0', 4) === 0))->toBeTrue($reconciliation->toJson());
});

test('actual browser serial issue and downloaded operations outputs retain exact persisted source identities', function (): void {
    if (getenv('MGYPACK_SERIAL_BROWSER_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit verification of isolated browser data and downloads only.');
    }
    $manifest = json_decode(file_get_contents('/tmp/mgypack-serial-runtime-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['synthetic'])->toBeTrue();
    $document = InventoryDocument::where('company_id', $manifest['company_id'])->where('doc_num', 'INV-MOV-00005')->sole();
    $line = $document->lines->sole();
    $transaction = $document->transactions->sole();
    expect($document->status)->toBe(InventoryDocument::StatusPosted)->and($document->created_by)->toBe($manifest['preparer_id'])
        ->and($line->selected_receipt_layer_id)->toBe($manifest['browser_layer_id'])
        ->and($transaction->inventory_serial_identity_id)->toBe($manifest['browser_serial_id'])
        ->and($transaction->total_cost)->toBe('7.12345678')->and($transaction->quantity_out)->toBe('1.00000000')
        ->and(InventoryReceiptLayer::findOrFail($manifest['browser_layer_id'])->remaining_quantity)->toBe('0.00000000')
        ->and(InventorySerialIdentity::findOrFail($manifest['browser_serial_id'])->current_receipt_layer_id)->toBeNull();
    $adjustments = collect(app(InventoryGlReconciliationService::class)->reconcile($manifest['company_id'], $manifest['period_id'], $manifest['branch_id']))->firstWhere('key', 'inventory_adjustments');
    expect($adjustments)->toMatchArray(['subledger' => '21.3705', 'gl' => '21.3705', 'difference' => '0.0000']);
    $dom = json_decode(file_get_contents('/tmp/mgypack-serial-browser-report-dom-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($dom)->toHaveCount(6)->and($dom[0][1])->toBe('INV-MOV-00005')->and($dom[0][4])->toContain('SYNTHETIC-BROWSER-UNIT');
    app()->setLocale('ar');
    $report = app(InventoryReportService::class)->report($manifest['company_id'], $manifest['period_id'], $manifest['branch_id'], [], null);
    expect($report['balances'])->toHaveCount(0)->and($report['movements'])->toHaveCount(6)
        ->and(bcadd((string) $report['reportTotals']['quantity_in'], '0', 8))->toBe('3.00000000')
        ->and(bcadd((string) $report['reportTotals']['quantity_out'], '0', 8))->toBe('3.00000000');
    $export = new InventoryReportExport($report);
    $workbook = IOFactory::load('/home/mohab/Downloads/inventory-operations-20261003-142252.xlsx');
    $csv = fopen('/home/mohab/Downloads/inventory-operations-20261003-142309.csv', 'r');
    try {
        foreach ($export->sections() as $sheetIndex => $section) {
            $actual = $workbook->getSheet($sheetIndex)->toArray(null, false, false, false);
            foreach ([$section['headings'], ...$section['rows']] as $rowIndex => $row) {
                foreach ($row as $column => $value) {
                    expect((string) ($actual[$rowIndex][$column] ?? ''))->toBe((string) ($value ?? ''));
                }
            }
        }
        $flat = new InventoryPeriodicCostCloseExport($export->sections(), true);
        foreach ($flat->array() as $row) {
            $csvRow = fgetcsv($csv, separator: ',', enclosure: '"', escape: '');
            foreach ($row as $column => $value) {
                expect($csvRow[$column] ?? '')->toBe($value);
            }
        }
    } finally {
        fclose($csv);
        $workbook->disconnectWorksheets();
    }
    foreach (['/home/mohab/Downloads/inventory-operations-report (2).pdf', '/home/mohab/Downloads/inventory-operations-report (3).pdf'] as $path) {
        $extract = new Process(['pdftotext', '-layout', $path, '-']);
        $extract->mustRun();
        foreach ($report['movements'] as $movement) {
            expect($extract->getOutput())->toContain($movement->source_doc_num, ...$movement->serialNumbers());
        }
        $info = new Process(['pdfinfo', $path]);
        $info->mustRun();
        expect($info->getOutput())->toContain('(A4)', 'Pages:           1');
    }
});
