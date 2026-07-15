<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        if (! Schema::hasTable($rolesTable)) {
            return;
        }

        Schema::table($rolesTable, function (Blueprint $table) use ($rolesTable): void {
            if (! Schema::hasColumn($rolesTable, 'deleted_at')) {
                $table->softDeletes()->index();
            }

            if (! Schema::hasColumn($rolesTable, 'deleted_by')) {
                $table->foreignId('deleted_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        if (! Schema::hasTable($rolesTable)) {
            return;
        }

        Schema::table($rolesTable, function (Blueprint $table) use ($rolesTable): void {
            if (Schema::hasColumn($rolesTable, 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
