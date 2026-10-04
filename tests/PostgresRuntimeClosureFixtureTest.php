<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Models\ProductionRun;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesIssueOrder;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/SpecificProductionCostCases.php';
require_once __DIR__.'/SpecificSalesCostCases.php';

test('prepare explicitly synthetic closure runtime acceptance fixtures', function (): void {
    if (getenv('MGYPACK_RUNTIME_FIXTURE') !== '1') {
        $this->markTestSkipped('Explicit isolated runtime fixture preparation only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
    $path = '/tmp/mgypack-runtime-closure-20261003.json';
    if (is_file($path)) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        expect(User::query()->where('username', $manifest['production']['username'])->exists())->toBeTrue();
        expect(User::query()->where('username', $manifest['sales']['username'])->exists())->toBeTrue();
        Permission::findOrCreate('inventory.documents.create', 'web');
        User::query()->where('username', $manifest['production']['username'])->firstOrFail()->givePermissionTo('inventory.documents.create');
        $operator = User::query()->where('username', $manifest['production']['username'])->firstOrFail();
        foreach (['production.reports.control.view', 'production.reports.control.export', 'production.reports.control.print'] as $permission) {
            $operator->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $run = ProductionRun::query()->where('public_id', $manifest['production']['run_uuid'])->sole();
        $context = ['company_doc_num' => Company::findOrFail($run->company_id)->doc_num,
            'branch_doc_num' => Branch::findOrFail($run->branch_id)->doc_num,
            'financial_period_doc_num' => FinancialPeriod::findOrFail($run->financial_period_id)->doc_num];
        expect($operator->username)->toStartWith('synthetic-closure-')
            ->and($run->product->doc_num)->toStartWith('FG-MFG-SYNTHETIC-SELECTED-');
        app(DefaultLoginContextService::class)->update($operator, $context);
        $manifest['production']['context'] = $context;
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);

        return;
    }
    DB::transaction(function () use ($path): void {
        $production = specificProductionFixture(true);
        foreach (['production.runs.view', 'production.runs.issue', 'inventory.documents.create', 'production.reports.view', 'production.reports.export', 'production.reports.pdf',
            'production.reports.control.view', 'production.reports.control.export', 'production.reports.control.print'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $production['user']->givePermissionTo($permission);
        }
        $production['cycle']->reserveRun($production['run'], $production['store']->id);
        $sales = specificSalesFixture(['distinct_batches' => true]);
        foreach (['sales.sales_invoices.view', 'sales.sales_orders.view', 'inventory.documents.issue'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $sales['preparer']->givePermissionTo($permission);
        }
        foreach ([[$production, $production['user']], [$sales, $sales['preparer']]] as [$fixture, $user]) {
            app(DefaultLoginContextService::class)->update($user, [
                'company_doc_num' => $fixture['company']->doc_num,
                'branch_doc_num' => $fixture['branch']->doc_num,
                'financial_period_doc_num' => $fixture['period']->doc_num,
            ]);
        }
        $manifest = [
            'database' => 'mgypack_acceptance_closure_20261003', 'synthetic' => true,
            'production' => ['username' => $production['user']->username, 'batch_uuid' => $production['batch']->getRouteKey(),
                'batch_doc_num' => $production['batch']->doc_num, 'run_uuid' => $production['run']->getRouteKey(),
                'store_uuid' => $production['store']->public_uuid, 'requirement_id' => $production['requirement']->id,
                'cheap_layer_id' => $production['cheapLayer']->id, 'expensive_layer_id' => $production['expensiveLayer']->id,
                'raw_doc_num' => $production['raw']->doc_num],
            'sales' => ['username' => $sales['preparer']->username, 'store_uuid' => $sales['store']->public_uuid,
                'issue_order_uuid' => $sales['issueOrder']->getRouteKey(), 'invoice_doc_num' => $sales['invoice']->doc_num,
                'order_doc_num' => $sales['order']->doc_num, 'expensive_layer_id' => $sales['layer']->id],
        ];
        expect($production['run']->requirements->sole()->issued_quantity)->toBe('0.00000000');
        expect($sales['invoice']->status)->toBe(CustomerInvoice::StatusPosted);
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    });
});

test('verify persisted synthetic browser issues against receipt quantities costs and source counters', function (): void {
    if (getenv('MGYPACK_RUNTIME_VERIFY') !== '1') {
        $this->markTestSkipped('Explicit isolated browser-run verification only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
    $manifest = json_decode(file_get_contents('/tmp/mgypack-runtime-closure-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    $run = ProductionRun::query()->where('public_id', $manifest['production']['run_uuid'])->sole();
    $issues = InventoryDocument::query()->where('production_run_batch_id', $run->production_run_batch_id)->where('document_type', InventoryDocument::TypeMaterialIssue)->get();
    $out = InventoryTransaction::query()->where('source_type', InventoryDocument::class)
        ->whereIn('source_id', $issues->modelKeys())->where('is_reversal', false)->where('quantity_out', '>', 0)->get();
    expect($issues)->toHaveCount(2)->and($run->requirements->sole()->issued_quantity)->toBe('7.00000000')
        ->and(bcadd((string) $out->sum('quantity_out'), '0', 8))->toBe('7.00000000')
        ->and(bcadd((string) $out->sum('total_cost'), '0', 8))->toBe('100.00000000');
    expect(InventoryReceiptLayer::findOrFail($manifest['production']['cheap_layer_id'])->remaining_quantity)->toBe('0.00000000')
        ->and(InventoryReceiptLayer::findOrFail($manifest['production']['expensive_layer_id'])->remaining_quantity)->toBe('3.00000000');
    $salesIssue = SalesIssueOrder::query()->where('doc_num', $manifest['sales']['issue_order_uuid'])->sole();
    $delivery = $salesIssue->issues()->where('document_type', InventoryDocument::TypeSalesDelivery)->sole();
    expect($salesIssue->status)->toBe(SalesIssueOrder::StatusIssued)
        ->and($delivery->transactions->sole()->quantity_out)->toBe('4.00000000')
        ->and($delivery->transactions->sole()->total_cost)->toBe('80.00000000')
        ->and($delivery->lines->sole()->selected_receipt_layer_id)->toBe($manifest['sales']['expensive_layer_id'])
        ->and($salesIssue->invoice->order->lines->sole()->delivered_base_quantity)->toBe('4.00000000');
    expect($delivery->journalEntry->lines->sum('debit_amount'))->toEqual(80)
        ->and($delivery->journalEntry->lines->sum('credit_amount'))->toEqual(80);
});
