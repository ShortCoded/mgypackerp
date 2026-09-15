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
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->timestamp('paused_at')->nullable()->after('actual_start_at')->index();
            $table->text('current_pause_reason')->nullable()->after('paused_at');
            $table->unsignedInteger('total_paused_minutes')->default(0)->after('current_pause_reason');
            $table->boolean('external_in_transit')->default(false)->after('external_provider_contact')->index();
            $table->timestamp('external_dispatched_at')->nullable()->after('external_in_transit');
            $table->timestamp('external_received_at')->nullable()->after('external_dispatched_at');
        });

        Schema::create('maintenance_work_order_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('maintenance_work_order_id')->constrained('maintenance_work_orders')->restrictOnDelete();
            $table->string('event_type', 40)->index();
            $table->timestamp('occurred_at')->index();
            $table->text('reason')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'occurred_at'], 'maintenance_events_context_time_index');
            $table->index(['maintenance_work_order_id', 'occurred_at'], 'maintenance_events_order_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_work_order_events');

        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropIndex(['paused_at']);
            $table->dropIndex(['external_in_transit']);
            $table->dropColumn([
                'paused_at',
                'current_pause_reason',
                'total_paused_minutes',
                'external_in_transit',
                'external_dispatched_at',
                'external_received_at',
            ]);
        });
    }
};
