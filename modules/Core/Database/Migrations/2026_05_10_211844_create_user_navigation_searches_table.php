<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_navigation_searches')) {
            Schema::create('user_navigation_searches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title');
                $table->string('url', 2048);
                $table->string('route_name')->nullable()->index();
                $table->string('icon')->nullable();
                $table->string('parent_path')->nullable();
                $table->timestamp('last_used_at')->index();
                $table->timestamps();

                $table->unique(['user_id', 'url']);
                $table->index(['user_id', 'last_used_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_navigation_searches');
    }
};
