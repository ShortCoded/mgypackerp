<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        Schema::table($rolesTable, function (Blueprint $table) use ($rolesTable): void {
            if (! Schema::hasColumn($rolesTable, 'company_access_restricted')) {
                $table->boolean('company_access_restricted')
                    ->default(false)
                    ->index()
                    ->after('notes');
            }

            if (! Schema::hasColumn($rolesTable, 'branch_access_restricted')) {
                $table->boolean('branch_access_restricted')
                    ->default(false)
                    ->index()
                    ->after('company_access_restricted');
            }

            if (! Schema::hasColumn($rolesTable, 'financial_period_access_restricted')) {
                $table->boolean('financial_period_access_restricted')
                    ->default(false)
                    ->index()
                    ->after('branch_access_restricted');
            }
        });

        if (! Schema::hasTable('role_company_access')) {
            Schema::create('role_company_access', function (Blueprint $table) use ($rolesTable): void {
                $table->id();
                $table->foreignId('role_id')->constrained($rolesTable)->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['role_id', 'company_id'], 'role_company_access_unique');
                $table->index('company_id', 'role_company_access_company_id_index');
            });
        }

        if (! Schema::hasTable('role_branch_access')) {
            Schema::create('role_branch_access', function (Blueprint $table) use ($rolesTable): void {
                $table->id();
                $table->foreignId('role_id')->constrained($rolesTable)->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['role_id', 'branch_id'], 'role_branch_access_unique');
                $table->index('branch_id', 'role_branch_access_branch_id_index');
            });
        }

        if (! Schema::hasTable('role_financial_period_access')) {
            Schema::create('role_financial_period_access', function (Blueprint $table) use ($rolesTable): void {
                $table->id();
                $table->foreignId('role_id')->constrained($rolesTable)->cascadeOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['role_id', 'financial_period_id'], 'role_financial_period_access_unique');
                $table->index('financial_period_id', 'role_financial_period_access_period_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_financial_period_access');
        Schema::dropIfExists('role_branch_access');
        Schema::dropIfExists('role_company_access');

        $rolesTable = config('permission.table_names.roles', 'roles');

        Schema::table($rolesTable, function (Blueprint $table) use ($rolesTable): void {
            if (Schema::hasColumn($rolesTable, 'financial_period_access_restricted')) {
                $table->dropColumn('financial_period_access_restricted');
            }

            if (Schema::hasColumn($rolesTable, 'branch_access_restricted')) {
                $table->dropColumn('branch_access_restricted');
            }

            if (Schema::hasColumn($rolesTable, 'company_access_restricted')) {
                $table->dropColumn('company_access_restricted');
            }
        });
    }
};
