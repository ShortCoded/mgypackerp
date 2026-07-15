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
        'doc_number' => 'task_boards_company_doc_number_unique_active',
        'doc_num' => 'task_boards_company_doc_num_unique_active',
    ];

    public function up(): void
    {
        Schema::create('task_boards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_public')->default(false)->index();
            $table->boolean('requires_password')->default(false);
            $table->string('public_token', 96)->unique();
            $table->string('public_password_hash')->nullable();
            $table->timestamp('last_public_access_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
            $table->index(['company_id', 'branch_id', 'is_active', 'deleted_at'], 'task_boards_scope_active_deleted_index');
            $table->index(['public_token', 'is_public', 'is_active', 'deleted_at'], 'task_boards_public_lookup_index');
        });

        Schema::create('task_board_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['task_board_id', 'user_id'], 'task_board_user_unique');
        });

        Schema::create('task_board_role', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_board_id')->constrained('task_boards')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['task_board_id', 'role_id'], 'task_board_role_unique');
        });

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createActiveUniqueIndexes(),
            default => null,
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('task_board_role');
        Schema::dropIfExists('task_board_user');

        foreach ($this->activeUniqueIndexes as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                default => null,
            };
        }

        Schema::dropIfExists('task_boards');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedTable = $grammar->wrapTable('task_boards');
        $companyId = $grammar->wrap('company_id');
        $deletedAt = $grammar->wrap('deleted_at');

        foreach ($this->activeUniqueIndexes as $column => $index) {
            $wrappedIndex = $grammar->wrap($index);
            $wrappedColumn = $grammar->wrap($column);
            $predicate = "{$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL";

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$companyId}, {$wrappedColumn}) WHERE {$predicate}");
        }
    }
};
