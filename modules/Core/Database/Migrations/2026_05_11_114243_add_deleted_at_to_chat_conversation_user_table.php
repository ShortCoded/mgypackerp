<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversation_user', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_conversation_user', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('archived_at')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversation_user', function (Blueprint $table): void {
            if (Schema::hasColumn('chat_conversation_user', 'deleted_at')) {
                $table->dropColumn('deleted_at');
            }
        });
    }
};
