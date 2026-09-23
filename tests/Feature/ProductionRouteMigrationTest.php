<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('production route migration preserves existing line snapshots and permits order-level stages', function (): void {
    $connection = 'production_route_migration_probe';
    $originalConnection = config('database.default');
    config(['database.connections.'.$connection => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::connection($connection)->create('users', function ($table): void {
            $table->id();
        });
        Schema::connection($connection)->create('production_order_lines', function ($table): void {
            $table->id();
        });
        Schema::connection($connection)->create('production_runs', function ($table): void {
            $table->id();
        });
        Schema::connection($connection)->create('production_order_stage_snapshots', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('production_order_id');
            $table->unsignedBigInteger('production_order_line_id');
            $table->unsignedBigInteger('production_stage_id');
            $table->unsignedInteger('sequence');
            $table->string('stage_code');
            $table->string('stage_name');
            $table->string('status', 30);
            $table->boolean('is_required')->default(true);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable();
            $table->timestamps();
            $table->foreign('production_order_line_id', 'production_order_stage_snapshots_production_order_line_id_foreign')
                ->references('id')->on('production_order_lines')->cascadeOnDelete();
            $table->unique(['production_order_line_id', 'sequence'], 'production_order_stage_snapshots_line_sequence_unique');
        });

        DB::connection($connection)->table('production_order_lines')->insert(['id' => 15]);
        DB::connection($connection)->table('production_order_stage_snapshots')->insert([
            'id' => 21,
            'production_order_id' => 10,
            'production_order_line_id' => 15,
            'production_stage_id' => 4,
            'sequence' => 1,
            'stage_code' => 'MIX',
            'stage_name' => 'Mix',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_09_23_090000_add_order_level_production_routes_and_stage_history.php');
        $migration->up();

        expect(DB::connection($connection)->table('production_order_stage_snapshots')->where('id', 21)->value('route_scope_key'))
            ->toBe('line:15');

        DB::connection($connection)->table('production_order_stage_snapshots')->insert([
            'production_order_id' => 10,
            'production_order_line_id' => null,
            'route_scope_key' => 'order',
            'production_stage_id' => 5,
            'sequence' => 1,
            'stage_code' => 'PACK',
            'stage_name' => 'Pack',
            'status' => 'pending',
            'is_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::connection($connection)->table('production_order_stage_snapshots')->count())->toBe(2)
            ->and(Schema::connection($connection)->hasTable('production_order_stage_events'))->toBeTrue();
    } finally {
        DB::purge($connection);
        DB::setDefaultConnection($originalConnection);
    }
});

test('production batch migration refuses rollback while batch lineage exists', function (): void {
    $connection = 'production_batch_migration_probe';
    $originalConnection = config('database.default');
    config(['database.connections.'.$connection => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        foreach (['users', 'companies', 'financial_periods', 'branches', 'production_orders'] as $tableName) {
            Schema::create($tableName, function ($table): void {
                $table->id();
            });
        }
        Schema::create('production_runs', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('production_order_id');
            $table->string('status', 30);
        });
        Schema::create('inventory_documents', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('production_run_id')->nullable();
            $table->string('document_type', 40);
            $table->string('status', 30);
        });

        $migration = require base_path('database/migrations/2026_09_23_050354_create_production_run_batches_table.php');
        $migration->up();
        DB::table('companies')->insert(['id' => 1]);
        DB::table('financial_periods')->insert(['id' => 1]);
        DB::table('branches')->insert(['id' => 1]);
        DB::table('production_orders')->insert(['id' => 1]);
        DB::table('production_run_batches')->insert([
            'public_id' => (string) Str::uuid(),
            'batch_number' => 'ORDER-001-B001',
            'company_id' => 1,
            'financial_period_id' => 1,
            'branch_id' => 1,
            'production_order_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => $migration->down())
            ->toThrow(RuntimeException::class, 'cannot be rolled back without losing inventory traceability.')
            ->and(Schema::hasTable('production_run_batches'))->toBeTrue()
            ->and(Schema::hasColumn('production_runs', 'production_run_batch_id'))->toBeTrue()
            ->and(Schema::hasColumn('inventory_documents', 'production_run_batch_id'))->toBeTrue();

        DB::table('production_run_batches')->delete();
        $migration->down();

        expect(Schema::hasTable('production_run_batches'))->toBeFalse()
            ->and(Schema::hasColumn('production_runs', 'production_run_batch_id'))->toBeFalse()
            ->and(Schema::hasColumn('inventory_documents', 'production_run_batch_id'))->toBeFalse();
    } finally {
        DB::purge($connection);
        DB::setDefaultConnection($originalConnection);
    }
});
