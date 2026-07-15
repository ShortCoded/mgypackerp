<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_events') || Schema::hasColumn('calendar_events', 'meeting_url')) {
            return;
        }

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->string('meeting_url', 2048)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('calendar_events') || ! Schema::hasColumn('calendar_events', 'meeting_url')) {
            return;
        }

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropColumn('meeting_url');
        });
    }
};
