<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'type')) {
            return;
        }

        DB::table('branches')
            ->where('type', 'station')
            ->update(['type' => 'factory']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'type')) {
            return;
        }

        DB::table('branches')
            ->where('type', 'factory')
            ->update(['type' => 'station']);
    }
};
