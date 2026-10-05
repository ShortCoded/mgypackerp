<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    if (getenv('MGYPACK_SUPPLIER_DISCOUNT_PG') !== '1') {
        $this->markTestSkipped('Explicitly isolated purchase discount PostgreSQL transactions only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_supplier_discount_pg_20261004')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(1);
});

require_once __DIR__.'/SupplierCommercialDiscountCases.php';
