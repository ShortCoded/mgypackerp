<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_presence_sessions')) {
            return;
        }

        Schema::create('user_presence_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_fingerprint')->nullable()->index();
            $table->string('status')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser_name')->nullable();
            $table->string('os_name')->nullable();
            $table->string('device_type')->nullable();
            $table->timestamp('login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('offline_reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_presence_sessions');
    }
};
