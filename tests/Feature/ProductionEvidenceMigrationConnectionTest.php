<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('production evidence migrations target the explicit connection despite a previously resolved schema builder', function (): void {
    $original = DB::getDefaultConnection();
    $priorSchema = app('db.schema');
    $tables = ['companies', 'branches', 'users', 'production_shifts', 'fixed_assets', 'production_machines',
        'financial_periods', 'production_runs', 'inventory_documents', 'production_progress_entries', 'quality_inspections'];
    $paths = ['modules/Production/Database/Migrations/2026_10_05_104438_add_partial_output_evidence_to_production_execution.php',
        'modules/Production/Database/Migrations/2026_10_05_112916_create_production_shift_crew_evidence.php'];
    foreach (['evidence_schema_source', 'evidence_schema_target'] as $name) {
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        foreach ($tables as $table) {
            Schema::connection($name)->create($table, fn (Blueprint $blueprint) => $blueprint->id());
            DB::connection($name)->table($table)->insert(['id' => 7]);
        }
    }
    try {
        DB::setDefaultConnection('evidence_schema_source');
        app()->instance('db.schema', Schema::connection('evidence_schema_source'));
        expect(Schema::getConnection()->getName())->toBe('evidence_schema_source');
        $options = ['--database' => 'evidence_schema_target', '--path' => $paths, '--force' => true, '--no-interaction' => true];
        $assertTarget = function (bool $applied) use ($tables): void {
            foreach (['evidence_schema_source', 'evidence_schema_target'] as $name) {
                $schema = Schema::connection($name);
                $hasEvidence = $name === 'evidence_schema_target' && $applied;
                foreach (['production_shift_crews', 'production_shift_entries', 'production_quality_output_batches', 'production_quality_receipt_allocations'] as $table) {
                    expect($schema->hasTable($table))->toBe($hasEvidence, $name.'.'.$table);
                }
                expect($schema->hasColumn('production_progress_entries', 'production_shift_entry_id'))->toBe($hasEvidence)
                    ->and($schema->hasColumn('production_runs', 'material_accounting_mode'))->toBe($hasEvidence);
                foreach ($tables as $table) {
                    expect(DB::connection($name)->table($table)->pluck('id')->all())->toBe([7]);
                }
            }
            expect(DB::connection('evidence_schema_target')->table('migrations')->count())->toBe($applied ? 2 : 0)
                ->and(Schema::connection('evidence_schema_source')->hasTable('migrations'))->toBeFalse();
        };
        expect(Artisan::call('migrate', $options))->toBe(0);
        $assertTarget(true);
        expect(Artisan::call('migrate', $options))->toBe(0)->and(Artisan::output())->toContain('Nothing to migrate');
        $assertTarget(true);
        expect(Artisan::call('migrate:rollback', [...$options, '--step' => 2]))->toBe(0);
        $assertTarget(false);
        expect(Artisan::call('migrate', $options))->toBe(0);
        $assertTarget(true);
        expect(Artisan::call('migrate', $options))->toBe(0)->and(Artisan::output())->toContain('Nothing to migrate');
        $assertTarget(true);
    } finally {
        DB::setDefaultConnection($original);
        app()->instance('db.schema', $priorSchema);
        DB::purge('evidence_schema_source');
        DB::purge('evidence_schema_target');
    }
});

test('native factory save remains on a copied connection after its original name metadata is removed', function (): void {
    $original = DB::getDefaultConnection();
    $source = DB::connection($original);
    $before = $source->table('users')->count();
    $configuration = $source->getConfig();
    unset($configuration['name'], $configuration['url']);
    $target = 'evidence_factory_target';
    config(['database.connections.'.$target => [...$configuration, 'database' => ':memory:']]);
    $ddl = $source->selectOne("select sql from sqlite_master where type='table' and name='users'");
    DB::connection($target)->statement($ddl->sql);
    foreach ($source->select('pragma foreign_key_list(users)') as $foreignKey) {
        if (! Schema::connection($target)->hasTable($foreignKey->table)) {
            Schema::connection($target)->create($foreignKey->table, fn (Blueprint $table) => $table->id());
        }
    }
    try {
        DB::setDefaultConnection($target);
        expect(DB::connection()->getName())->toBe($target)->and((new User)->getConnection()->getName())->toBe($target);
        $user = User::factory()->create(['doc_num' => 'SYNTHETIC-CONNECTION-PROBE', 'username' => 'synthetic-connection-probe',
            'email' => 'synthetic-connection-probe@example.test']);
        expect($user->getConnectionName())->toBe($target)->and(DB::connection($target)->table('users')->count())->toBe(1)
            ->and($source->table('users')->count())->toBe($before);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge($target);
    }
});
