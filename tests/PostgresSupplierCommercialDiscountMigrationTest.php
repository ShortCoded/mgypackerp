<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/CustomerMigrationSupport.php';

test('backup a named isolated clone and apply only additive supplier commercial discount migrations without changing legacy data', function (): void {
    if (getenv('MGYPACK_SUPPLIER_DISCOUNT_BOOTSTRAP') !== '1') {
        $this->markTestSkipped('Once-only explicitly authorized isolated PostgreSQL clone rehearsal.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_purchase_discount_pg_20261004')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $name = 'mgypack_supplier_discount_pg_20261004';
    expect(DB::selectOne('select 1 as found from pg_database where datname = ?', [$name]))->toBeNull();
    expect(Schema::hasColumn('supplier_quotation_lines', 'discount_type'))->toBeFalse()
        ->and(Schema::hasColumn('purchase_orders', 'header_discount_type'))->toBeTrue();
    $baseline = [];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        if ($table === 'migrations') {
            continue;
        }
        $columns = Schema::getColumnListing($table);
        $baseline[$table] = ['columns' => $columns, ...customerRehearsalTableSnapshot($table, $columns)];
    }
    $sequences = DB::select("select sequencename, last_value from pg_sequences where schemaname='public' and sequencename <> 'migrations_id_seq' order by sequencename");
    $backup = '/tmp/mgypack-supplier-discount-pre-migration-20261004.dump';
    $evidence = '/tmp/mgypack-supplier-discount-pg-baseline-20261004.json';
    expect(file_exists($backup))->toBeFalse()->and(file_exists($evidence))->toBeFalse();
    touch($backup);
    chmod($backup, 0600);
    $config = DB::connection()->getConfig();
    (new Process(['pg_dump', '--format=custom', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--file='.$backup, $identity->db], env: ['PGPASSWORD' => $config['password']]))->setTimeout(60)->mustRun();
    file_put_contents($evidence, json_encode(['source' => $identity->db, 'database' => $name, 'backup' => $backup,
        'backup_sha256' => hash_file('sha256', $backup), 'tables' => $baseline, 'sequences' => $sequences], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($evidence, 0600);
    DB::statement('CREATE DATABASE mgypack_supplier_discount_pg_20261004');
    (new Process(['pg_restore', '--exit-on-error', '--single-transaction', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--dbname='.$name, $backup], env: ['PGPASSWORD' => $config['password']]))->setTimeout(60)->mustRun();
    unset($config['url'], $config['name']);
    config(['database.connections.commercial_preview_rehearsal' => [...$config, 'database' => $name]]);
    try {
        foreach ($baseline as $table => $snapshot) {
            expect(customerRehearsalTableSnapshot($table, $snapshot['columns'], 'commercial_preview_rehearsal'))
                ->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
        }
        foreach ([
            'modules/Purchases/Database/Migrations/2026_10_04_173049_add_typed_commercial_discounts_to_supplier_quotes_and_selections.php',
        ] as $path) {
            $options = ['--database' => 'commercial_preview_rehearsal', '--force' => true, '--no-interaction' => true, '--path' => $path];
            expect(Artisan::call('migrate', $options))->toBe(0);
            foreach ($baseline as $table => $snapshot) {
                expect(customerRehearsalTableSnapshot($table, $snapshot['columns'], 'commercial_preview_rehearsal'))
                    ->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
            }
            expect(Artisan::call('migrate', $options))->toBe(0)->and(Artisan::output())->toContain('Nothing to migrate');
        }
        foreach ($sequences as $sequence) {
            expect(DB::connection('commercial_preview_rehearsal')->selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence->sequencename])->last_value)
                ->toBe($sequence->last_value, $sequence->sequencename);
        }
        expect(DB::connection('commercial_preview_rehearsal')->table('migrations')->count())->toBe(DB::table('migrations')->count() + 1);
        foreach ($baseline as $table => $snapshot) {
            expect(customerRehearsalTableSnapshot($table, $snapshot['columns']))
                ->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
        }
    } finally {
        DB::disconnect('commercial_preview_rehearsal');
    }
});

test('after rollback supplier commercial discount PostgreSQL legacy data and source clone match their backup baseline', function (): void {
    if (getenv('MGYPACK_SUPPLIER_DISCOUNT_RECONCILE') !== '1') {
        $this->markTestSkipped('Explicit post-test PostgreSQL reconciliation only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_supplier_discount_pg_20261004')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
    $baseline = json_decode(file_get_contents('/tmp/mgypack-supplier-discount-pg-baseline-20261004.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($baseline['database'])->toBe($identity->db)->and(hash_file('sha256', $baseline['backup']))->toBe($baseline['backup_sha256']);
    $config = DB::connection()->getConfig();
    unset($config['url'], $config['name']);
    config(['database.connections.commercial_preview_source' => [...$config, 'database' => $baseline['source']]]);
    try {
        foreach ($baseline['tables'] as $table => $snapshot) {
            foreach ([null, 'commercial_preview_source'] as $connection) {
                expect(customerRehearsalTableSnapshot($table, $snapshot['columns'], $connection))
                    ->toBe(['rows' => $snapshot['rows'], 'sha256' => $snapshot['sha256']], $table);
            }
        }
        foreach ($baseline['sequences'] as $sequence) {
            expect(DB::connection('commercial_preview_source')->selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence['sequencename']])->last_value)
                ->toBe($sequence['last_value'], $sequence['sequencename']);
        }
        expect(Schema::connection('commercial_preview_source')->hasColumn('supplier_quotation_lines', 'discount_type'))->toBeFalse();
        expect(DB::table('supplier_quotations')->whereNotNull('header_discount_type')->orWhereNotNull('header_discount_value')->orWhere('header_discount_amount', '<>', 0)->count())->toBe(0);
        foreach (['supplier_quotation_lines', 'supplier_selection_lines'] as $table) {
            expect(DB::table($table)->whereNotNull('discount_type')->orWhereNotNull('discount_value')->orWhereNotNull('subtotal_amount')->orWhere('header_discount_amount', '<>', 0)->count())->toBe(0);
        }
        expect(DB::table('supplier_selection_lines')->whereNotNull('source_discount_snapshot')->orWhereNotNull('header_discount_type')->orWhereNotNull('header_discount_value')->count())->toBe(0);
        $result = ['database' => $identity->db, 'source_database' => $baseline['source'], 'backup' => $baseline['backup'],
            'backup_sha256' => $baseline['backup_sha256'], 'legacy_tables_matched' => count($baseline['tables']),
            'source_legacy_tables_matched' => count($baseline['tables']), 'source_sequences_unchanged' => true,
            'legacy_rows_unchanged' => true, 'synthetic_rows_rolled_back' => true,
            'migrations_applied' => DB::table('migrations')->whereIn('migration', [
                '2026_10_04_173049_add_typed_commercial_discounts_to_supplier_quotes_and_selections',
            ])->orderBy('migration')->pluck('migration')->all(),
            'test_clone_sequences' => DB::select("select sequencename, last_value from pg_sequences where schemaname='public' order by sequencename")];
        expect($result['migrations_applied'])->toHaveCount(1);
        file_put_contents('/tmp/mgypack-supplier-discount-pg-reconciliation-20261004.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod('/tmp/mgypack-supplier-discount-pg-reconciliation-20261004.json', 0600);
    } finally {
        DB::disconnect('commercial_preview_source');
    }
});
