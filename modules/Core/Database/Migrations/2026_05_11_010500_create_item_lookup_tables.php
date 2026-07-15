<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'item_units',
        'item_sizes',
        'item_models',
        'item_categories',
        'item_groups',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('doc_number')->nullable()->index();
                    $table->string('doc_num')->nullable()->index();
                    $table->string('code', 50)->nullable()->index();
                    $table->string('name');
                    $table->text('notes')->nullable();
                    $table->string('status')->default('active')->index();
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->timestamp('restored_at')->nullable();
                    $table->timestamps();
                    $table->softDeletes()->index();
                });
            }

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => $this->createActiveUniqueIndexes($tableName),
                default => null,
            };
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            foreach ($this->activeUniqueIndexes($tableName) as $index) {
                $wrappedIndex = DB::getQueryGrammar()->wrap($index);

                match (DB::getDriverName()) {
                    'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                    default => null,
                };
            }

            Schema::dropIfExists($tableName);
        }
    }

    private function createActiveUniqueIndexes(string $tableName): void
    {
        foreach ($this->activeUniqueIndexes($tableName) as $column => $index) {
            $this->createActiveUniqueIndex($tableName, $column, $index);
        }
    }

    /**
     * @return array<string, string>
     */
    private function activeUniqueIndexes(string $tableName): array
    {
        return [
            'doc_number' => "{$tableName}_doc_number_unique_active",
            'doc_num' => "{$tableName}_doc_num_unique_active",
            'code' => "{$tableName}_code_unique_active",
            'name' => "{$tableName}_name_unique_active",
        ];
    }

    private function createActiveUniqueIndex(string $tableName, string $column, string $index): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedColumn = $grammar->wrap($column);
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        if (in_array($column, ['doc_number', 'doc_num', 'code'], true)) {
            $predicate .= " AND {$wrappedColumn} IS NOT NULL";
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$predicate}");
    }
};
