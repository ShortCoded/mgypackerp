<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_payroll_items')) {
            Schema::table('hr_payroll_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('hr_payroll_items', 'account_classification_id')) {
                    $table->foreignId('account_classification_id')->nullable()->constrained('account_classifications')->nullOnDelete();
                }

                if (! Schema::hasColumn('hr_payroll_items', 'account_id')) {
                    $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('hr_payroll_cost_allocations')) {
            Schema::create('hr_payroll_cost_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payslip_item_id')->constrained('hr_payslip_items')->cascadeOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->foreignId('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
                $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
                $table->foreignId('account_classification_id')->constrained('account_classifications')->restrictOnDelete();
                $table->string('allocation_type', 30)->index();
                $table->decimal('percentage', 7, 4);
                $table->decimal('amount', 15, 2);
                $table->timestamps();

                $table->unique(
                    ['payslip_item_id', 'department_id', 'cost_center_id', 'account_id'],
                    'hr_payroll_cost_allocations_dimension_unique'
                );
                $table->index(['department_id', 'cost_center_id'], 'hr_payroll_cost_allocations_department_cc_index');
            });
        }

        if (Schema::hasTable('hr_payroll_postings') && ! Schema::hasColumn('hr_payroll_postings', 'journal_entry_id')) {
            Schema::table('hr_payroll_postings', function (Blueprint $table): void {
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_cost_allocations');

        if (Schema::hasTable('hr_payroll_postings') && Schema::hasColumn('hr_payroll_postings', 'journal_entry_id')) {
            Schema::table('hr_payroll_postings', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('journal_entry_id');
            });
        }

        if (Schema::hasTable('hr_payroll_items')) {
            Schema::table('hr_payroll_items', function (Blueprint $table): void {
                if (Schema::hasColumn('hr_payroll_items', 'account_id')) {
                    $table->dropConstrainedForeignId('account_id');
                }

                if (Schema::hasColumn('hr_payroll_items', 'account_classification_id')) {
                    $table->dropConstrainedForeignId('account_classification_id');
                }
            });
        }
    }
};
