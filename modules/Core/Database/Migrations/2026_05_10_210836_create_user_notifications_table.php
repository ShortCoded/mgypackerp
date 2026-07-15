<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 80)->index();
                $table->string('category', 40)->nullable()->index();
                $table->string('title');
                $table->text('body')->nullable();
                $table->string('url', 2048)->nullable();
                $table->timestamp('scheduled_for')->nullable()->index();
                $table->timestamp('delivered_at')->nullable()->index();
                $table->timestamp('read_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->string('dedupe_key', 191)->nullable()->unique();
                $table->timestamps();

                $table->index(['user_id', 'read_at', 'delivered_at']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
