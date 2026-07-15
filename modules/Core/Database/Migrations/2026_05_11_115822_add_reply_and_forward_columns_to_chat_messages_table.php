<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_messages', 'reply_to_message_id')) {
                $table->foreignId('reply_to_message_id')
                    ->nullable()
                    ->after('sender_id')
                    ->constrained('chat_messages')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('chat_messages', 'forwarded_from_message_id')) {
                $table->foreignId('forwarded_from_message_id')
                    ->nullable()
                    ->after('reply_to_message_id')
                    ->constrained('chat_messages')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('chat_messages', 'forwarded_from_user_id')) {
                $table->foreignId('forwarded_from_user_id')
                    ->nullable()
                    ->after('forwarded_from_message_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('chat_messages', 'forwarded_from_user_id')) {
                $table->dropConstrainedForeignId('forwarded_from_user_id');
            }

            if (Schema::hasColumn('chat_messages', 'forwarded_from_message_id')) {
                $table->dropConstrainedForeignId('forwarded_from_message_id');
            }

            if (Schema::hasColumn('chat_messages', 'reply_to_message_id')) {
                $table->dropConstrainedForeignId('reply_to_message_id');
            }
        });
    }
};
