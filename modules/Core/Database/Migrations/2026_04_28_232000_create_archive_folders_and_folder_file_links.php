<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('archive_folders')) {
            Schema::create('archive_folders', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->foreignId('parent_id')->nullable()->constrained('archive_folders')->nullOnDelete();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->text('path_cache')->nullable();
                $table->string('module')->nullable()->index();
                $table->string('record_type')->nullable()->index();
                $table->string('record_doc_num')->nullable()->index();
                $table->nullableMorphs('attachable');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes()->index();
            });
        } else {
            $this->addMissingFolderColumns();
        }

        Schema::table('archive_files', function (Blueprint $table): void {
            if (! Schema::hasColumn('archive_files', 'archive_folder_id')) {
                $table->foreignId('archive_folder_id')->nullable()->after('doc_num')->constrained('archive_folders')->nullOnDelete();
            }

            if (! Schema::hasColumn('archive_files', 'folder_doc_num')) {
                $table->string('folder_doc_num')->nullable()->after('archive_folder_id')->index();
            }
        });

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createIndexes(),
            default => null,
        };
    }

    public function down(): void
    {
        foreach ([
            'archive_folders_doc_num_unique_active',
            'archive_folders_sibling_name_unique_active',
            'archive_files_folder_name_unique_active',
        ] as $index) {
            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index)),
                default => null,
            };
        }
    }

    private function addMissingFolderColumns(): void
    {
        Schema::table('archive_folders', function (Blueprint $table): void {
            $this->addColumnIfMissing('archive_folders', 'doc_number', fn () => $table->integer('doc_number')->nullable()->index());
            $this->addColumnIfMissing('archive_folders', 'doc_num', fn () => $table->string('doc_num')->nullable()->index());
            $this->addColumnIfMissing('archive_folders', 'parent_id', fn () => $table->foreignId('parent_id')->nullable()->constrained('archive_folders')->nullOnDelete());
            $this->addColumnIfMissing('archive_folders', 'name', fn () => $table->string('name')->nullable());
            $this->addColumnIfMissing('archive_folders', 'slug', fn () => $table->string('slug')->nullable());
            $this->addColumnIfMissing('archive_folders', 'path_cache', fn () => $table->text('path_cache')->nullable());
            $this->addColumnIfMissing('archive_folders', 'module', fn () => $table->string('module')->nullable()->index());
            $this->addColumnIfMissing('archive_folders', 'record_type', fn () => $table->string('record_type')->nullable()->index());
            $this->addColumnIfMissing('archive_folders', 'record_doc_num', fn () => $table->string('record_doc_num')->nullable()->index());

            if (! Schema::hasColumn('archive_folders', 'attachable_type') && ! Schema::hasColumn('archive_folders', 'attachable_id')) {
                $table->nullableMorphs('attachable');
            }

            $this->addColumnIfMissing('archive_folders', 'created_by', fn () => $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing('archive_folders', 'updated_by', fn () => $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing('archive_folders', 'deleted_by', fn () => $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete());

            if (! Schema::hasColumn('archive_folders', 'created_at') && ! Schema::hasColumn('archive_folders', 'updated_at')) {
                $table->timestamps();
            }

            if (! Schema::hasColumn('archive_folders', 'deleted_at')) {
                $table->softDeletes()->index();
            }
        });
    }

    private function addColumnIfMissing(string $tableName, string $column, callable $definition): void
    {
        if (! Schema::hasColumn($tableName, $column)) {
            $definition();
        }
    }

    private function createIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $folders = $grammar->wrapTable('archive_folders');
        $files = $grammar->wrapTable('archive_files');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('archive_folders_doc_num_unique_active')." ON {$folders} (".$grammar->wrap('doc_num').') WHERE '.$grammar->wrap('deleted_at').' IS NULL');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('archive_folders_sibling_name_unique_active')." ON {$folders} (COALESCE(".$grammar->wrap('parent_id').', 0), COALESCE('.$grammar->wrap('module').", ''), COALESCE(".$grammar->wrap('record_type').", ''), COALESCE(".$grammar->wrap('record_doc_num').", ''), LOWER(".$grammar->wrap('name').')) WHERE '.$grammar->wrap('deleted_at').' IS NULL');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('archive_files_folder_name_unique_active')." ON {$files} (COALESCE(".$grammar->wrap('archive_folder_id').', 0), LOWER('.$grammar->wrap('original_name').')) WHERE '.$grammar->wrap('deleted_at').' IS NULL');
    }
};
