<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quick_tasks', function (Blueprint $table): void {
            $table->foreignId('task_board_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('task_boards')
                ->nullOnDelete();

            $table->index(['company_id', 'task_board_id', 'status', 'created_at'], 'quick_tasks_task_board_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('quick_tasks', function (Blueprint $table): void {
            $table->dropIndex('quick_tasks_task_board_status_created_index');
            $table->dropConstrainedForeignId('task_board_id');
        });
    }
};
