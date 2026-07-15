<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addUpdatedByColumn('archive_files');
        $this->addUpdatedByColumn('archive_folders');
    }

    public function down(): void
    {
        $this->dropUpdatedByColumn('archive_files');
        $this->dropUpdatedByColumn('archive_folders');
    }

    private function addUpdatedByColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'updated_by')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $wrappedTable = DB::getQueryGrammar()->wrapTable($tableName);
            $wrappedIndex = DB::getQueryGrammar()->wrap("{$tableName}_updated_by_index");

            DB::statement("ALTER TABLE {$wrappedTable} ADD COLUMN updated_by INTEGER NULL");
            DB::statement("CREATE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} (updated_by)");

            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $column = $table->foreignId('updated_by')->nullable();

            if (Schema::hasTable('users')) {
                $column->constrained('users')->nullOnDelete();
            }

            $table->index('updated_by');
        });
    }

    private function dropUpdatedByColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'updated_by')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $wrappedTable = DB::getQueryGrammar()->wrapTable($tableName);
            $wrappedIndex = DB::getQueryGrammar()->wrap("{$tableName}_updated_by_index");

            DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
            DB::statement("ALTER TABLE {$wrappedTable} DROP COLUMN updated_by");

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (Schema::hasTable('users')) {
                $table->dropForeign("{$tableName}_updated_by_foreign");
            }

            $table->dropIndex("{$tableName}_updated_by_index");
            $table->dropColumn('updated_by');
        });
    }
};
