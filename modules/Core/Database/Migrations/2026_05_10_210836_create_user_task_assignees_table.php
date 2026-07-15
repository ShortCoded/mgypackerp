<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_task_assignees')) {
            Schema::create('user_task_assignees', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_task_id')->constrained('user_tasks')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['user_task_id', 'user_id']);
                $table->index(['user_id', 'user_task_id']);
            });
        }

        $this->backfillAssignees();
        $this->storeDefaultBoardListLabels();
    }

    public function down(): void
    {
        Schema::dropIfExists('user_task_assignees');
    }

    private function backfillAssignees(): void
    {
        if (! Schema::hasTable('user_tasks') || ! Schema::hasTable('user_task_assignees')) {
            return;
        }

        $now = now();

        DB::table('user_tasks')
            ->whereNotNull('assigned_to')
            ->orderBy('id')
            ->select(['id', 'assigned_to', 'assigned_by', 'created_by'])
            ->chunkById(200, function ($tasks) use ($now): void {
                foreach ($tasks as $task) {
                    DB::table('user_task_assignees')->updateOrInsert(
                        [
                            'user_task_id' => $task->id,
                            'user_id' => $task->assigned_to,
                        ],
                        [
                            'assigned_by' => $task->assigned_by ?: $task->created_by,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                }
            });
    }

    private function storeDefaultBoardListLabels(): void
    {
        if (! Schema::hasTable('board_lists')) {
            return;
        }

        $locale = (string) config('app.locale', 'ar');
        $names = $locale === 'ar'
            ? [
                'todo' => 'للبدء',
                'in_progress' => 'قيد التنفيذ',
                'waiting' => 'انتظار',
                'done' => 'مكتملة',
            ]
            : [
                'todo' => 'To Do',
                'in_progress' => 'In Progress',
                'waiting' => 'Waiting',
                'done' => 'Done',
            ];

        foreach ($names as $slug => $name) {
            DB::table('board_lists')
                ->where('is_system', true)
                ->where('slug', $slug)
                ->update([
                    'name' => $name,
                    'updated_at' => now(),
                ]);
        }
    }
};
