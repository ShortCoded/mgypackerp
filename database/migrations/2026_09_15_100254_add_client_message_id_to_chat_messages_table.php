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
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->uuid('client_message_id')->nullable()->after('sender_id');
            $table->unique(
                ['conversation_id', 'sender_id', 'client_message_id'],
                'chat_messages_client_message_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique('chat_messages_client_message_unique');
            $table->dropColumn('client_message_id');
        });
    }
};
