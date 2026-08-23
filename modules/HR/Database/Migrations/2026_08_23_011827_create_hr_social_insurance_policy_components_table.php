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
        Schema::create('hr_social_insurance_policy_components', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('social_insurance_policy_id')->constrained('hr_social_insurance_policies')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('employee_rate', 7, 4)->default(0);
            $table->decimal('employer_rate', 7, 4)->default(0);
            $table->string('calculation_basis', 40)->default('contribution_wage');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(
                ['social_insurance_policy_id', 'sort_order'],
                'hr_insurance_components_policy_order_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_social_insurance_policy_components');
    }
};
