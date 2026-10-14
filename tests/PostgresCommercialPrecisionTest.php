<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);
require_once __DIR__.'/ProcurementSupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_CHECKLIST_PRECISION_PG') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit isolated PostgreSQL precision rehearsal only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_commercial_acceptance_20261007')->and($identity->host)->toBe('127.0.0.1')
        ->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(1);
});

test('PostgreSQL purchase prices retain fifteen fractional digits on actual native models and partial invoice source copying', function (): void {
    $f = procurementFixture(isolatedCompany: true);
    procurementUseBranch($f, procurementAdministrativeBranch($f));
    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create(['supplier_doc_num' => $f['firstSupplier']->doc_num, 'branch_store_uuid' => $f['store']->public_uuid,
        'currency_doc_num' => $f['currency']->doc_num, 'document_date' => now()->toDateString(), 'exchange_rate' => '1',
        'direct_procurement_override' => true, 'direct_procurement_reason' => 'SYNTHETIC PostgreSQL exact source proof',
        'lines' => [['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'ordered_quantity' => '360', 'unit_price' => '32.916666666666666']]])['record']);
    $line = $order->lines->sole();
    expect(DB::scalar('select unit_price::text from purchase_order_lines where id = ?', [$line->id]))->toBe('32.916666666666666')
        ->and($order->total_amount)->toBe('11850.0000');
    $invoice = app(PurchaseInvoiceService::class)->create(['supplier_doc_num' => $f['firstSupplier']->doc_num,
        'currency_doc_num' => $f['currency']->doc_num, 'invoice_date' => now()->toDateString(), 'exchange_rate' => '1',
        'purchase_order_doc_num' => $order->doc_num, 'payment_type' => 'credit',
        'lines' => [['product_doc_num' => $f['service']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'purchase_order_line_public_id' => $line->public_id,
            'quantity' => '120', 'unit_price' => '32.916666666666666']]])['record'];
    expect($invoice->lines->sole()->unit_price)->toBe('32.916666666666666')->and($invoice->total_amount)->toBe('3950.0000')
        ->and(DB::scalar('select unit_price::text from purchase_invoice_lines where id = ?', [$invoice->lines->sole()->id]))->toBe('32.916666666666666');
    foreach (['purchase_order_lines', 'purchase_invoice_lines', 'purchase_return_lines'] as $table) {
        $column = DB::selectOne('select numeric_precision,numeric_scale,is_nullable,column_default from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?', ['public', $table, 'unit_price']);
        expect($column->numeric_precision)->toBe(29)->and($column->numeric_scale)->toBe(15)->and($column->is_nullable)->toBe('NO')->and($column->column_default)->not->toBeNull();
    }
    expect(fn () => $line->update(['unit_price' => '1.1234567890123456']))->toThrow(InvalidArgumentException::class);
    expect($line->fresh()->unit_price)->toBe('32.916666666666666');
});

test('PostgreSQL widening preserves historical eight place prices and fourteen integer digits in a rollback transaction', function (): void {
    $f = procurementFixture(isolatedCompany: true);
    foreach (['purchase_order_lines', 'purchase_invoice_lines', 'purchase_return_lines'] as $table) {
        Schema::table($table, fn ($schema) => $schema->decimal('unit_price', 22, 8)->default(0)->change());
    }
    $order = PurchaseOrder::query()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id,
        'branch_id' => $f['branch']->id, 'branch_store_id' => $f['store']->id, 'doc_num' => 'SYNTHETIC-HISTORICAL-PRICE', 'doc_number' => 99100,
        'supplier_id' => $f['firstSupplier']->id, 'currency_id' => $f['currency']->id, 'document_date' => now()->toDateString()]);
    foreach (['22.54545123', '99999999999999.12345678'] as $index => $price) {
        $order->lines()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'line_number' => $index + 1,
            'product_id' => $f['raw']->id, 'unit_id' => $f['unit']->id, 'ordered_quantity' => '1', 'unit_price' => $price]);
    }
    $before = DB::select('select id,unit_price::text as price from purchase_order_lines where purchase_order_id = ? order by id', [$order->id]);
    $migration = require base_path('modules/Purchases/Database/Migrations/2026_10_07_140128_widen_purchase_unit_prices_to_fifteen_decimals.php');
    $migration->up();
    $after = DB::select('select id,unit_price::text as price from purchase_order_lines where purchase_order_id = ? order by id', [$order->id]);
    foreach ($before as $index => $old) {
        expect($after[$index]->id)->toBe($old->id)->and(bccomp($after[$index]->price, $old->price, 15))->toBe(0);
    }
    expect($order->fresh()->lines->last()->unit_price)->toBe('99999999999999.12345678');
});
