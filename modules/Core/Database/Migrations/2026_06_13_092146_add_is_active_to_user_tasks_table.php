<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_tasks') || Schema::hasColumn('user_tasks', 'is_active')) {
            return;
        }

        Schema::table('user_tasks', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('user_tasks', function (Blueprint $table): void {
            $table->index('is_active', 'user_tasks_is_active_index');
            $table->index(['type', 'is_active', 'deleted_at'], 'user_tasks_type_is_active_deleted_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_tasks') || ! Schema::hasColumn('user_tasks', 'is_active')) {
            return;
        }

        $indexes = [
            'user_tasks_type_is_active_deleted_at_index',
            'user_tasks_is_active_index',
        ];

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            foreach ($indexes as $index) {
                $wrappedIndex = DB::getQueryGrammar()->wrap($index);

                DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
            }
        } else {
            Schema::table('user_tasks', function (Blueprint $table) use ($indexes): void {
                foreach ($indexes as $index) {
                    $table->dropIndex($index);
                }
            });
        }

        Schema::table('user_tasks', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
