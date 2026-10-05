<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    if (getenv('MGYPACK_COMMERCIAL_PREVIEW_PG') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit isolated PostgreSQL commercial preview rehearsal only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_discount_manual_print_pg_20261004')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(1);
});

require_once __DIR__.'/SalesInventoryPrintCostCases.php';
