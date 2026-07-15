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
        if (! Schema::hasTable('companies') || Schema::hasColumn('companies', 'hotline')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('hotline', 50)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'hotline')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('hotline');
        });
    }
};
