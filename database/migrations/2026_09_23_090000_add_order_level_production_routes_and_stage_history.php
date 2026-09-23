<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_stage_snapshots', function (Blueprint $table): void {
            $table->string('route_scope_key', 64)->default('order')->after('production_order_line_id');
            $table->foreignId('created_by')->nullable()->after('completed_by')->constrained('users')->nullOnDelete();
        });

        DB::table('production_order_stage_snapshots')
            ->whereNotNull('production_order_line_id')
            ->orderBy('id')
            ->chunkById(500, function ($snapshots): void {
                foreach ($snapshots as $snapshot) {
                    DB::table('production_order_stage_snapshots')
                        ->where('id', $snapshot->id)
                        ->update(['route_scope_key' => 'line:'.$snapshot->production_order_line_id]);
                }
            });

        Schema::table('production_order_stage_snapshots', function (Blueprint $table): void {
            $table->dropForeign(['production_order_line_id']);
            $table->dropUnique('production_order_stage_snapshots_line_sequence_unique');
            $table->unsignedBigInteger('production_order_line_id')->nullable()->change();
            $table->foreign('production_order_line_id', 'production_order_stage_snapshots_production_order_line_id_foreign')
                ->references('id')->on('production_order_lines')->cascadeOnDelete();
            $table->unique(
                ['production_order_id', 'route_scope_key', 'sequence'],
                'production_order_stage_snapshots_scope_sequence_unique',
            );
        });

        Schema::create('production_order_stage_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('production_order_stage_snapshot_id')
                ->constrained('production_order_stage_snapshots')
                ->restrictOnDelete();
            $table->foreignId('production_run_id')->nullable()->constrained('production_runs')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('previous_status', 30)->nullable();
            $table->string('status', 30);
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->index(['production_order_stage_snapshot_id', 'occurred_at'], 'production_order_stage_events_timeline_index');
        });
    }

    public function down(): void
    {
        if (DB::table('production_order_stage_events')->exists()
            || DB::table('production_order_stage_snapshots')->whereNull('production_order_line_id')->exists()) {
            throw new RuntimeException('Production route history is in use and cannot be rolled back without losing execution evidence.');
        }

        Schema::dropIfExists('production_order_stage_events');

        Schema::table('production_order_stage_snapshots', function (Blueprint $table): void {
            $table->dropUnique('production_order_stage_snapshots_scope_sequence_unique');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('route_scope_key');
            $table->dropForeign(['production_order_line_id']);
            $table->unsignedBigInteger('production_order_line_id')->nullable(false)->change();
            $table->foreign('production_order_line_id', 'production_order_stage_snapshots_production_order_line_id_foreign')
                ->references('id')->on('production_order_lines')->cascadeOnDelete();
            $table->unique(['production_order_line_id', 'sequence'], 'production_order_stage_snapshots_line_sequence_unique');
        });
    }
};
