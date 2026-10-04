<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerMigrationSupport.php';

test('additive later-period payroll correction migration preserves every existing row and sequence in the named isolated databases', function (): void {
    if (getenv('MGYPACK_PAYROLL_LATER_PERIOD_MIGRATE') !== '1') {
        $this->markTestSkipped('Explicit isolated migration rehearsal only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBeIn(['mgypack_acceptance_closure_20261003', 'mgypack_customer_20261003'])
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $baseline = [];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if ($table === 'migrations') {
            continue;
        }
        $columns = Schema::getColumnListing($table);
        $baseline[$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
    }
    $ownership = DB::table('hr_payroll_posting_column_ownership')->orderBy('column_name')->get();
    $sequences = DB::select("select sequencename, last_value from pg_sequences where schemaname='public' and sequencename <> 'migrations_id_seq' order by sequencename");
    if ($identity->db === 'mgypack_customer_20261003' && ! Schema::hasColumn('hr_payroll_corrections', 'correction_mode')) {
        $backup = '/tmp/mgypack-customer-pre-payroll-later-period-20261003.dump';
        expect(file_exists($backup))->toBeFalse();
        $config = DB::connection()->getConfig();
        (new Process(['pg_dump', '--format=custom', '--no-password', '--host=127.0.0.1', '--port=5432',
            '--username='.$config['username'], '--file='.$backup, $identity->db], env: ['PGPASSWORD' => $config['password']]))->setTimeout(60)->mustRun();
        chmod($backup, 0600);
        $path = '/tmp/mgypack-customer-pre-payroll-later-period-20261003.json';
        file_put_contents($path, json_encode(['database' => $identity->db, 'backup' => $backup, 'backup_sha256' => hash_file('sha256', $backup),
            'tables' => $baseline, 'sequences' => $sequences], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }
    $options = ['--force' => true, '--no-interaction' => true, '--path' => 'modules/HR/Database/Migrations/2026_10_03_143436_add_later_period_mode_to_payroll_corrections.php'];
    expect(Artisan::call('migrate', $options))->toBe(0)->and(Schema::hasColumn('hr_payroll_corrections', 'correction_mode'))->toBeTrue();
    foreach ($baseline as $table => $snapshot) {
        if ($table === 'hr_payroll_posting_column_ownership') {
            expect(DB::table($table)->whereIn('column_name', $ownership->pluck('column_name'))->orderBy('column_name')->get()->toJson())->toBe($ownership->toJson());

            continue;
        }
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    foreach ($sequences as $sequence) {
        expect(DB::selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence->sequencename])->last_value)->toBe($sequence->last_value);
    }
    expect(DB::table('hr_payroll_posting_column_ownership')->where('column_name', 'hr_payroll_corrections.correction_mode')->value('created_by_migration'))->toBeTrue();
    expect(DB::table('hr_payroll_corrections')->whereNotNull('correction_mode')->count())->toBe(0);
    expect(Artisan::call('migrate', $options))->toBe(0)->and(Artisan::output())->toContain('Nothing to migrate');
});

test('restore the actual pre-snapshot customer backup to a fresh isolated recovery database and verify every row and sequence', function (): void {
    if (getenv('MGYPACK_PAYROLL_LATER_PERIOD_RECOVERY') !== '1') {
        $this->markTestSkipped('Once-only explicit isolated restore rehearsal.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_customer_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $baseline = json_decode(file_get_contents('/tmp/mgypack-customer-pre-payroll-later-period-20261003.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($baseline['database'])->toBe($identity->db)->and(hash_file('sha256', $baseline['backup']))->toBe($baseline['backup_sha256']);
    $name = 'mgypack_payroll_later_recovery_20261003';
    expect(DB::selectOne('select 1 as found from pg_database where datname = ?', [$name]))->toBeNull();
    DB::statement('CREATE DATABASE mgypack_payroll_later_recovery_20261003');
    $config = DB::connection()->getConfig();
    (new Process(['pg_restore', '--exit-on-error', '--single-transaction', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--dbname='.$name, $baseline['backup']], env: ['PGPASSWORD' => $config['password']]))->setTimeout(60)->mustRun();
    unset($config['url'], $config['name']);
    config(['database.connections.payroll_later_recovery' => [...$config, 'database' => $name]]);
    try {
        foreach ($baseline['tables'] as $table => $snapshot) {
            expect(customerRehearsalTableSnapshot($table, $snapshot['columns'], 'payroll_later_recovery'))
                ->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
        }
        foreach ($baseline['sequences'] as $sequence) {
            expect(DB::connection('payroll_later_recovery')->selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence['sequencename']])->last_value)
                ->toBe($sequence['last_value'], $sequence['sequencename']);
        }
        expect(Schema::connection('payroll_later_recovery')->hasColumn('hr_payroll_corrections', 'correction_mode'))->toBeFalse();
    } finally {
        DB::disconnect('payroll_later_recovery');
    }
});
