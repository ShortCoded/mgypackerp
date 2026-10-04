<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerMigrationSupport.php';

test('invoice correction additive migration preserves all isolated acceptance rows and sequences and repeats safely', function (): void {
    if (getenv('MGYPACK_INVOICE_CORRECTION_MIGRATE') !== '1') {
        $this->markTestSkipped('Explicit isolated invoice correction migration only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $baseline = [];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if ($table !== 'migrations') {
            $columns = Schema::getColumnListing($table);
            $baseline[$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
        }
    }
    $sequences = DB::select("select sequencename, last_value from pg_sequences where schemaname='public' and sequencename <> 'migrations_id_seq' order by sequencename");
    $options = ['--force' => true, '--no-interaction' => true, '--path' => 'database/migrations/2026_10_03_223011_create_customer_invoice_corrections_table.php'];
    expect(Artisan::call('migrate', $options))->toBe(0)->and(Schema::hasTable('customer_invoice_corrections'))->toBeTrue();
    foreach ($baseline as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    foreach ($sequences as $sequence) {
        expect(DB::selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence->sequencename])->last_value)->toBe($sequence->last_value);
    }
    expect(Artisan::call('migrate', $options))->toBe(0)->and(Artisan::output())->toContain('Nothing to migrate');
});
