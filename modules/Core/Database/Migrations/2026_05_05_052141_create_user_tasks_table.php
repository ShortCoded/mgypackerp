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
        'doc_number' => 'user_tasks_doc_number_unique_active',
        'doc_num' => 'user_tasks_doc_num_unique_active',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('user_tasks')) {
            Schema::create('user_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('type', 20)->default('task')->index();
                $table->string('status', 30)->default('todo')->index();
                $table->string('priority', 30)->default('normal')->index();
                $table->string('color', 30)->nullable();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('start_at')->nullable()->index();
                $table->timestamp('due_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->unsignedInteger('position')->default(0);
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();
                $table->index(['assigned_to', 'status', 'position']);
                $table->index(['created_by', 'status', 'position']);
                $table->index(['assigned_by', 'deleted_at']);
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

        Schema::dropIfExists('user_tasks');
    }

    private function createActiveUniqueIndexes(): void
    {
        foreach ($this->activeUniqueIndexes as $column => $index) {
            if (! Schema::hasColumn('user_tasks', $column)) {
                continue;
            }

            $grammar = DB::getQueryGrammar();
            $wrappedIndex = $grammar->wrap($index);
            $wrappedTable = $grammar->wrapTable('user_tasks');
            $wrappedColumn = $grammar->wrap($column);
            $predicate = $grammar->wrap('deleted_at')." IS NULL AND {$wrappedColumn} IS NOT NULL";

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$predicate}");
        }
    }
};
