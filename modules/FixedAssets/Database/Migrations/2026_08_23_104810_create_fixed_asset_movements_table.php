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
        Schema::create('fixed_asset_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num');
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->date('movement_date');
            $table->foreignId('source_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('source_branch_hall_id')->nullable()->constrained('branch_halls')->nullOnDelete();
            $table->foreignId('destination_branch_hall_id')->nullable()->constrained('branch_halls')->nullOnDelete();
            $table->text('source_location_address')->nullable();
            $table->text('destination_location_address')->nullable();
            $table->foreignId('source_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('destination_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->string('status')->default('posted');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'doc_number']);
            $table->unique(['company_id', 'doc_num']);
            $table->index(['fixed_asset_id', 'movement_date']);
            $table->index(['company_id', 'destination_branch_id', 'movement_date'], 'fixed_asset_movements_destination_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_movements');
    }
};
