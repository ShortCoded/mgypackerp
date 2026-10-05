<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    if (getenv('MGYPACK_ISSUE_LINEAGE_PG') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit isolated PostgreSQL issue view/print/lineage rehearsal only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_withholding_collection_pg_20261005')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(1);
});

require_once __DIR__.'/InventoryDocumentLineageCases.php';
require_once __DIR__.'/SalesInventoryPrintCostCases.php';

require_once __DIR__.'/CustomerMigrationSupport.php';

test('all original isolated PostgreSQL rows remain unchanged after transactional issue view print and lineage tests', function (): void {
    $baseline = json_decode(file_get_contents(storage_path('app/test-artifacts/mgypack-fast-wht-migration-proof-20261005.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($baseline['original_tables'] as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    expect(DB::table('customer_withholding_settlements')->count())->toBe(0)
        ->and(DB::table('customer_invoices')->where('actual_withholding_amount', '<>', 0)->count())->toBe(0);
});
