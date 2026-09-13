<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_stages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('output_type', 80)->nullable();
            $table->decimal('standard_duration_value', 12, 4)->nullable();
            $table->string('standard_duration_unit', 10)->nullable();
            $table->unsignedInteger('display_order')->default(1);
            $table->string('status', 20)->default('active')->index();
            $this->auditColumns($table);
            $table->index(['company_id', 'status', 'display_order'], 'production_stages_context_order_index');
        });

        Schema::create('product_production_stages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('production_stage_id')->constrained('production_stages')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('standard_duration_value', 12, 4)->nullable();
            $table->string('standard_duration_unit', 10)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active')->index();
            $this->auditColumns($table);
            $table->index(['company_id', 'product_id', 'status', 'sequence'], 'product_production_stages_route_index');
        });

        Schema::create('production_order_stage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('production_order_line_id')->constrained('production_order_lines')->cascadeOnDelete();
            $table->foreignId('production_stage_id')->nullable()->constrained('production_stages')->nullOnDelete();
            $table->foreignId('product_production_stage_id')->nullable()->constrained('product_production_stages')->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('stage_code', 80);
            $table->string('stage_name');
            $table->text('description')->nullable();
            $table->string('output_type', 80)->nullable();
            $table->decimal('standard_duration_value', 12, 4)->nullable();
            $table->string('standard_duration_unit', 10)->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['production_order_line_id', 'sequence'], 'production_order_stage_snapshots_line_sequence_unique');
            $table->index(['production_order_id', 'status'], 'production_order_stage_snapshots_order_status_index');
        });

        Schema::table('production_orders', function (Blueprint $table): void {
            $table->unique(['company_id', 'sales_order_id'], 'production_orders_sales_order_unique');
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->foreignId('production_order_stage_snapshot_id')->nullable()->after('production_order_line_id')->constrained('production_order_stage_snapshots')->restrictOnDelete();
            $table->foreignId('fixed_asset_id')->nullable()->after('production_mold_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->text('work_description')->nullable()->after('batch_lot');
            $table->unsignedInteger('planned_labor_count')->nullable()->after('work_description');
            $table->unsignedInteger('actual_labor_count')->nullable()->after('planned_labor_count');
            $table->json('labor_details')->nullable()->after('actual_labor_count');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->softDeletes()->index();
            $table->index(['fixed_asset_id', 'planned_start_at', 'planned_end_at'], 'production_runs_fixed_asset_schedule_index');
            $table->index(['production_order_stage_snapshot_id', 'status'], 'production_runs_stage_status_index');
        });

        Schema::create('production_material_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $this->productionContext($table);
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('production_run_id')->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained('purchase_requisitions')->nullOnDelete();
            $table->date('request_date')->index();
            $table->date('required_by_date')->nullable();
            $table->string('request_type', 20)->default('planned')->index();
            $table->string('status', 30)->default('draft')->index();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $this->workflowApprovalColumns($table);
            $this->auditColumns($table);
            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'production_material_requests_context_number_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'status'], 'production_material_requests_context_status_index');
            $table->index(['production_run_id', 'status'], 'production_material_requests_run_status_index');
        });

        Schema::create('production_material_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('production_material_request_id')->constrained('production_material_requests')->cascadeOnDelete();
            $table->foreignId('production_material_requirement_id')->constrained('production_material_requirements')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->decimal('planned_quantity', 20, 8);
            $table->decimal('requested_quantity', 20, 8);
            $table->decimal('approved_quantity', 20, 8)->default(0);
            $table->decimal('reserved_quantity', 20, 8)->default(0);
            $table->decimal('issued_quantity', 20, 8)->default(0);
            $table->decimal('shortage_quantity', 20, 8)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['production_material_request_id', 'line_number'], 'production_material_request_lines_request_line_unique');
            $table->unique(['production_material_request_id', 'production_material_requirement_id'], 'production_material_request_lines_requirement_unique');
        });

        Schema::create('production_expense_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $this->productionContext($table);
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('production_run_id')->constrained('production_runs')->restrictOnDelete();
            $table->date('request_date')->index();
            $table->decimal('amount', 20, 4);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('payment_channel', 20)->default('cashbox');
            $table->foreignId('cashbox_id')->nullable()->constrained('cashboxes')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cash_voucher_id')->nullable()->constrained('cash_vouchers')->nullOnDelete();
            $table->foreignId('reversal_cash_voucher_id')->nullable()->constrained('cash_vouchers')->nullOnDelete();
            $table->text('reason');
            $table->string('status', 30)->default('draft')->index();
            $table->text('notes')->nullable();
            $this->workflowApprovalColumns($table);
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $this->auditColumns($table);
            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'production_expense_requests_context_number_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'status'], 'production_expense_requests_context_status_index');
        });

        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->string('disposition', 30)->nullable()->after('result')->index();
            $table->timestamp('reviewed_at')->nullable()->after('sampled_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable()->after('corrective_action');
            $table->foreignId('released_by')->nullable()->after('released_at')->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
        });

        Schema::create('maintenance_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $this->productionContext($table);
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('production_run_id')->nullable()->constrained('production_runs')->nullOnDelete();
            $table->dateTime('reported_at')->index();
            $table->string('request_type', 30)->default('breakdown')->index();
            $table->string('discipline', 30)->nullable()->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->text('symptoms');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $this->auditColumns($table);
            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'maintenance_requests_context_number_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'status'], 'maintenance_requests_context_status_index');
        });

        Schema::create('maintenance_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $this->productionContext($table);
            $table->foreignId('maintenance_request_id')->nullable()->constrained('maintenance_requests')->nullOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('production_run_id')->nullable()->constrained('production_runs')->nullOnDelete();
            $table->string('maintenance_type', 30)->index();
            $table->string('discipline', 30)->nullable()->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('service_mode', 20)->default('internal')->index();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('external_provider_name')->nullable();
            $table->string('external_provider_contact')->nullable();
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->text('work_description');
            $table->text('diagnosis')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('work_performed')->nullable();
            $table->text('completion_notes')->nullable();
            $table->decimal('external_cost', 20, 4)->default(0);
            $table->date('next_due_date')->nullable()->index();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $this->auditColumns($table);
            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'maintenance_work_orders_context_number_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'status'], 'maintenance_work_orders_context_status_index');
            $table->index(['fixed_asset_id', 'status'], 'maintenance_work_orders_asset_status_index');
        });

        $this->createActiveUniqueIndexes();
    }

    public function down(): void
    {
        $this->dropActiveUniqueIndexes();
        Schema::dropIfExists('maintenance_work_orders');
        Schema::dropIfExists('maintenance_requests');
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('restored_by');
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropConstrainedForeignId('released_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['restored_at', 'released_at', 'reviewed_at', 'disposition']);
        });
        Schema::dropIfExists('production_expense_requests');
        Schema::dropIfExists('production_material_request_lines');
        Schema::dropIfExists('production_material_requests');
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropIndex('production_runs_stage_status_index');
            $table->dropIndex('production_runs_fixed_asset_schedule_index');
            $table->dropConstrainedForeignId('restored_by');
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropConstrainedForeignId('fixed_asset_id');
            $table->dropConstrainedForeignId('production_order_stage_snapshot_id');
            $table->dropColumn(['restored_at', 'deleted_at', 'labor_details', 'actual_labor_count', 'planned_labor_count', 'work_description']);
        });
        Schema::table('production_orders', fn (Blueprint $table) => $table->dropUnique('production_orders_sales_order_unique'));
        Schema::dropIfExists('production_order_stage_snapshots');
        Schema::dropIfExists('product_production_stages');
        Schema::dropIfExists('production_stages');
    }

    private function productionContext(Blueprint $table): void
    {
        $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
        $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
        $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
    }

    private function workflowApprovalColumns(Blueprint $table): void
    {
        $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('submitted_at')->nullable();
        $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('approved_at')->nullable();
        $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('rejected_at')->nullable();
        $table->text('rejection_reason')->nullable();
    }

    private function auditColumns(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('restored_at')->nullable();
        $table->timestamps();
        $table->softDeletes()->index();
    }

    private function createActiveUniqueIndexes(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $deletedAt = $grammar->wrap('deleted_at');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('production_stages_company_code_unique_active').' ON '.$grammar->wrapTable('production_stages').' ('.$grammar->wrap('company_id').', '.$grammar->wrap('code').') WHERE '.$deletedAt.' IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('product_production_stages_product_stage_unique_active').' ON '.$grammar->wrapTable('product_production_stages').' ('.$grammar->wrap('product_id').', '.$grammar->wrap('production_stage_id').') WHERE '.$deletedAt.' IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('product_production_stages_product_sequence_unique_active').' ON '.$grammar->wrapTable('product_production_stages').' ('.$grammar->wrap('product_id').', '.$grammar->wrap('sequence').') WHERE '.$deletedAt.' IS NULL');
    }

    private function dropActiveUniqueIndexes(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        foreach (['production_stages_company_code_unique_active', 'product_production_stages_product_stage_unique_active', 'product_production_stages_product_sequence_unique_active'] as $index) {
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
        }
    }
};
