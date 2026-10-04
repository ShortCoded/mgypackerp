<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Database\Seeders\BoardListSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Database\Seeders\SettingSeeder;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

$pristineClosureSuffix = match (getenv('MGYPACK_FINAL_PRISTINE_CLOSURE')) {
    '2' => 'final2',
    '1' => 'final',
    default => '',
};
define('PristineClosureDatabase', 'mgypack_pristine_closure_'.($pristineClosureSuffix === '' ? '' : $pristineClosureSuffix.'_').'20261003');
define('PristineClosureRecoveryDatabase', 'mgypack_pristine_closure_'.($pristineClosureSuffix === '' ? '' : $pristineClosureSuffix.'_').'recovery_20261003');
define('PristineClosureManifest', '/tmp/mgypack-pristine-closure-'.($pristineClosureSuffix === '' ? '' : $pristineClosureSuffix.'-').'package-20261003.json');
define('PristineClosureBackup', '/tmp/mgypack-pristine-closure-'.($pristineClosureSuffix === '' ? '' : $pristineClosureSuffix.'-').'before-package-20261003.dump');
const PristineClosureDump = '/mnt/Me/MB/DB/SQLs/Mgy Pack/dump-erp-202610030521.sql';

beforeEach(function (): void {
    if (getenv('MGYPACK_PRISTINE_CLOSURE') !== '1') {
        $this->markTestSkipped('Explicit isolated pristine migration package only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe('mgypack_customer_20261003')
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

/** @param array<string, mixed>|null $definition @return array<string, mixed> */
function pristineClosureTable(string $table, ?array $definition = null, ?Closure $plannedMetadata = null): array
{
    $columns = $definition['columns'] ?? Schema::getColumnListing($table);
    $numeric = $definition['numeric'] ?? array_map(fn ($column) => $column->column_name, DB::select(
        "select column_name from information_schema.columns where table_schema='public' and table_name=? and data_type in ('numeric','bigint','integer','smallint')", [$table],
    ));
    $hashes = [];
    foreach (DB::table($table)->select($columns)->cursor() as $row) {
        $values = (array) $row;
        if ($plannedMetadata !== null) {
            $values = $plannedMetadata($values);
        }
        foreach ($numeric as $column) {
            if ($values[$column] !== null) {
                $value = (string) $values[$column];
                $values[$column] = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
                if ($values[$column] === '-0') {
                    $values[$column] = '0';
                }
            }
        }
        $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
        $hashes[$hash] = ($hashes[$hash] ?? 0) + 1;
    }
    ksort($hashes, SORT_STRING);

    return ['columns' => $columns, 'numeric' => $numeric, 'rows' => array_sum($hashes), 'hashes' => $hashes,
        'sha256' => hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR))];
}

/** @return array<string, array<string, mixed>> */
function pristineClosureTables(): array
{
    $tables = [];
    foreach (Schema::getTableListing(schema: 'public', schemaQualified: false) as $table) {
        $tables[$table] = pristineClosureTable($table);
    }
    ksort($tables, SORT_STRING);

    return $tables;
}

/** @param array<string, array<string, mixed>> $tables @param list<string> $additiveTables */
function pristineClosureAssertTables(array $tables, array $additiveTables = []): void
{
    foreach ($tables as $table => $baseline) {
        expect(Schema::hasTable($table))->toBeTrue($table)
            ->and(array_diff($baseline['columns'], Schema::getColumnListing($table)))->toBe([], $table);
        $current = pristineClosureTable($table, $baseline);
        if (in_array($table, $additiveTables, true)) {
            foreach ($baseline['hashes'] as $hash => $count) {
                expect($current['hashes'][$hash] ?? 0)->toBeGreaterThanOrEqual($count, $table);
            }
        } else {
            expect($current['sha256'])->toBe($baseline['sha256'], $table);
        }
    }
}

/** @return array<string, string> */
function pristineClosurePackage(): array
{
    $files = [];
    foreach ([database_path('migrations'), ...glob(base_path('modules/*/Database/Migrations'), GLOB_ONLYDIR)] as $directory) {
        foreach (glob($directory.'/*.php') as $path) {
            $files[str_replace(base_path().'/', '', $path)] = hash_file('sha256', $path);
        }
    }
    ksort($files, SORT_STRING);

    return $files;
}

/** @param array<string, mixed> $manifest */
function pristineClosureSave(array $manifest): void
{
    file_put_contents(PristineClosureManifest, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod(PristineClosureManifest, 0600);
}

/** @return array<string, mixed> */
function pristineClosureLoad(): array
{
    $manifest = json_decode(file_get_contents(PristineClosureManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['database'])->toBe(PristineClosureDatabase)
        ->and(hash_file('sha256', PristineClosureDump))->toBe($manifest['source_sha256'])
        ->and(pristineClosurePackage())->toBe($manifest['migration_files']);

    return $manifest;
}

/** @param Closure(): void $callback */
function pristineClosureInDatabase(string $database, Closure $callback): void
{
    expect($database)->toBeIn([PristineClosureDatabase, PristineClosureRecoveryDatabase]);
    $original = DB::getDefaultConnection();
    $config = DB::connection()->getConfig();
    unset($config['url'], $config['name']);
    config(['database.connections.pristine_closure' => [...$config, 'database' => $database]]);
    DB::purge('pristine_closure');
    DB::setDefaultConnection('pristine_closure');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    try {
        expect(DB::selectOne('select current_database() as db')->db)->toBe($database);
        $callback();
    } finally {
        DB::setDefaultConnection($original);
        DB::disconnect('pristine_closure');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

/** @return list<array{sequencename: string, last_value: mixed}> */
function pristineClosureSequences(): array
{
    return array_map(fn ($row) => (array) $row, DB::select("select sequencename,last_value from pg_sequences where schemaname='public' order by sequencename"));
}

/** @param list<array{sequencename: string, last_value: mixed}> $sequences */
function pristineClosureAssertSequences(array $sequences, bool $allowMigrationSequence = false, array $appendOnlySequences = []): void
{
    foreach ($sequences as $sequence) {
        if ($allowMigrationSequence && $sequence['sequencename'] === 'migrations_id_seq') {
            continue;
        }
        $current = DB::selectOne("select last_value from pg_sequences where schemaname='public' and sequencename=?", [$sequence['sequencename']])->last_value;
        if (in_array($sequence['sequencename'], $appendOnlySequences, true)) {
            expect($current)->toBeGreaterThanOrEqual($sequence['last_value'], $sequence['sequencename']);
        } else {
            expect($current)->toBe($sequence['last_value'], $sequence['sequencename']);
        }
    }
}

test('pristine package imports the exact raw customer dump into a new isolated database and captures recovery', function (): void {
    expect(file_exists(PristineClosureManifest))->toBeFalse()
        ->and(DB::selectOne('select 1 from pg_database where datname=?', [PristineClosureDatabase]))->toBeNull();
    $control = pristineClosureTables();
    $controlSequences = pristineClosureSequences();
    $sql = file_get_contents(PristineClosureDump);
    expect(preg_match('/^\\s*(?:CREATE|DROP)\\s+DATABASE|^\\\\(?:connect|c|!)(?:\\s|$)|ALTER\\s+SYSTEM|COPY[^;]*PROGRAM/im', $sql))->toBe(0);
    $config = DB::connection()->getConfig();
    DB::statement('CREATE DATABASE '.PristineClosureDatabase);
    (new Process(['psql', '--no-password', '--single-transaction', '--set=ON_ERROR_STOP=1', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--dbname='.PristineClosureDatabase, '--file='.PristineClosureDump], env: ['PGPASSWORD' => $config['password']]))
        ->setTimeout(90)->mustRun();
    pristineClosureInDatabase(PristineClosureDatabase, function () use ($control, $controlSequences): void {
        $backup = PristineClosureBackup;
        expect(file_exists($backup))->toBeFalse();
        $config = DB::connection()->getConfig();
        (new Process(['pg_dump', '--no-password', '--format=custom', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'],
            '--file='.$backup, PristineClosureDatabase], env: ['PGPASSWORD' => $config['password']]))->setTimeout(90)->mustRun();
        chmod($backup, 0600);
        $manifest = ['database' => PristineClosureDatabase, 'source_sha256' => hash_file('sha256', PristineClosureDump),
            'migration_files' => pristineClosurePackage(), 'before' => pristineClosureTables(), 'sequences_before' => pristineClosureSequences(),
            'backup' => $backup, 'backup_sha256' => hash_file('sha256', $backup), 'control_before' => $control, 'control_sequences' => $controlSequences,
            'migrations_before' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(), 'stage' => 'imported'];
        expect(count($manifest['before']))->toBeGreaterThan(300);
        pristineClosureSave($manifest);
    });
    pristineClosureAssertTables($control);
    pristineClosureAssertSequences($controlSequences);
});

test('pristine package recovers the raw backup and records the exact converted request closure metadata plan', function (): void {
    $manifest = pristineClosureLoad();
    expect(hash_file('sha256', $manifest['backup']))->toBe($manifest['backup_sha256'])
        ->and(DB::selectOne('select 1 from pg_database where datname=?', [PristineClosureRecoveryDatabase]))->toBeNull();
    DB::statement('CREATE DATABASE '.PristineClosureRecoveryDatabase);
    $config = DB::connection()->getConfig();
    (new Process(['pg_restore', '--exit-on-error', '--single-transaction', '--no-password', '--host=127.0.0.1', '--port=5432',
        '--username='.$config['username'], '--dbname='.PristineClosureRecoveryDatabase, $manifest['backup']], env: ['PGPASSWORD' => $config['password']]))->setTimeout(90)->mustRun();
    pristineClosureInDatabase(PristineClosureRecoveryDatabase, function () use (&$manifest): void {
        pristineClosureAssertTables($manifest['before']);
        pristineClosureAssertSequences($manifest['sequences_before']);
        expect(DB::table('sales_requests')->where('status', 'converted')->whereNull('closed_at')
            ->whereNull('updated_at')->whereNull('created_at')->count())->toBe(0);
        $manifest['planned_sales_request_closure'] = pristineClosureTable('sales_requests', $manifest['before']['sales_requests'], function (array $row): array {
            if ($row['status'] === 'converted' && $row['closed_at'] === null) {
                $row['closed_at'] = $row['updated_at'] ?? $row['created_at'];
                $row['closed_by'] = $row['updated_by'] ?? $row['approved_by'];
            }

            return $row;
        });
        $manifest['planned_sales_request_closure_count'] = DB::table('sales_requests')->where('status', 'converted')->whereNull('closed_at')->count();
        $manifest['recovery_database'] = PristineClosureRecoveryDatabase;
        pristineClosureSave($manifest);
    });
    pristineClosureAssertTables($manifest['control_before']);
    pristineClosureAssertSequences($manifest['control_sequences']);
});

test('pristine package applies all current migrations and preserves original customer values with a no op repeat', function (): void {
    $manifest = pristineClosureLoad();
    pristineClosureInDatabase(PristineClosureDatabase, function () use (&$manifest): void {
        expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]))->toBe(0);
        $manifest['migrate_output'] = Artisan::output();
        $baseline = $manifest['before'];
        unset($baseline['migrations']);
        $baseline['sales_requests'] = $manifest['planned_sales_request_closure'];
        pristineClosureAssertTables($baseline);
        pristineClosureAssertSequences($manifest['sequences_before'], true);
        expect(DB::table('migrations')->whereIn('migration', $manifest['migrations_before'])->count())->toBe(count($manifest['migrations_before']));
        $manifest['after_migrations'] = pristineClosureTables();
        $manifest['sequences_after_migrations'] = pristineClosureSequences();
        $manifest['migrations_after'] = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('Nothing to migrate');
        pristineClosureAssertTables($manifest['after_migrations']);
        pristineClosureAssertSequences($manifest['sequences_after_migrations']);
        $manifest['stage'] = 'migrated';
        pristineClosureSave($manifest);
    });
    pristineClosureAssertTables($manifest['control_before']);
    pristineClosureAssertSequences($manifest['control_sequences']);
});

test('pristine package previews permission changes runs real safe configuration and proves repeatability', function (): void {
    $manifest = pristineClosureLoad();
    expect($manifest['stage'])->toBeIn(['migrated', 'configured', 'recovered']);
    pristineClosureInDatabase(PristineClosureDatabase, function () use (&$manifest): void {
        $before = pristineClosureTables();
        $sequences = pristineClosureSequences();
        expect(Artisan::call('erp:permissions:sync', ['--dry-run' => true, '--skip-admin-sync' => true]))->toBe(0);
        $manifest['permission_dry_run'] = Artisan::output();
        pristineClosureAssertTables($before);
        pristineClosureAssertSequences($sequences);
        expect(Artisan::call('erp:permissions:sync', ['--skip-admin-sync' => true]))->toBe(0);
        $manifest['permission_apply'] = Artisan::output();
        pristineClosureAssertTables($before, ['permissions']);
        foreach ([DatabaseSeeder::class, SettingSeeder::class,
            AccountClassificationsSeeder::class, CurrencySeeder::class,
            BoardListSeeder::class] as $seeder) {
            expect(Artisan::call('db:seed', ['--class' => $seeder, '--force' => true, '--no-interaction' => true]))->toBe(0);
            $manifest['configuration_output'][$seeder] = Artisan::output();
        }
        $additive = ['settings', 'permissions', 'accounts', 'account_classifications', 'cost_centers', 'currencies', 'board_lists', 'role_has_permissions', 'model_has_permissions'];
        pristineClosureAssertTables($before, $additive);
        pristineClosureAssertTables($manifest['after_migrations'], $additive);
        $manifest['after_configuration'] = pristineClosureTables();
        $manifest['sequences_after_configuration'] = pristineClosureSequences();
        foreach (array_keys($manifest['configuration_output']) as $seeder) {
            expect(Artisan::call('db:seed', ['--class' => $seeder, '--force' => true, '--no-interaction' => true]))->toBe(0);
        }
        expect(Artisan::call('erp:permissions:sync', ['--skip-admin-sync' => true]))->toBe(0);
        pristineClosureAssertTables($manifest['after_configuration']);
        pristineClosureAssertSequences($manifest['sequences_after_configuration'], appendOnlySequences: ['permissions_id_seq', 'settings_id_seq']);
        $manifest['sequences_after_configuration_repeat'] = pristineClosureSequences();
        expect(DB::table('permissions')->where('guard_name', 'web')->whereIn('name', app(PermissionRegistryService::class)->all())->count())
            ->toBe(count(app(PermissionRegistryService::class)->all()));
        $manifest['stage'] = 'configured';
        pristineClosureSave($manifest);
    });
    pristineClosureAssertTables($manifest['control_before']);
    pristineClosureAssertSequences($manifest['control_sequences']);
});

test('pristine package migrates its recovered raw backup and repeats the migration package', function (): void {
    $manifest = pristineClosureLoad();
    expect($manifest['stage'])->toBe('configured')->and(hash_file('sha256', $manifest['backup']))->toBe($manifest['backup_sha256'])
        ->and($manifest['recovery_database'])->toBe(PristineClosureRecoveryDatabase);
    pristineClosureInDatabase(PristineClosureRecoveryDatabase, function () use (&$manifest): void {
        pristineClosureAssertTables($manifest['before']);
        pristineClosureAssertSequences($manifest['sequences_before']);
        expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]))->toBe(0);
        $manifest['recovery_migrate_output'] = Artisan::output();
        $baseline = $manifest['before'];
        unset($baseline['migrations']);
        $baseline['sales_requests'] = $manifest['planned_sales_request_closure'];
        pristineClosureAssertTables($baseline);
        pristineClosureAssertSequences($manifest['sequences_before'], true);
        expect(DB::table('migrations')->orderBy('id')->pluck('migration')->all())->toBe($manifest['migrations_after']);
        $after = pristineClosureTables();
        $sequences = pristineClosureSequences();
        expect(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]))->toBe(0)->and(Artisan::output())->toContain('Nothing to migrate');
        pristineClosureAssertTables($after);
        pristineClosureAssertSequences($sequences);
        $manifest['recovery_database'] = PristineClosureRecoveryDatabase;
        $manifest['recovery_after'] = $after;
        $manifest['stage'] = 'recovered';
        pristineClosureSave($manifest);
    });
    pristineClosureAssertTables($manifest['control_before']);
    pristineClosureAssertSequences($manifest['control_sequences']);
});

test('recovered package reapplies reviewed configuration and remains repeatable', function (): void {
    $manifest = pristineClosureLoad();
    expect($manifest['stage'])->toBeIn(['recovered', 'recovery_configured']);
    pristineClosureInDatabase(PristineClosureRecoveryDatabase, function () use (&$manifest): void {
        $before = pristineClosureTables();
        $sequences = pristineClosureSequences();
        expect(Artisan::call('erp:permissions:sync', ['--dry-run' => true, '--skip-admin-sync' => true]))->toBe(0);
        $manifest['recovery_permission_dry_run'] = Artisan::output();
        pristineClosureAssertTables($before);
        pristineClosureAssertSequences($sequences);
        expect(Artisan::call('erp:permissions:sync', ['--skip-admin-sync' => true]))->toBe(0);
        $manifest['recovery_permission_apply'] = Artisan::output();
        pristineClosureAssertTables($before, ['permissions']);
        foreach (array_keys($manifest['configuration_output']) as $seeder) {
            expect(Artisan::call('db:seed', ['--class' => $seeder, '--force' => true, '--no-interaction' => true]))->toBe(0);
            $manifest['recovery_configuration_output'][$seeder] = Artisan::output();
        }
        $additive = ['settings', 'permissions', 'accounts', 'account_classifications', 'cost_centers', 'currencies', 'board_lists', 'role_has_permissions', 'model_has_permissions'];
        pristineClosureAssertTables($manifest['recovery_after'], $additive);
        $configured = pristineClosureTables();
        $configuredSequences = pristineClosureSequences();
        foreach (array_keys($manifest['recovery_configuration_output']) as $seeder) {
            expect(Artisan::call('db:seed', ['--class' => $seeder, '--force' => true, '--no-interaction' => true]))->toBe(0);
        }
        expect(Artisan::call('erp:permissions:sync', ['--skip-admin-sync' => true]))->toBe(0);
        pristineClosureAssertTables($configured);
        pristineClosureAssertSequences($configuredSequences, appendOnlySequences: ['permissions_id_seq', 'settings_id_seq']);
        expect(DB::table('permissions')->where('guard_name', 'web')->whereIn('name', app(PermissionRegistryService::class)->all())->count())
            ->toBe(count(app(PermissionRegistryService::class)->all()));
        $manifest['recovery_after_configuration'] = pristineClosureTables();
        $manifest['recovery_sequences_after_configuration'] = pristineClosureSequences();
        $manifest['stage'] = 'recovery_configured';
        pristineClosureSave($manifest);
    });
    pristineClosureAssertTables($manifest['control_before']);
    pristineClosureAssertSequences($manifest['control_sequences']);
});
