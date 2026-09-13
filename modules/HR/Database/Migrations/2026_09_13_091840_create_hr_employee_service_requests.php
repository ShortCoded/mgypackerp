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
        Schema::create('hr_employee_service_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('request_type', 40)->index();
            $table->string('subject')->nullable();
            $table->text('details');
            $table->date('requested_from')->nullable()->index();
            $table->date('requested_to')->nullable()->index();
            $table->unsignedInteger('requested_minutes')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('submitted')->index();
            $table->timestamp('submitted_at')->useCurrent()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();
            $table->index(['employee_id', 'status', 'submitted_at']);
            $table->index(['company_id', 'status', 'submitted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_employee_service_requests');
    }
};
