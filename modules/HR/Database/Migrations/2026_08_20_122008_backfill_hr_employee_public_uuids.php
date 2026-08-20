<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hr_employees', 'public_uuid')) {
            return;
        }

        DB::table('hr_employees')
            ->whereNull('public_uuid')
            ->orderBy('id')
            ->chunkById(500, function ($employees): void {
                foreach ($employees as $employee) {
                    DB::table('hr_employees')
                        ->where('id', $employee->id)
                        ->whereNull('public_uuid')
                        ->update(['public_uuid' => (string) Str::uuid()]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Public identifiers are intentionally retained if this data migration is rolled back.
    }
};
