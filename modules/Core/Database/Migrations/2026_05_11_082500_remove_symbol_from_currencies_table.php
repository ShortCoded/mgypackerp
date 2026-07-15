<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies') || ! Schema::hasColumn('currencies', 'symbol')) {
            return;
        }

        Schema::table('currencies', function (Blueprint $table): void {
            $table->dropColumn('symbol');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('currencies') || Schema::hasColumn('currencies', 'symbol')) {
            return;
        }

        Schema::table('currencies', function (Blueprint $table): void {
            $table->string('symbol', 20)->nullable()->after('code');
        });
    }
};
