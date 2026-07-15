<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $permissionsTable = config('permission.table_names.permissions', 'permissions');

        Schema::dropIfExists('temperature_logs');

        DB::table('settings')
            ->whereIn('key', [
                'document_numbers.temperature_logs.prefix',
                'document_numbers.temperature_logs.padding',
            ])
            ->delete();

        DB::table($permissionsTable)
            ->whereIn('name', [
                'tools.temperature_logs.view',
                'tools.temperature_logs.create',
                'tools.temperature_logs.clone',
                'tools.temperature_logs.edit',
                'tools.temperature_logs.delete',
                'tools.temperature_logs.view_trashed',
                'tools.temperature_logs.restore',
                'tools.temperature_logs.document_number.control',
                'tools.temperature_logs.document_number_settings.update',
            ])
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible: the Temperature Logs feature was removed.
    }
};
