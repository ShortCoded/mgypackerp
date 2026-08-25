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
        if (! Schema::hasColumn('quotation_payment_milestones', 'due_type')) {
            Schema::table('quotation_payment_milestones', function (Blueprint $table): void {
                $table->string('due_type')->nullable()->index()->after('amount');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('quotation_payment_milestones', 'due_type')) {
            Schema::table('quotation_payment_milestones', function (Blueprint $table): void {
                $table->dropColumn('due_type');
            });
        }
    }
};
