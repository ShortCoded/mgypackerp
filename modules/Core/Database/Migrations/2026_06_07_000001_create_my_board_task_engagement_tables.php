<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $activeUniqueIndexes = [
        'doc_number' => 'my_board_labels_doc_number_unique_active',
        'doc_num' => 'my_board_labels_doc_num_unique_active',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('my_board_labels')) {
            Schema::create('my_board_labels', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('name', 80);
                $table->string('color', 30)->default('default')->index();
                $table->string('status', 30)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();
            });
        }

        if (! Schema::hasTable('my_board_task_label')) {
            Schema::create('my_board_task_label', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_task_id')->constrained('user_tasks')->cascadeOnDelete();
                $table->foreignId('my_board_label_id')->constrained('my_board_labels')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['user_task_id', 'my_board_label_id']);
                $table->index(['my_board_label_id', 'user_task_id']);
            });
        }

        if (! Schema::hasTable('my_board_task_views')) {
            Schema::create('my_board_task_views', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_task_id')->constrained('user_tasks')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('viewed_at')->index();
                $table->timestamps();

                $table->unique(['user_task_id', 'user_id']);
                $table->index(['user_id', 'viewed_at']);
            });
        }

        if (! Schema::hasTable('my_board_task_comments')) {
            Schema::create('my_board_task_comments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_task_id')->constrained('user_tasks')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->longText('body_html');
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes()->index();

                $table->index(['user_task_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createActiveUniqueIndexes(),
            default => null,
        };
    }

    public function down(): void
    {
        foreach ($this->activeUniqueIndexes as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                default => null,
            };
        }

        $labelNameIndex = DB::getQueryGrammar()->wrap('my_board_labels_name_unique_active');

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$labelNameIndex}"),
            default => null,
        };

        Schema::dropIfExists('my_board_task_comments');
        Schema::dropIfExists('my_board_task_views');
        Schema::dropIfExists('my_board_task_label');
        Schema::dropIfExists('my_board_labels');
    }

    private function createActiveUniqueIndexes(): void
    {
        foreach ($this->activeUniqueIndexes as $column => $index) {
            if (! Schema::hasColumn('my_board_labels', $column)) {
                continue;
            }

            $grammar = DB::getQueryGrammar();
            $wrappedIndex = $grammar->wrap($index);
            $wrappedTable = $grammar->wrapTable('my_board_labels');
            $wrappedColumn = $grammar->wrap($column);
            $predicate = $grammar->wrap('deleted_at')." IS NULL AND {$wrappedColumn} IS NOT NULL";

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$predicate}");
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap('my_board_labels_name_unique_active');
        $wrappedTable = $grammar->wrapTable('my_board_labels');
        $wrappedName = $grammar->wrap('name');
        $wrappedDeletedAt = $grammar->wrap('deleted_at');
        $wrappedStatus = $grammar->wrap('status');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} (LOWER({$wrappedName})) WHERE {$wrappedDeletedAt} IS NULL AND {$wrappedStatus} = 'active'");
    }
};
