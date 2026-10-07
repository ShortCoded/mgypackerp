<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql' || DB::selectOne('select current_database() as name')->name !== 'mgypack_receipt_cancellation_pg_20261005') {
        $this->markTestSkipped('Requires the backed-up disposable PostgreSQL receipt cancellation acceptance database.');
    }
});

require_once __DIR__.'/ProductionReceiptCancellationCases.php';
