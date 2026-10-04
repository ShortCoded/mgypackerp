<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_customer_20261003')->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432);
});

require_once __DIR__.'/CustomerMigrationSupport.php';

test('capture immutable customer clone baseline and recoverable backup before pending migrations', function (): void {
    if (getenv('MGYPACK_MIGRATION_REHEARSAL_CAPTURE') !== '1') {
        $this->markTestSkipped('Explicit once-only pre-migration capture.');
    }
    $path = '/tmp/mgypack-customer-pre-migration-20261003.json';
    $backup = '/tmp/mgypack-customer-pre-migration-20261003.dump';
    expect(file_exists($path))->toBeFalse()->and(file_exists($backup))->toBeFalse();
    $baseline = ['database' => 'mgypack_customer_20261003', 'captured_at' => now()->toIso8601String(),
        'source_dump_sha256' => hash_file('sha256', '/mnt/Me/MB/DB/SQLs/Mgy Pack/dump-erp-202610030521.sql'), 'tables' => []];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if ($table === 'migrations') {
            continue;
        }
        $columns = Schema::getColumnListing($table);
        $baseline['tables'][$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
    }
    $baseline['migrations'] = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
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
    expect(filesize($backup))->toBeGreaterThan(0)->and(count($baseline['tables']))->toBeGreaterThan(300);
});

test('all original customer rows columns values and sequence states survive closure migrations', function (): void {
    $path = '/tmp/mgypack-customer-pre-migration-20261003.json';
    expect(file_exists($path))->toBeTrue();
    $baseline = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($baseline['database'])->toBe('mgypack_customer_20261003')
        ->and(hash_file('sha256', $baseline['backup']))->toBe($baseline['backup_sha256']);
    foreach ($baseline['tables'] as $table => $snapshot) {
        expect(Schema::hasTable($table))->toBeTrue($table);
        expect(array_diff($snapshot['columns'], Schema::getColumnListing($table)))->toBe([], $table);
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    foreach ($baseline['sequences'] as $sequence) {
        $current = DB::selectOne("select last_value from pg_sequences where schemaname = 'public' and sequencename = ?", [$sequence['sequencename']]);
        expect($current->last_value)->toBe($sequence['last_value'], $sequence['sequencename']);
    }
    expect(DB::table('migrations')->whereIn('migration', $baseline['migrations'])->count())->toBe(count($baseline['migrations']));
});

test('restore the pre-migration customer clone backup to a new isolated database and reconcile every original table', function (): void {
    if (getenv('MGYPACK_MIGRATION_RECOVERY') !== '1') {
        $this->markTestSkipped('Explicit isolated recovery rehearsal.');
    }
    $baseline = json_decode(file_get_contents('/tmp/mgypack-customer-pre-migration-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $baseline['backup']))->toBe($baseline['backup_sha256']);
    $name = 'mgypack_customer_recovery_20261003';
    expect(DB::selectOne('select 1 as found from pg_database where datname = ?', [$name]))->toBeNull();
    DB::statement('CREATE DATABASE mgypack_customer_recovery_20261003');
    $config = DB::connection()->getConfig();
    $restore = new Process(['pg_restore', '--exit-on-error', '--single-transaction', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--dbname='.$name, $baseline['backup']], env: ['PGPASSWORD' => $config['password']]);
    $restore->setTimeout(60)->mustRun();
    unset($config['url'], $config['name']);
    config(['database.connections.customer_recovery_acceptance' => [...$config, 'database' => $name]]);
    try {
        foreach ($baseline['tables'] as $table => $snapshot) {
            expect(customerRehearsalTableSnapshot($table, $snapshot['columns'], 'customer_recovery_acceptance'))
                ->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
        }
        expect(DB::connection('customer_recovery_acceptance')->table('migrations')->orderBy('id')->pluck('migration')->all())->toBe($baseline['migrations']);
    } finally {
        DB::disconnect('customer_recovery_acceptance');
    }
});
