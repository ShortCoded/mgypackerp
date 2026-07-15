<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_colors')) {
            Schema::create('item_colors', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
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
            'pgsql', 'sqlite' => $this->createActiveUniqueIndexes(),
            default => null,
        };
    }

    public function down(): void
    {
        foreach ($this->activeUniqueIndexes() as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                default => null,
            };
        }

        Schema::dropIfExists('item_colors');
    }

    private function createActiveUniqueIndexes(): void
    {
        foreach ($this->activeUniqueIndexes() as $column => $index) {
            $this->createActiveUniqueIndex($column, $index);
        }
    }

    /**
     * @return array<string, string>
     */
    private function activeUniqueIndexes(): array
    {
        return [
            'doc_number' => 'item_colors_doc_number_unique_active',
            'doc_num' => 'item_colors_doc_num_unique_active',
            'name' => 'item_colors_name_unique_active',
        ];
    }

    private function createActiveUniqueIndex(string $column, string $index): void
    {
        if (! Schema::hasColumn('item_colors', $column)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('item_colors');
        $wrappedColumn = $grammar->wrap($column);
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        if (in_array($column, ['doc_number', 'doc_num'], true)) {
            $predicate .= " AND {$wrappedColumn} IS NOT NULL";
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$predicate}");
    }
};
