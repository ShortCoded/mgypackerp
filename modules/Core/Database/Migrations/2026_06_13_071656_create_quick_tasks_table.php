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
        'doc_number' => 'quick_tasks_company_doc_number_unique_active',
        'doc_num' => 'quick_tasks_company_doc_num_unique_active',
    ];

    public function up(): void
    {
        Schema::create('quick_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->string('title');
            $table->string('summary')->nullable();
            $table->text('details')->nullable();
            $table->string('status', 30)->default('new')->index();
            $table->string('priority', 30)->default('normal')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
            $table->index(['company_id', 'branch_id', 'status', 'created_at'], 'quick_tasks_scope_status_created_index');
            $table->index(['company_id', 'assigned_to', 'deleted_at'], 'quick_tasks_company_assignee_deleted_index');
        });

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

        Schema::dropIfExists('quick_tasks');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedTable = $grammar->wrapTable('quick_tasks');
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
