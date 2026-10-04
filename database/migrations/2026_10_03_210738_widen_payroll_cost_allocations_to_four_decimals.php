<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE hr_payroll_cost_allocations ALTER COLUMN amount TYPE numeric(17, 4)');

            return;
        }
        Schema::table('hr_payroll_cost_allocations', function (Blueprint $table): void {
            $table->decimal('amount', 17, 4)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Payroll allocation precision cannot be narrowed without a reviewed data migration.');
    }
};
