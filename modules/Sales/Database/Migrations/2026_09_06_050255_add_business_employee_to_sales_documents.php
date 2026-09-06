<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_requests', 'quotations', 'sales_orders'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('business_employee_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_requests', 'quotations', 'sales_orders'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('business_employee_id');
            });
        }
    }
};
