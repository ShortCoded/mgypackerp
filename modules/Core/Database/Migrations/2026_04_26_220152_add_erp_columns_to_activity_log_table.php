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

        if (! $schema->hasTable($tableName)) {
            return;
        }

        $schema->table($tableName, function (Blueprint $table) use ($schema, $tableName): void {
            if (! $schema->hasColumn($tableName, 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable();
            }

            if (! $schema->hasColumn($tableName, 'module')) {
                $table->string('module')->nullable()->index();
            }

            if (! $schema->hasColumn($tableName, 'action')) {
                $table->string('action')->nullable()->index();
            }

            if (! $schema->hasColumn($tableName, 'status')) {
                $table->string('status')->nullable()->index();
            }

            if (! $schema->hasColumn($tableName, 'ip_address')) {
                $table->string('ip_address')->nullable();
            }

            if (! $schema->hasColumn($tableName, 'user_agent')) {
                $table->text('user_agent')->nullable();
            }

            if (! $schema->hasColumn($tableName, 'url')) {
                $table->text('url')->nullable();
            }

            if (! $schema->hasColumn($tableName, 'method')) {
                $table->string('method')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('activitylog.table_name', 'activity_log');
        $schema = Schema::connection(config('activitylog.database_connection'));

        if (! $schema->hasTable($tableName)) {
            return;
        }

        $columns = collect([
            'company_id',
            'module',
            'action',
            'status',
            'ip_address',
            'user_agent',
            'url',
            'method',
        ])->filter(fn (string $column): bool => $schema->hasColumn($tableName, $column))->all();

        if ($columns === []) {
            return;
        }

        $schema->table($tableName, function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
