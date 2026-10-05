<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/CustomerMigrationSupport.php';

test('withholding additive migration rollbacks and backup restoration preserve every original isolated database row', function (): void {
    if (getenv('MGYPACK_WHT_MIGRATE') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit once-only isolated migration rehearsal.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_withholding_collection_pg_20261005')->and($identity->host)->toBe('127.0.0.1')
        ->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    expect(Schema::hasTable('customer_withholding_settlements'))->toBeFalse();
    $baseline = [];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if ($table !== 'migrations') {
            $columns = Schema::getColumnListing($table);
            $baseline[$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
        }
    }
    $config = DB::connection()->getConfig();
    $backup = storage_path('app/test-artifacts/mgypack-fast-wht-pg-before-migration-20261005.dump');
    expect(file_exists($backup))->toBeFalse();
    touch($backup);
    chmod($backup, 0600);
    (new Process(['pg_dump', '--format=custom', '--no-password', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'],
        '--file='.$backup, $identity->db], env: ['PGPASSWORD' => $config['password']]))->setTimeout(90)->mustRun();
    $path = 'modules/Sales/Database/Migrations/2026_10_05_075438_create_customer_withholding_settlements_table.php';
    $options = ['--database' => 'pgsql', '--path' => $path, '--force' => true, '--no-interaction' => true];
    expect(Artisan::call('migrate', $options))->toBe(0)->and(Schema::hasTable('customer_withholding_settlements'))->toBeTrue();
    foreach ($baseline as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    expect(DB::table('customer_withholding_settlements')->count())->toBe(0)
        ->and(DB::table('customer_invoices')->where('actual_withholding_amount', '<>', 0)->count())->toBe(0);
    expect(Artisan::call('migrate:rollback', [...$options, '--step' => 1]))->toBe(0)
        ->and(Schema::hasTable('customer_withholding_settlements'))->toBeFalse()
        ->and(Schema::hasColumn('customer_invoices', 'actual_withholding_amount'))->toBeFalse();
    foreach ($baseline as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    expect(Artisan::call('migrate', $options))->toBe(0)->and(Artisan::call('migrate', $options))->toBe(0)->and(Artisan::output())->toContain('Nothing to migrate');
    $restoreName = 'mgypack_wht_restore_pg_20261005';
    expect(DB::selectOne('select 1 from pg_database where datname = ?', [$restoreName]))->toBeNull();
    DB::statement('CREATE DATABASE mgypack_wht_restore_pg_20261005');
    (new Process(['pg_restore', '--exit-on-error', '--single-transaction', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--dbname='.$restoreName, $backup], env: ['PGPASSWORD' => $config['password']]))->setTimeout(90)->mustRun();
    unset($config['url'], $config['name']);
    config(['database.connections.wht_restore' => [...$config, 'database' => $restoreName]]);
    expect(Schema::connection('wht_restore')->hasTable('customer_withholding_settlements'))->toBeFalse();
    foreach ($baseline as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns'], 'wht_restore'))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    file_put_contents(storage_path('app/test-artifacts/mgypack-fast-wht-migration-proof-20261005.json'), json_encode([
        'source_database' => $identity->db, 'restore_database' => $restoreName, 'host' => $identity->host, 'port' => $identity->port,
        'migration' => $path, 'backup' => $backup, 'sha256' => hash_file('sha256', $backup), 'original_tables' => $baseline,
        'up_preserved_rows' => true, 'down_preserved_rows' => true, 'reapplied' => true, 'restore_reconciled' => true, 'default_database_written' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});
