<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = config('activitylog.table_name', 'activity_log');
        $schema = Schema::connection(config('activitylog.database_connection'));

        if (! $schema->hasTable($tableName) || $schema->hasColumn($tableName, 'deduplication_key')) {
            return;
        }

        $schema->table($tableName, function (Blueprint $table): void {
            $table->string('deduplication_key', 64)
                ->nullable()
                ->unique('activity_log_deduplication_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('activitylog.table_name', 'activity_log');
        $schema = Schema::connection(config('activitylog.database_connection'));

        if (! $schema->hasTable($tableName) || ! $schema->hasColumn($tableName, 'deduplication_key')) {
            return;
        }

        $schema->table($tableName, function (Blueprint $table): void {
            $table->dropUnique('activity_log_deduplication_key_unique');
            $table->dropColumn('deduplication_key');
        });
    }
};
