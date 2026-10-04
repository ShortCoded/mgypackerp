<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerMigrationSupport.php';

test('guarded synthetic database applies standard cost schema preserving existing rows and sequences and repeated migrate is harmless', function (): void {
    if (getenv('MGYPACK_STANDARD_COST_MIGRATE') !== '1') {
        $this->markTestSkipped('Explicit guarded synthetic acceptance schema migration.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_acceptance_closure_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $baseline = [];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if (in_array($table, ['migrations', 'inventory_cost_standards', 'inventory_standard_cost_settlements'], true)) {
            continue;
        }
        $columns = Schema::getColumnListing($table);
        $baseline[$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
    }
    $sequences = DB::select("select sequencename, last_value from pg_sequences where schemaname = 'public' and sequencename != 'migrations_id_seq' and sequencename not in ('inventory_cost_standards_id_seq', 'inventory_standard_cost_settlements_id_seq') order by sequencename");
    $options = ['--force' => true, '--no-interaction' => true, '--path' => 'modules/Inventory/Database/Migrations/2026_10_03_120700_create_inventory_cost_standards.php'];
    expect(Artisan::call('migrate', $options))->toBe(0)->and(Schema::hasTable('inventory_cost_standards'))->toBeTrue()->and(Schema::hasTable('inventory_standard_cost_settlements'))->toBeTrue();
    foreach ($baseline as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    foreach ($sequences as $sequence) {
        expect(DB::selectOne("select last_value from pg_sequences where schemaname = 'public' and sequencename = ?", [$sequence->sequencename])->last_value)
            ->toBe($sequence->last_value, $sequence->sequencename);
    }
    expect(Artisan::call('migrate', $options))->toBe(0)->and(Artisan::output())->toContain('Nothing to migrate')
        ->and(DB::table('migrations')->where('migration', '2026_10_03_120700_create_inventory_cost_standards')->count())->toBe(1);
});
