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
        if (! Schema::hasColumn('products', 'image_path')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('image_path')->nullable()->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('products', 'image_path')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('image_path');
            });
        }
    }
};
