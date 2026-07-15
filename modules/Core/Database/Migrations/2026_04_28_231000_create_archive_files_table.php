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
        'doc_number' => 'archive_files_doc_number_unique_active',
        'doc_num' => 'archive_files_doc_num_unique_active',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('archive_files')) {
            Schema::create('archive_files', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->nullableMorphs('attachable');
                $table->string('module')->nullable()->index();
                $table->string('record_type')->nullable()->index();
                $table->string('record_doc_num')->nullable()->index();
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('original_name');
                $table->string('stored_name');
                $table->string('disk');
                $table->string('path');
                $table->string('mime_type')->nullable();
                $table->string('extension', 32)->nullable()->index();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('checksum', 128)->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes()->index();
            });
        } else {
            $this->addMissingColumns();
        }

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createActiveUniqueIndexes(),
            default => null,
        };
    }

    public function down(): void
    {
        if (! Schema::hasTable('archive_files')) {
            return;
        }

        foreach ($this->activeUniqueIndexes as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                default => null,
            };
        }
    }

    private function addMissingColumns(): void
    {
        Schema::table('archive_files', function (Blueprint $table): void {
            $this->addColumnIfMissing($table, 'doc_number', fn () => $table->integer('doc_number')->nullable()->index());
            $this->addColumnIfMissing($table, 'doc_num', fn () => $table->string('doc_num')->nullable()->index());

            if (! Schema::hasColumn('archive_files', 'attachable_type') && ! Schema::hasColumn('archive_files', 'attachable_id')) {
                $table->nullableMorphs('attachable');
            }

            $this->addColumnIfMissing($table, 'module', fn () => $table->string('module')->nullable()->index());
            $this->addColumnIfMissing($table, 'record_type', fn () => $table->string('record_type')->nullable()->index());
            $this->addColumnIfMissing($table, 'record_doc_num', fn () => $table->string('record_doc_num')->nullable()->index());
            $this->addColumnIfMissing($table, 'title', fn () => $table->string('title')->nullable());
            $this->addColumnIfMissing($table, 'description', fn () => $table->text('description')->nullable());
            $this->addColumnIfMissing($table, 'original_name', fn () => $table->string('original_name')->nullable());
            $this->addColumnIfMissing($table, 'stored_name', fn () => $table->string('stored_name')->nullable());
            $this->addColumnIfMissing($table, 'disk', fn () => $table->string('disk')->nullable());
            $this->addColumnIfMissing($table, 'path', fn () => $table->string('path')->nullable());
            $this->addColumnIfMissing($table, 'mime_type', fn () => $table->string('mime_type')->nullable());
            $this->addColumnIfMissing($table, 'extension', fn () => $table->string('extension', 32)->nullable()->index());
            $this->addColumnIfMissing($table, 'size_bytes', fn () => $table->unsignedBigInteger('size_bytes')->default(0));
            $this->addColumnIfMissing($table, 'checksum', fn () => $table->string('checksum', 128)->nullable());
            $this->addColumnIfMissing($table, 'uploaded_by', fn () => $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing($table, 'deleted_by', fn () => $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete());

            if (! Schema::hasColumn('archive_files', 'created_at') && ! Schema::hasColumn('archive_files', 'updated_at')) {
                $table->timestamps();
            }

            if (! Schema::hasColumn('archive_files', 'deleted_at')) {
                $table->softDeletes()->index();
            }
        });
    }

    private function addColumnIfMissing(Blueprint $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn('archive_files', $column)) {
            $definition();
        }
    }

    private function createActiveUniqueIndexes(): void
    {
        foreach ($this->activeUniqueIndexes as $column => $index) {
            if (! Schema::hasColumn('archive_files', $column)) {
                continue;
            }

            $grammar = DB::getQueryGrammar();
            $wrappedIndex = $grammar->wrap($index);
            $wrappedTable = $grammar->wrapTable('archive_files');
            $wrappedColumn = $grammar->wrap($column);
            $wrappedDeletedAt = $grammar->wrap('deleted_at');

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL");
        }
    }
};
