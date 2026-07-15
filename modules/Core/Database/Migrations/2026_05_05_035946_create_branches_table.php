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
        'doc_number' => 'branches_doc_number_unique_active',
        'doc_num' => 'branches_doc_num_unique_active',
        'company_name' => 'branches_company_name_unique_active',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('name');
                $table->string('type')->index();
                $table->text('address')->nullable();
                $table->string('camera_url')->nullable();
                $table->string('phone')->nullable();
                $table->string('mobile')->nullable();
                $table->string('email')->nullable();
                $table->string('hotline')->nullable();
                $table->string('contact_person')->nullable();
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
        foreach ($this->activeUniqueIndexes as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                default => null,
            };
        }

        Schema::dropIfExists('branches');
    }

    private function createActiveUniqueIndexes(): void
    {
        foreach ($this->activeUniqueIndexes as $column => $index) {
            if (! Schema::hasColumn('branches', $column)) {
                if ($column !== 'company_name') {
                    continue;
                }
            }

            if ($column === 'company_name') {
                $this->createCompanyNameUniqueIndex($index);

                continue;
            }

            $this->createActiveUniqueIndex($column, $index);
        }
    }

    private function createActiveUniqueIndex(string $column, string $index): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('branches');
        $wrappedColumn = $grammar->wrap($column);
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        if (in_array($column, ['doc_number', 'doc_num'], true)) {
            $predicate .= " AND {$wrappedColumn} IS NOT NULL";
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$predicate}");
    }

    private function createCompanyNameUniqueIndex(string $index): void
    {
        if (! Schema::hasColumn('branches', 'company_id') || ! Schema::hasColumn('branches', 'name')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('branches');
        $wrappedCompany = $grammar->wrap('company_id');
        $wrappedName = $grammar->wrap('name');
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedCompany}, {$wrappedName}) WHERE {$predicate}");
    }
};
