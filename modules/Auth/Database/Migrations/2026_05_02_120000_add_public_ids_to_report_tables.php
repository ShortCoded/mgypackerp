<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'auth_logs',
        'user_presence_sessions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            $this->addPublicId($tableName);
        }

        $this->addPublicId(config('activitylog.table_name', 'activity_log'), config('activitylog.database_connection'));
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            $this->dropPublicId($tableName);
        }

        $this->dropPublicId(config('activitylog.table_name', 'activity_log'), config('activitylog.database_connection'));
    }

    private function addPublicId(string $tableName, ?string $connection = null): void
    {
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($tableName)) {
            return;
        }

        if (! $schema->hasColumn($tableName, 'public_id')) {
            $schema->table($tableName, function (Blueprint $table): void {
                $table->uuid('public_id')->nullable()->unique();
            });
        }

        DB::connection($connection)
            ->table($tableName)
            ->whereNull('public_id')
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(500, function ($rows) use ($connection, $tableName): void {
                foreach ($rows as $row) {
                    DB::connection($connection)
                        ->table($tableName)
                        ->where('id', $row->id)
                        ->whereNull('public_id')
                        ->update(['public_id' => (string) Str::uuid()]);
                }
            });
    }

    private function dropPublicId(string $tableName, ?string $connection = null): void
    {
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($tableName) || ! $schema->hasColumn($tableName, 'public_id')) {
            return;
        }

        $schema->table($tableName, function (Blueprint $table): void {
            $table->dropColumn('public_id');
        });
    }
};
