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
        Schema::table('companies', function (Blueprint $table) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->boolean('show_company_identity_on_prints')->default(false);
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn('show_company_identity_on_prints');
            });
        });
    }
};
