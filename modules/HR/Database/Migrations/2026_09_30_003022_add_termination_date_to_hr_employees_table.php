<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->date('termination_date')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_employees')->whereNotNull('termination_date')->exists()) {
            throw new RuntimeException('Rollback refused because employee termination dates would be lost.');
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->dropColumn('termination_date');
        });
    }
};
