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
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'default_company_id')) {
                $table->foreignId('default_company_id')->nullable()->constrained('companies')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'default_branch_id')) {
                $table->foreignId('default_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'default_financial_period_id')) {
                $table->foreignId('default_financial_period_id')->nullable()->constrained('financial_periods')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'default_financial_period_id')) {
                $table->dropConstrainedForeignId('default_financial_period_id');
            }

            if (Schema::hasColumn('users', 'default_branch_id')) {
                $table->dropConstrainedForeignId('default_branch_id');
            }

            if (Schema::hasColumn('users', 'default_company_id')) {
                $table->dropConstrainedForeignId('default_company_id');
            }
        });
    }
};
