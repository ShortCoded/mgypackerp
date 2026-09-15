<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->uuid('event_uuid')->nullable()->after('public_uuid')->index();
            $table->string('module', 50)->nullable()->after('category')->index();
            $table->string('severity', 20)->default('information')->after('module')->index();
            $table->boolean('requires_action')->default(false)->after('severity');
            $table->string('sound_key', 20)->nullable()->after('requires_action');
            $table->string('external_title', 255)->nullable()->after('body');
            $table->text('external_body')->nullable()->after('external_title');
            $table->string('required_permission', 150)->nullable()->after('url')->index();
            $table->foreignId('company_id')->nullable()->after('required_permission')->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->after('branch_id')->constrained('chat_conversations')->nullOnDelete();
            $table->string('push_status', 30)->default('not_attempted')->after('read_at')->index();
            $table->unsignedSmallInteger('push_attempts')->default(0)->after('push_status');
            $table->timestamp('push_last_attempt_at')->nullable()->after('push_attempts');
            $table->string('push_error_code', 80)->nullable()->after('push_last_attempt_at');

            $table->index(['user_id', 'delivered_at', 'id'], 'user_notifications_poll_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('user_notifications_poll_index');
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn([
                'event_uuid',
                'module',
                'severity',
                'requires_action',
                'sound_key',
                'external_title',
                'external_body',
                'required_permission',
                'push_status',
                'push_attempts',
                'push_last_attempt_at',
                'push_error_code',
            ]);
        });
    }
};
