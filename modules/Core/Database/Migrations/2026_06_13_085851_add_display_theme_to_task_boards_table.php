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
        if (! Schema::hasColumn('task_boards', 'display_theme')) {
            Schema::table('task_boards', function (Blueprint $table): void {
                $table->string('display_theme', 10)
                    ->default('light')
                    ->index('task_boards_display_theme_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('task_boards', 'display_theme')) {
            Schema::table('task_boards', function (Blueprint $table): void {
                if (Schema::hasIndex('task_boards', 'task_boards_display_theme_index')) {
                    $table->dropIndex('task_boards_display_theme_index');
                }

                $table->dropColumn('display_theme');
            });
        }
    }
};
