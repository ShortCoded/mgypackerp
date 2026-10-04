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
        foreach (['hr_payslip_items' => ['amount'], 'hr_salary_advances' => ['principal', 'balance']] as $tableName => $columns) {
            foreach ($columns as $column) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("ALTER TABLE {$tableName} ALTER COLUMN {$column} TYPE numeric(17, 4)");
                } else {
                    Schema::table($tableName, function (Blueprint $table) use ($column): void {
                        $table->decimal($column, 17, 4)->default(0)->change();
                    });
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Payroll source precision cannot be narrowed without a reviewed data migration.');
    }
};
