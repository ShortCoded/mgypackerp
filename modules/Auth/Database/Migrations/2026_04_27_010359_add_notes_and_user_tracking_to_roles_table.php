<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        Schema::table($rolesTable, function (Blueprint $table) use ($rolesTable): void {
            if (! Schema::hasColumn($rolesTable, 'notes')) {
                $table->text('notes')->nullable()->after('name');
            }

            if (! Schema::hasColumn($rolesTable, 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('doc_num')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn($rolesTable, 'updated_by')) {
                $table->foreignId('updated_by')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn($rolesTable, 'deleted_by')) {
                $table->foreignId('deleted_by')
                    ->nullable()
                    ->after('updated_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        Schema::table($rolesTable, function (Blueprint $table) use ($rolesTable): void {
            foreach (['deleted_by', 'updated_by', 'created_by'] as $column) {
                if (Schema::hasColumn($rolesTable, $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            if (Schema::hasColumn($rolesTable, 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
