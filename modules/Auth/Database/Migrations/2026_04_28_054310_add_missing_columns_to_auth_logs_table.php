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
        if (! Schema::hasTable('auth_logs')) {
            return;
        }

        Schema::table('auth_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('auth_logs', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('auth_logs', 'login')) {
                $table->string('login')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'identifier')) {
                $table->string('identifier')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'email')) {
                $table->string('email')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'phone')) {
                $table->string('phone')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'username')) {
                $table->string('username')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'event')) {
                $table->string('event')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'status')) {
                $table->string('status')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'remember_me')) {
                $table->boolean('remember_me')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'user_agent')) {
                $table->text('user_agent')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'guard')) {
                $table->string('guard')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'url')) {
                $table->text('url')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'method')) {
                $table->string('method')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'payload_summary')) {
                $table->jsonb('payload_summary')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'failure_reason')) {
                $table->text('failure_reason')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'context')) {
                $table->jsonb('context')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'logged_in_at')) {
                $table->timestamp('logged_in_at')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'logged_out_at')) {
                $table->timestamp('logged_out_at')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
