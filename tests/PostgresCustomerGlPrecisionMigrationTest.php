<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerMigrationSupport.php';

beforeEach(function (): void {
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_customer_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('back up current customer clone then apply GL precision close schema once preserving every existing row and sequence', function (): void {
    if (getenv('MGYPACK_GL_PRECISION_MIGRATION_CAPTURE') !== '1') {
        $this->markTestSkipped('Once-only guarded customer clone backup and migration rehearsal.');
    }
    $path = '/tmp/mgypack-customer-pre-gl-precision-20261003.json';
    $backup = '/tmp/mgypack-customer-pre-gl-precision-20261003.dump';
    expect(file_exists($path))->toBeFalse()->and(file_exists($backup))->toBeFalse()
        ->and(Schema::hasColumn('inventory_value_adjustment_lines', 'precision_key'))->toBeFalse();
    $baseline = ['database' => 'mgypack_customer_20261003', 'captured_at' => now()->toIso8601String(), 'tables' => []];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if ($table === 'migrations') {
            continue;
        }
        $columns = Schema::getColumnListing($table);
        $baseline['tables'][$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
    }
    $baseline['sequences'] = DB::select("select sequencename, last_value from pg_sequences where schemaname = 'public' and sequencename <> 'migrations_id_seq' order by sequencename");
    $config = DB::connection()->getConfig();
    $dump = new Process(['pg_dump', '--format=custom', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--file='.$backup, 'mgypack_customer_20261003'], env: ['PGPASSWORD' => $config['password']]);
    $dump->setTimeout(60)->mustRun();
    chmod($backup, 0600);
    $baseline['backup'] = $backup;
    $baseline['backup_sha256'] = hash_file('sha256', $backup);
    file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true, '--path' => 'modules/Inventory/Database/Migrations/2026_10_03_120600_add_inventory_gl_precision_lineage.php']))->toBe(0);
    expect(Artisan::output())->toContain('2026_10_03_120600_add_inventory_gl_precision_lineage');
    foreach ($baseline['tables'] as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    foreach ($baseline['sequences'] as $sequence) {
        $current = DB::selectOne("select last_value from pg_sequences where schemaname = 'public' and sequencename = ?", [$sequence->sequencename]);
        expect($current->last_value)->toBe($sequence->last_value, $sequence->sequencename);
    }
    expect(DB::table('inventory_value_adjustment_lines')->whereNotNull('precision_key')->count())->toBe(0)
        ->and(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true, '--path' => 'modules/Inventory/Database/Migrations/2026_10_03_120600_add_inventory_gl_precision_lineage.php']))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to migrate');
});

test('reconcile saved GL precision migration baseline and prove repeated migrate is harmless', function (): void {
    $baseline = json_decode(file_get_contents('/tmp/mgypack-customer-pre-gl-precision-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($baseline['database'])->toBe('mgypack_customer_20261003')
        ->and(hash_file('sha256', $baseline['backup']))->toBe($baseline['backup_sha256'])
        ->and(Schema::hasColumn('inventory_value_adjustment_lines', 'precision_key'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', '2026_10_03_120600_add_inventory_gl_precision_lineage')->count())->toBe(1);
    foreach ($baseline['tables'] as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    foreach ($baseline['sequences'] as $sequence) {
        $current = DB::selectOne("select last_value from pg_sequences where schemaname = 'public' and sequencename = ?", [$sequence['sequencename']]);
        expect($current->last_value)->toBe($sequence['last_value'], $sequence['sequencename']);
    }
    expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true, '--path' => 'modules/Inventory/Database/Migrations/2026_10_03_120600_add_inventory_gl_precision_lineage.php']))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to migrate');
});

test('restore GL precision pre-migration customer backup to a fresh isolated database and verify all original rows', function (): void {
    if (getenv('MGYPACK_GL_PRECISION_MIGRATION_RECOVERY') !== '1') {
        $this->markTestSkipped('Once-only guarded isolated restore rehearsal.');
    }
    $baseline = json_decode(file_get_contents('/tmp/mgypack-customer-pre-gl-precision-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $baseline['backup']))->toBe($baseline['backup_sha256']);
    $name = 'mgypack_gl_precision_recovery_20261003';
    expect(DB::selectOne('select 1 as found from pg_database where datname = ?', [$name]))->toBeNull();
    DB::statement('CREATE DATABASE mgypack_gl_precision_recovery_20261003');
    $config = DB::connection()->getConfig();
    $restore = new Process(['pg_restore', '--exit-on-error', '--single-transaction', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--dbname='.$name, $baseline['backup']], env: ['PGPASSWORD' => $config['password']]);
    $restore->setTimeout(60)->mustRun();
    unset($config['url'], $config['name']);
    config(['database.connections.gl_precision_recovery' => [...$config, 'database' => $name]]);
    try {
        foreach ($baseline['tables'] as $table => $snapshot) {
            expect(customerRehearsalTableSnapshot($table, $snapshot['columns'], 'gl_precision_recovery'))
                ->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
        }
        expect(Schema::connection('gl_precision_recovery')->hasColumn('inventory_value_adjustment_lines', 'precision_key'))->toBeFalse();
    } finally {
        DB::disconnect('gl_precision_recovery');
    }
});
