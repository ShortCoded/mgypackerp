<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerMigrationSupport.php';

test('manual inventory correction migration is additive and repeatable on explicitly isolated databases', function (): void {
    if (getenv('MGYPACK_MANUAL_CORRECTION_MIGRATE') !== '1') {
        $this->markTestSkipped('Explicit isolated migration rehearsal only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBeIn([
        'mgypack_acceptance_closure_20261003', 'mgypack_customer_20261003', 'mgypack_manual_correction_recovery_20261003',
    ])->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $baseline = [];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if ($table !== 'migrations') {
            $columns = Schema::getColumnListing($table);
            $baseline[$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
        }
    }
    $sequences = DB::select("select sequencename, last_value from pg_sequences where schemaname='public' and sequencename <> 'migrations_id_seq' order by sequencename");
    if ($identity->db === 'mgypack_customer_20261003' && ! Schema::hasTable('inventory_movement_corrections')) {
        $backup = '/tmp/mgypack-customer-pre-manual-correction-20261003.dump';
        expect(file_exists($backup))->toBeFalse();
        $config = DB::connection()->getConfig();
        (new Process(['pg_dump', '--format=custom', '--no-password', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'], '--file='.$backup, $identity->db],
            env: ['PGPASSWORD' => $config['password']]))->setTimeout(60)->mustRun();
        chmod($backup, 0600);
        $path = '/tmp/mgypack-customer-pre-manual-correction-20261003.json';
        file_put_contents($path, json_encode(['database' => $identity->db, 'backup' => $backup, 'backup_sha256' => hash_file('sha256', $backup),
            'tables' => $baseline, 'sequences' => $sequences], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }
    $path = ['database/migrations/2026_10_03_202158_create_inventory_movement_corrections_table.php'];
    expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true, '--path' => $path]))->toBe(0)
        ->and(Schema::hasTable('inventory_movement_corrections'))->toBeTrue();
    foreach ($baseline as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    foreach ($sequences as $sequence) {
        expect(DB::selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence->sequencename])->last_value)->toBe($sequence->last_value);
    }
    expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true, '--path' => $path]))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to migrate');
});

test('restore the actual pre-manual-correction customer backup into a fresh isolated database', function (): void {
    if (getenv('MGYPACK_MANUAL_CORRECTION_RECOVERY') !== '1') {
        $this->markTestSkipped('Once-only explicitly isolated backup recovery.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_customer_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $baseline = json_decode(file_get_contents('/tmp/mgypack-customer-pre-manual-correction-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $baseline['backup']))->toBe($baseline['backup_sha256']);
    $name = 'mgypack_manual_correction_recovery_20261003';
    expect(DB::selectOne('select 1 as found from pg_database where datname = ?', [$name]))->toBeNull();
    DB::statement('CREATE DATABASE mgypack_manual_correction_recovery_20261003');
    $config = DB::connection()->getConfig();
    (new Process(['pg_restore', '--exit-on-error', '--single-transaction', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--dbname='.$name, $baseline['backup']], env: ['PGPASSWORD' => $config['password']]))->setTimeout(60)->mustRun();
    unset($config['url'], $config['name']);
    config(['database.connections.manual_correction_recovery' => [...$config, 'database' => $name]]);
    try {
        foreach ($baseline['tables'] as $table => $snapshot) {
            expect(customerRehearsalTableSnapshot($table, $snapshot['columns'], 'manual_correction_recovery'))
                ->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
        }
        foreach ($baseline['sequences'] as $sequence) {
            expect(DB::connection('manual_correction_recovery')->selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence['sequencename']])->last_value)
                ->toBe($sequence['last_value']);
        }
        expect(Schema::connection('manual_correction_recovery')->hasTable('inventory_movement_corrections'))->toBeFalse();
    } finally {
        DB::disconnect('manual_correction_recovery');
    }
});
