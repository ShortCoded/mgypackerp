<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('branch_stores', 'classification')) {
            Schema::table('branch_stores', function (Blueprint $table): void {
                $table->string('classification', 50)->default('general')->after('name');
            });
        }

        if (! Schema::hasIndex('branch_stores', 'branch_stores_classification_index')) {
            Schema::table('branch_stores', function (Blueprint $table): void {
                $table->index('classification');
            });
        }

        DB::table('branch_stores')
            ->whereNull('classification')
            ->orWhere('classification', '')
            ->update(['classification' => 'general']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('branch_stores', 'classification')) {
            return;
        }

        if (Schema::hasIndex('branch_stores', 'branch_stores_classification_index')) {
            Schema::table('branch_stores', function (Blueprint $table): void {
                $table->dropIndex('branch_stores_classification_index');
            });
        }

        Schema::table('branch_stores', function (Blueprint $table): void {
            $table->dropColumn('classification');
        });
    }
};
