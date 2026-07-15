<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private array $tables = [
        'item_decals' => ['doc_number', 'doc_num', 'name'],
        'item_origin_countries' => ['doc_number', 'doc_num', 'name'],
    ];

    public function up(): void
    {
        foreach (array_keys($this->tables) as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                    $table->unsignedBigInteger('doc_number')->nullable()->index();
                    $table->string('doc_num')->nullable()->index();
                    $table->string('name');
                    $table->string('status')->default('active')->index();
                    $table->text('notes')->nullable();
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->timestamp('restored_at')->nullable();
                    $table->timestamps();
                    $table->softDeletes()->index();

                    $table->index('company_id');
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
        foreach (array_reverse(array_keys($this->tables)) as $tableName) {
            foreach ($this->activeUniqueIndexes($tableName) as $index) {
                $this->dropIndexIfExists($index);
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
            'name' => "{$tableName}_company_name_unique_active",
            'doc_number' => "{$tableName}_company_doc_number_unique_active",
            'doc_num' => "{$tableName}_company_doc_num_unique_active",
        ];
    }

    private function createActiveUniqueIndex(string $tableName, string $column, string $index): void
    {
        if (! Schema::hasColumn($tableName, $column) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedColumns = $grammar->wrap('company_id').', '.$grammar->wrap($column);
        $predicate = $grammar->wrap('deleted_at').' IS NULL AND '.$grammar->wrap('company_id').' IS NOT NULL';

        if (in_array($column, ['doc_number', 'doc_num'], true)) {
            $predicate .= ' AND '.$grammar->wrap($column).' IS NOT NULL';
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns}) WHERE {$predicate}");
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            default => null,
        };
    }
};
