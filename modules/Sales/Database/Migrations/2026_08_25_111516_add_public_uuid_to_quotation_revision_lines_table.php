<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('quotation_revision_lines', 'public_uuid')) {
            return;
        }

        Schema::table('quotation_revision_lines', function (Blueprint $table): void {
            $table->uuid('public_uuid')->nullable()->unique()->after('id');
        });

        DB::table('quotation_revision_lines')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($lines): void {
                foreach ($lines as $line) {
                    DB::table('quotation_revision_lines')
                        ->where('id', $line->id)
                        ->update(['public_uuid' => (string) Str::uuid()]);
                }
            });

        Schema::table('quotation_revision_lines', function (Blueprint $table): void {
            $table->uuid('public_uuid')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('quotation_revision_lines', 'public_uuid')) {
            return;
        }

        Schema::table('quotation_revision_lines', function (Blueprint $table): void {
            $table->dropColumn('public_uuid');
        });
    }
};
