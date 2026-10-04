<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employment_tax_policies', function (Blueprint $table): void {
            $table->string('taxable_basis', 40)->nullable();
            $table->string('annualization_method', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employment_tax_policies', function (Blueprint $table): void {
            $table->dropColumn(['taxable_basis', 'annualization_method']);
        });
    }
};
