<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\UserTask;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $activeUniqueIndexes = [
        'doc_number' => 'board_lists_doc_number_unique_active',
        'doc_num' => 'board_lists_doc_num_unique_active',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('board_lists')) {
            Schema::create('board_lists', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('type', 20)->index();
                $table->string('name');
                $table->string('slug', 80);
                $table->string('status', 30)->default(UserTask::StatusTodo)->index();
                $table->string('color', 30)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_system')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes()->index();
                $table->unique(['type', 'slug', 'deleted_at']);
                $table->index(['type', 'position']);
                $table->index(['type', 'status', 'position']);
            });
        }

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createActiveUniqueIndexes(),
            default => null,
        };

        if (Schema::hasTable('user_tasks') && ! Schema::hasColumn('user_tasks', 'board_list_id')) {
            Schema::table('user_tasks', function (Blueprint $table): void {
                $table->foreignId('board_list_id')->nullable()->constrained('board_lists')->nullOnDelete();
                $table->index(['assigned_to', 'type', 'board_list_id', 'position'], 'user_tasks_assignee_type_list_position_index');
            });
        }

        $this->seedDefaultLists();
        $this->backfillUserTasks();
    }

    public function down(): void
    {
        if (Schema::hasTable('user_tasks') && Schema::hasColumn('user_tasks', 'board_list_id')) {
            Schema::table('user_tasks', function (Blueprint $table): void {
                $table->dropIndex('user_tasks_assignee_type_list_position_index');
                $table->dropConstrainedForeignId('board_list_id');
            });
        }

        foreach ($this->activeUniqueIndexes as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                default => null,
            };
        }

        Schema::dropIfExists('board_lists');
    }

    private function createActiveUniqueIndexes(): void
    {
        foreach ($this->activeUniqueIndexes as $column => $index) {
            if (! Schema::hasColumn('board_lists', $column)) {
                continue;
            }

            $grammar = DB::getQueryGrammar();
            $wrappedIndex = $grammar->wrap($index);
            $wrappedTable = $grammar->wrapTable('board_lists');
            $wrappedColumn = $grammar->wrap($column);
            $predicate = $grammar->wrap('deleted_at')." IS NULL AND {$wrappedColumn} IS NOT NULL";

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$predicate}");
        }
    }

    private function seedDefaultLists(): void
    {
        $now = now();
        $defaults = [
            [1, 'BoardList-00001', UserTask::TypeTask, 'To Do', UserTask::StatusTodo, 'primary', 0],
            [2, 'BoardList-00002', UserTask::TypeTask, 'In Progress', UserTask::StatusInProgress, 'info', 1],
            [3, 'BoardList-00003', UserTask::TypeTask, 'Waiting', UserTask::StatusWaiting, 'warning', 2],
            [4, 'BoardList-00004', UserTask::TypeTask, 'Done', UserTask::StatusDone, 'success', 3],
            [5, 'BoardList-00005', UserTask::TypeNote, 'To Do', UserTask::StatusTodo, 'primary', 0],
            [6, 'BoardList-00006', UserTask::TypeNote, 'In Progress', UserTask::StatusInProgress, 'info', 1],
            [7, 'BoardList-00007', UserTask::TypeNote, 'Waiting', UserTask::StatusWaiting, 'warning', 2],
            [8, 'BoardList-00008', UserTask::TypeNote, 'Done', UserTask::StatusDone, 'success', 3],
        ];

        foreach ($defaults as [$number, $docNum, $type, $name, $status, $color, $position]) {
            DB::table('board_lists')->updateOrInsert(
                ['type' => $type, 'slug' => $status],
                [
                    'doc_number' => $number,
                    'doc_num' => $docNum,
                    'name' => $name,
                    'status' => $status,
                    'color' => $color,
                    'position' => $position,
                    'is_system' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                    'deleted_at' => null,
                ],
            );
        }
    }

    private function backfillUserTasks(): void
    {
        if (! Schema::hasTable('user_tasks') || ! Schema::hasColumn('user_tasks', 'board_list_id')) {
            return;
        }

        $lists = DB::table('board_lists')
            ->whereNull('deleted_at')
            ->get(['id', 'type', 'status'])
            ->keyBy(fn (object $list): string => "{$list->type}:{$list->status}");

        foreach ($lists as $key => $list) {
            [$type, $status] = explode(':', $key, 2);

            DB::table('user_tasks')
                ->whereNull('board_list_id')
                ->where('type', $type)
                ->where('status', $status)
                ->update(['board_list_id' => $list->id]);
        }
    }
};
