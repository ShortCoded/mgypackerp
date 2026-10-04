<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerMigrationSupport.php';

test('receipt application history migration is additive and repeatable on explicitly isolated databases', function (): void {
    if (getenv('MGYPACK_RECEIPT_HISTORY_MIGRATE') !== '1') {
        $this->markTestSkipped('Explicit isolated migration rehearsal only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBeIn([
        'mgypack_acceptance_closure_20261003', 'mgypack_customer_20261003', 'mgypack_sales_return_later_recovery_20261003',
    ])->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $baseline = [];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if ($table !== 'migrations') {
            $columns = Schema::getColumnListing($table);
            $baseline[$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
        }
    }
    $sequences = DB::select("select sequencename, last_value from pg_sequences where schemaname='public' and sequencename <> 'migrations_id_seq' order by sequencename");
    if ($identity->db === 'mgypack_customer_20261003' && ! Schema::hasTable('customer_receipt_application_events')) {
        $backup = '/tmp/mgypack-customer-pre-receipt-history-20261003.dump';
        expect(file_exists($backup))->toBeFalse();
        $config = DB::connection()->getConfig();
        (new Process(['pg_dump', '--format=custom', '--no-password', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'], '--file='.$backup, $identity->db],
            env: ['PGPASSWORD' => $config['password']]))->setTimeout(60)->mustRun();
        chmod($backup, 0600);
        $path = '/tmp/mgypack-customer-pre-receipt-history-20261003.json';
        file_put_contents($path, json_encode(['database' => $identity->db, 'backup' => $backup, 'backup_sha256' => hash_file('sha256', $backup),
            'tables' => $baseline, 'sequences' => $sequences], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }
    $path = ['database/migrations/2026_10_03_195742_create_customer_receipt_application_events_table.php',
        'database/migrations/2026_10_03_200656_add_settlement_evidence_to_customer_receipt_allocations_table.php'];
    expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true, '--path' => $path]))->toBe(0)
        ->and(Schema::hasTable('customer_receipt_application_events'))->toBeTrue()
        ->and(Schema::hasColumn('customer_receipt_allocations', 'settlement_evidence'))->toBeTrue();
    foreach ($baseline as $table => $snapshot) {
        expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
    }
    foreach ($sequences as $sequence) {
        expect(DB::selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence->sequencename])->last_value)->toBe($sequence->last_value);
    }
    expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true, '--path' => $path]))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to migrate');
});
