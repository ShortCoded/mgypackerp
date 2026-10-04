<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $inconsistent = DB::table('sales_requests as request')
            ->join('sales_request_lines as line', 'line.sales_request_id', '=', 'request.id')
            ->where('request.status', 'converted')
            ->whereNull('request.deleted_at')
            ->whereColumn('line.converted_quantity', '<', 'line.quantity')
            ->exists();

        if ($inconsistent) {
            throw new RuntimeException('A converted sales request still has unconverted lines; closure backfill requires reconciliation.');
        }

        DB::table('sales_requests')
            ->where('status', 'converted')
            ->whereNull('closed_at')
            ->update([
                'closed_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
                'closed_by' => DB::raw('COALESCE(updated_by, approved_by)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Closure timestamps are audit history and remain on rollback.
    }
};
