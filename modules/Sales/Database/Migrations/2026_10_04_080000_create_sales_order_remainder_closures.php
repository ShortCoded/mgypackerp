<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->decimal('declined_quantity', 20, 8)->default(0)->after('delivered_quantity');
            $table->decimal('declined_base_quantity', 20, 8)->default(0)->after('delivered_base_quantity');
            $table->decimal('remainder_credited_quantity', 20, 8)->default(0)->after('invoiced_quantity');
            $table->decimal('remainder_credited_base_quantity', 20, 8)->default(0)->after('invoiced_base_quantity');
        });

        Schema::create('sales_order_remainder_closures', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->date('closure_date');
            $table->text('reason');
            $table->string('status', 24)->default('applying');
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->json('before_snapshot');
            $table->json('effect_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'idempotency_key'], 'sales_order_remainder_closures_idempotency_unique');
            $table->index(['company_id', 'branch_id', 'sales_order_id'], 'sales_order_remainder_closures_lookup');
        });

        Schema::create('sales_order_remainder_closure_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_remainder_closure_id')->constrained('sales_order_remainder_closures')->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
            $table->decimal('declined_quantity', 20, 8);
            $table->decimal('declined_base_quantity', 20, 8);
            $table->decimal('delivered_quantity_snapshot', 20, 8);
            $table->decimal('delivered_base_quantity_snapshot', 20, 8);
            $table->decimal('released_reservation_quantity', 20, 8)->default(0);
            $table->decimal('released_reservation_base_quantity', 20, 8)->default(0);
            $table->decimal('released_production_quantity', 20, 8)->default(0);
            $table->decimal('released_production_base_quantity', 20, 8)->default(0);
            $table->decimal('credited_remainder_quantity', 20, 8)->default(0);
            $table->decimal('credited_remainder_base_quantity', 20, 8)->default(0);
            $table->timestamps();

            $table->unique(['sales_order_remainder_closure_id', 'sales_order_line_id'], 'sales_order_remainder_closure_lines_unique');
        });

        Schema::table('sales_issue_orders', function (Blueprint $table): void {
            $table->text('short_close_reason')->nullable()->after('status');
            $table->foreignId('short_closed_by')->nullable()->after('issued_at')->constrained('users')->nullOnDelete();
            $table->timestamp('short_closed_at')->nullable()->after('short_closed_by');
        });
    }

    public function down(): void
    {
        $hasClosureHistory = Schema::hasTable('sales_order_remainder_closures')
            && DB::table('sales_order_remainder_closures')->exists();
        $hasLineEffects = Schema::hasColumn('sales_order_lines', 'declined_quantity')
            && DB::table('sales_order_lines')->where(function ($lines): void {
                $lines->where('declined_quantity', '<>', 0)
                    ->orWhere('declined_base_quantity', '<>', 0)
                    ->orWhere('remainder_credited_quantity', '<>', 0)
                    ->orWhere('remainder_credited_base_quantity', '<>', 0);
            })->exists();
        $hasShortClosedIssues = Schema::hasColumn('sales_issue_orders', 'short_closed_at')
            && DB::table('sales_issue_orders')->where(function ($issues): void {
                $issues->where('status', 'short_closed')
                    ->orWhereNotNull('short_close_reason')
                    ->orWhereNotNull('short_closed_by')
                    ->orWhereNotNull('short_closed_at');
            })->exists();
        if ($hasClosureHistory || $hasLineEffects || $hasShortClosedIssues) {
            throw new RuntimeException('Applied sales-order remainder closures contain business records; this migration cannot be rolled back.');
        }

        Schema::table('sales_issue_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('short_closed_by');
            $table->dropColumn(['short_close_reason', 'short_closed_at']);
        });
        Schema::dropIfExists('sales_order_remainder_closure_lines');
        Schema::dropIfExists('sales_order_remainder_closures');

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'declined_quantity', 'declined_base_quantity',
                'remainder_credited_quantity', 'remainder_credited_base_quantity',
            ]);
        });
    }
};
