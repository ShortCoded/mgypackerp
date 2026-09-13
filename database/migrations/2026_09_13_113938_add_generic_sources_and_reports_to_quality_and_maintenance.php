<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->foreignId('production_order_id')->nullable()->change();
            $table->string('subject_type', 30)->default('production_run')->after('branch_id')->index();
            $table->foreignId('product_id')->nullable()->after('production_run_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('branch_store_id')->nullable()->after('product_id')->constrained('branch_stores')->restrictOnDelete();
            $table->string('stock_status', 30)->nullable()->after('branch_store_id');
            $table->string('batch_lot', 120)->nullable()->after('stock_status');
            $table->string('source_reference')->nullable()->after('batch_lot');
            $table->index(['company_id', 'branch_id', 'subject_type', 'status'], 'quality_inspections_subject_status_index');
        });

        Schema::create('quality_inspection_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('quality_inspection_id')->constrained('quality_inspections')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->timestamp('reported_at')->index();
            $table->string('result', 30)->default('pending')->index();
            $table->string('disposition', 30)->nullable();
            $table->string('defect_code', 100)->nullable();
            $table->decimal('affected_base_quantity', 20, 8)->nullable();
            $table->text('observations');
            $table->text('corrective_action')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 30)->default('submitted')->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
            $table->unique(['quality_inspection_id', 'sequence'], 'quality_inspection_reports_inspection_sequence_unique');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->foreignId('production_mold_id')->nullable()->after('fixed_asset_id')->constrained('production_molds')->restrictOnDelete();
        });

        Schema::create('maintenance_material_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('maintenance_work_order_id')->constrained('maintenance_work_orders')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->date('request_date')->index();
            $table->string('status', 30)->default('submitted')->index();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('inventory_issue_document_id')->nullable()->constrained('inventory_documents')->nullOnDelete();
            $table->foreignId('inventory_return_document_id')->nullable()->constrained('inventory_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'maintenance_material_requests_context_number_unique');
            $table->index(['maintenance_work_order_id', 'status'], 'maintenance_material_requests_order_status_index');
        });

        Schema::create('maintenance_material_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_material_request_id')->constrained('maintenance_material_requests')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->string('item_type', 30)->default('spare_part')->index();
            $table->decimal('requested_quantity', 20, 8);
            $table->decimal('approved_quantity', 20, 8)->default(0);
            $table->decimal('issued_quantity', 20, 8)->default(0);
            $table->decimal('returned_quantity', 20, 8)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['maintenance_material_request_id', 'line_number'], 'maintenance_material_request_lines_request_line_unique');
        });

        Schema::table('production_expense_requests', function (Blueprint $table): void {
            $table->foreignId('production_order_id')->nullable()->change();
            $table->foreignId('production_run_id')->nullable()->change();
            $table->foreignId('maintenance_work_order_id')->nullable()->after('production_run_id')->constrained('maintenance_work_orders')->restrictOnDelete();
            $table->index(['maintenance_work_order_id', 'status'], 'production_expense_requests_maintenance_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('production_expense_requests', function (Blueprint $table): void {
            $table->dropIndex('production_expense_requests_maintenance_status_index');
            $table->dropConstrainedForeignId('maintenance_work_order_id');
        });
        Schema::dropIfExists('maintenance_material_request_lines');
        Schema::dropIfExists('maintenance_material_requests');
        Schema::table('maintenance_work_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('production_mold_id'));
        Schema::dropIfExists('quality_inspection_reports');
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->dropIndex('quality_inspections_subject_status_index');
            $table->dropConstrainedForeignId('branch_store_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['subject_type', 'stock_status', 'batch_lot', 'source_reference']);
        });
    }
};
