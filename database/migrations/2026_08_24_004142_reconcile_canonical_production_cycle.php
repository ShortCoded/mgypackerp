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
        $this->ensureQualityFoundation();

        Schema::table('production_orders', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->change();
            $table->date('expected_delivery_date')->nullable()->change();
            $table->string('source_type', 30)->default('sales_order')->after('branch_id')->index();
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('priority', 20)->default('normal')->after('expected_delivery_date');
            $table->decimal('overproduction_tolerance_percent', 8, 4)->default(0)->after('priority');
            $table->text('short_close_reason')->nullable()->after('cancel_reason');
            $table->foreignId('short_closed_by')->nullable()->after('short_close_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('short_closed_at')->nullable()->after('short_closed_by');
            $table->index(['company_id', 'source_type', 'source_id'], 'production_orders_source_index');
        });

        Schema::table('production_order_lines', function (Blueprint $table): void {
            $table->foreignId('sales_order_line_id')->nullable()->change();
            $table->json('bom_snapshot')->nullable()->after('specifications');
            $table->decimal('received_base_quantity', 20, 8)->default(0)->after('base_quantity');
        });

        Schema::create('production_machines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_hall_id')->nullable()->constrained('branch_halls')->nullOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->string('status', 30)->default('available')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'production_machines_company_code_unique');
            $table->index(['company_id', 'branch_id', 'status'], 'production_machines_availability_index');
        });

        Schema::create('production_molds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_hall_id')->nullable()->constrained('branch_halls')->nullOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->string('status', 30)->default('available')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'production_molds_company_code_unique');
            $table->index(['company_id', 'branch_id', 'status'], 'production_molds_availability_index');
        });

        Schema::create('production_machine_mold', function (Blueprint $table): void {
            $table->foreignId('production_machine_id')->constrained('production_machines')->cascadeOnDelete();
            $table->foreignId('production_mold_id')->constrained('production_molds')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['production_machine_id', 'production_mold_id']);
        });

        Schema::create('production_mold_product', function (Blueprint $table): void {
            $table->foreignId('production_mold_id')->constrained('production_molds')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['production_mold_id', 'product_id']);
        });

        Schema::create('production_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'code'], 'production_shifts_context_code_unique');
        });

        Schema::create('production_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('run_number', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('production_order_line_id')->constrained('production_order_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->decimal('conversion_factor', 20, 8)->default(1);
            $table->decimal('planned_quantity', 20, 8);
            $table->decimal('planned_base_quantity', 20, 8);
            $table->decimal('good_base_quantity', 20, 8)->default(0);
            $table->decimal('rejected_base_quantity', 20, 8)->default(0);
            $table->decimal('rework_base_quantity', 20, 8)->default(0);
            $table->decimal('scrap_base_quantity', 20, 8)->default(0);
            $table->decimal('received_base_quantity', 20, 8)->default(0);
            $table->timestamp('planned_start_at');
            $table->timestamp('planned_end_at');
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->foreignId('production_shift_id')->nullable()->constrained('production_shifts')->restrictOnDelete();
            $table->foreignId('production_machine_id')->nullable()->constrained('production_machines')->restrictOnDelete();
            $table->foreignId('production_mold_id')->nullable()->constrained('production_molds')->restrictOnDelete();
            $table->string('batch_lot', 100)->nullable()->index();
            $table->string('status', 30)->default('planned')->index();
            $table->string('setup_status', 30)->default('pending');
            $table->timestamp('setup_started_at')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'run_number']);
            $table->index(['production_order_id', 'status'], 'production_runs_order_status_index');
            $table->index(['production_machine_id', 'planned_start_at', 'planned_end_at'], 'production_runs_machine_schedule_index');
            $table->index(['production_mold_id', 'planned_start_at', 'planned_end_at'], 'production_runs_mold_schedule_index');
        });

        Schema::create('production_material_requirements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('production_order_line_id')->constrained('production_order_lines')->cascadeOnDelete();
            $table->foreignId('production_run_id')->constrained('production_runs')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_component_id')->nullable()->constrained('product_components')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->string('calculation_method', 20);
            $table->decimal('component_quantity_snapshot', 20, 8);
            $table->decimal('planned_quantity', 20, 8);
            $table->decimal('reserved_quantity', 20, 8)->default(0);
            $table->decimal('issued_quantity', 20, 8)->default(0);
            $table->decimal('additional_issued_quantity', 20, 8)->default(0);
            $table->decimal('returned_quantity', 20, 8)->default(0);
            $table->decimal('consumed_quantity', 20, 8)->default(0);
            $table->decimal('waste_quantity', 20, 8)->default(0);
            $table->json('component_snapshot');
            $table->timestamps();

            $table->unique(['production_run_id', 'line_number'], 'production_material_requirements_run_line_unique');
            $table->index(['production_run_id', 'product_id'], 'production_material_requirements_product_index');
        });

        Schema::create('production_progress_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('production_run_id')->constrained('production_runs')->cascadeOnDelete();
            $table->timestamp('recorded_at')->index();
            $table->decimal('good_base_quantity', 20, 8)->default(0);
            $table->decimal('rejected_base_quantity', 20, 8)->default(0);
            $table->decimal('rework_base_quantity', 20, 8)->default(0);
            $table->decimal('scrap_base_quantity', 20, 8)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->foreignId('production_run_id')->nullable()->after('production_order_id')->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('production_material_requirement_id')->nullable()->after('production_run_id')->constrained('production_material_requirements')->restrictOnDelete();
            $table->index(['production_run_id', 'status'], 'inventory_reservations_run_status_index');
        });

        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->foreignId('production_run_id')->nullable()->after('production_order_id')->constrained('production_runs')->restrictOnDelete();
            $table->index(['production_run_id', 'document_type', 'status'], 'inventory_documents_run_type_index');
        });

        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->foreignId('production_run_id')->nullable()->after('production_order_id')->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('inventory_reservation_id')->nullable()->after('production_run_id')->constrained('inventory_reservations')->restrictOnDelete();
        });

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->foreignId('production_run_id')->nullable()->after('production_order_id')->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('inventory_reservation_id')->nullable()->after('production_run_id')->constrained('inventory_reservations')->restrictOnDelete();
            $table->index(['production_run_id', 'transaction_type'], 'inventory_transactions_run_type_index');
        });

        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->unsignedBigInteger('production_order_stage_id')->nullable()->change();
            $table->foreignId('quality_inspection_type_id')->nullable()->change();
            $table->foreignId('production_run_id')->nullable()->after('production_order_id')->constrained('production_runs')->restrictOnDelete();
            $table->timestamp('sampled_at')->nullable()->after('inspection_date')->index();
            $table->string('defect_code', 100)->nullable()->after('result');
            $table->decimal('affected_base_quantity', 20, 8)->nullable()->after('defect_code');
            $table->text('corrective_action')->nullable()->after('rework_notes');
            $table->json('evidence')->nullable()->after('corrective_action');
            $table->index(['production_run_id', 'sampled_at'], 'quality_inspections_run_sample_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->dropIndex('quality_inspections_run_sample_index');
            $table->dropConstrainedForeignId('production_run_id');
            $table->dropColumn(['sampled_at', 'defect_code', 'affected_base_quantity', 'corrective_action', 'evidence']);
        });

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->dropIndex('inventory_transactions_run_type_index');
            $table->dropConstrainedForeignId('inventory_reservation_id');
            $table->dropConstrainedForeignId('production_run_id');
        });

        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_reservation_id');
            $table->dropConstrainedForeignId('production_run_id');
        });

        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropIndex('inventory_documents_run_type_index');
            $table->dropConstrainedForeignId('production_run_id');
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inventory_reservations_run_status_index');
            $table->dropConstrainedForeignId('production_material_requirement_id');
            $table->dropConstrainedForeignId('production_run_id');
        });

        Schema::dropIfExists('production_progress_entries');
        Schema::dropIfExists('production_material_requirements');
        Schema::dropIfExists('production_runs');
        Schema::dropIfExists('production_shifts');
        Schema::dropIfExists('production_mold_product');
        Schema::dropIfExists('production_machine_mold');
        Schema::dropIfExists('production_molds');
        Schema::dropIfExists('production_machines');

        Schema::table('production_order_lines', function (Blueprint $table): void {
            $table->dropColumn(['bom_snapshot', 'received_base_quantity']);
            $table->foreignId('sales_order_line_id')->nullable(false)->change();
        });

        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropIndex('production_orders_source_index');
            $table->dropConstrainedForeignId('short_closed_by');
            $table->dropColumn(['source_type', 'source_id', 'priority', 'overproduction_tolerance_percent', 'short_close_reason', 'short_closed_at']);
            $table->foreignId('sales_order_id')->nullable(false)->change();
            $table->foreignId('customer_id')->nullable(false)->change();
            $table->date('expected_delivery_date')->nullable(false)->change();
        });
    }

    private function ensureQualityFoundation(): void
    {
        if (! Schema::hasTable('quality_inspection_types')) {
            Schema::create('quality_inspection_types', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('code', 100);
                $table->string('name');
                $table->string('name_ar')->nullable();
                $table->boolean('is_final_production')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'code']);
            });
        }

        if (! Schema::hasTable('quality_checkpoints')) {
            Schema::create('quality_checkpoints', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('quality_inspection_type_id')->constrained('quality_inspection_types')->cascadeOnDelete();
                $table->string('code', 100);
                $table->string('name');
                $table->string('name_ar')->nullable();
                $table->unsignedInteger('sequence')->default(10);
                $table->string('response_type', 30)->default('pass_fail');
                $table->text('acceptance_criteria')->nullable();
                $table->decimal('minimum_value', 20, 8)->nullable();
                $table->decimal('maximum_value', 20, 8)->nullable();
                $table->string('measurement_unit', 100)->nullable();
                $table->boolean('is_required')->default(true);
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['quality_inspection_type_id', 'code']);
                $table->index(['quality_inspection_type_id', 'is_active', 'sequence']);
            });
        }

        if (! Schema::hasTable('quality_inspections')) {
            Schema::create('quality_inspections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('doc_number');
                $table->string('doc_num', 100);
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
                $table->unsignedBigInteger('production_order_stage_id')->nullable();
                $table->foreignId('quality_inspection_type_id')->nullable()->constrained('quality_inspection_types')->restrictOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->date('inspection_date')->index();
                $table->string('status', 30)->default('draft')->index();
                $table->string('result', 30)->default('pending')->index();
                $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('rework_notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'doc_num']);
                $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'quality_inspections_context_number_unique');
                $table->index(['company_id', 'financial_period_id', 'branch_id', 'status'], 'quality_inspections_context_status_index');
            });
        }

        if (! Schema::hasTable('quality_inspection_results')) {
            Schema::create('quality_inspection_results', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quality_inspection_id')->constrained('quality_inspections')->cascadeOnDelete();
                $table->foreignId('quality_checkpoint_id')->constrained('quality_checkpoints')->restrictOnDelete();
                $table->unsignedInteger('sequence');
                $table->string('result', 30)->default('pending')->index();
                $table->string('measured_value')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamps();
                $table->unique(['quality_inspection_id', 'quality_checkpoint_id'], 'quality_inspection_results_inspection_checkpoint_unique');
            });
        }
    }
};
