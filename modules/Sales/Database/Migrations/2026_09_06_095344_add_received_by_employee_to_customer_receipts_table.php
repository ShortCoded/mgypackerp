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
        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->foreignId('received_by_employee_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('hr_employees')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_by_employee_id');
        });
    }
};
