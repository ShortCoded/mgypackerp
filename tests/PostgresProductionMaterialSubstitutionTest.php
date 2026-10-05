<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_substitution_slice_pg_20261004_e9f52b')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(1);
});

require_once __DIR__.'/ProductionMaterialSubstitutionCases.php';
