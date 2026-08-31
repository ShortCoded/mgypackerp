<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cost_centers') && ! Schema::hasColumn('cost_centers', 'name_en')) {
            Schema::table('cost_centers', function (Blueprint $table): void {
                $table->string('name_en')->nullable()->after('name');
            });
        }

        if (! Schema::hasTable('hr_department_cost_center_defaults')) {
            Schema::create('hr_department_cost_center_defaults', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('department_id')->constrained('hr_departments')->cascadeOnDelete();
                $table->foreignId('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'department_id'], 'hr_department_cc_defaults_company_department_unique');
                $table->index(['company_id', 'cost_center_id'], 'hr_department_cc_defaults_company_cc_index');
            });
        }

        $this->addNullableForeignId('journal_entry_lines', 'department_id', 'hr_departments');
        $this->addNullableForeignId('purchase_order_lines', 'cost_center_id', 'cost_centers');
        $this->addNullableForeignId('purchase_invoice_lines', 'cost_center_id', 'cost_centers');
        $this->addNullableForeignId('production_machines', 'cost_center_id', 'cost_centers');
        $this->addNullableForeignId('production_runs', 'cost_center_id', 'cost_centers');
    }

    public function down(): void
    {
        $this->dropNullableForeignId('production_runs', 'cost_center_id');
        $this->dropNullableForeignId('production_machines', 'cost_center_id');
        $this->dropNullableForeignId('purchase_invoice_lines', 'cost_center_id');
        $this->dropNullableForeignId('purchase_order_lines', 'cost_center_id');
        $this->dropNullableForeignId('journal_entry_lines', 'department_id');
        Schema::dropIfExists('hr_department_cost_center_defaults');

        if (Schema::hasTable('cost_centers') && Schema::hasColumn('cost_centers', 'name_en')) {
            Schema::table('cost_centers', function (Blueprint $table): void {
                $table->dropColumn('name_en');
            });
        }
    }

    private function addNullableForeignId(string $tableName, string $column, string $relatedTable): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $relatedTable): void {
            $table->foreignId($column)->nullable()->constrained($relatedTable)->nullOnDelete();
        });
    }

    private function dropNullableForeignId(string $tableName, string $column): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->dropConstrainedForeignId($column);
        });
    }
};
