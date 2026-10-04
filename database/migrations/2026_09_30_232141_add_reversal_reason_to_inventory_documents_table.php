<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->text('reversal_reason')->nullable()->after('reversed_at');
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_documents')->whereNotNull('reversal_reason')->exists()) {
            throw new RuntimeException('Rollback refused because recorded inventory reversal reasons would be lost.');
        }

        Schema::table('inventory_documents', function (Blueprint $table) {
            $table->dropColumn('reversal_reason');
        });
    }
};
