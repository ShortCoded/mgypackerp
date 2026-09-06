<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->foreignId('requester_employee_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requester_employee_id');
        });
    }
};
