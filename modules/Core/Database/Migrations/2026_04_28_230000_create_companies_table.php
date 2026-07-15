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
        'doc_number' => 'companies_doc_number_unique_active',
        'doc_num' => 'companies_doc_num_unique_active',
        'name' => 'companies_name_unique_active',
        'code' => 'companies_code_unique_active',
        'email' => 'companies_email_unique_active',
        'commercial_register_number' => 'companies_commercial_register_number_unique_active',
        'tax_card_number' => 'companies_tax_card_number_unique_active',
        'vat_registration_number' => 'companies_vat_registration_number_unique_active',
        'national_id' => 'companies_national_id_unique_active',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            $this->createTable();
        } else {
            $this->addMissingColumns();
        }

        match (DB::getDriverName()) {
            'pgsql' => $this->createPostgreSqlIndexes(),
            'sqlite' => $this->createSqliteIndexes(),
            default => null,
        };
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        foreach ([...array_values($this->activeUniqueIndexes), 'companies_one_active_main_unique'] as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            match (DB::getDriverName()) {
                'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
                default => null,
            };
        }
    }

    private function createTable(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->integer('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->string('name')->index();
            $table->string('legal_name')->nullable();
            $table->string('commercial_name')->nullable();
            $table->string('code', 100)->nullable()->index();
            $table->string('logo')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->boolean('is_main')->default(false)->index();
            $table->text('notes')->nullable();
            $table->string('legal_form', 100)->nullable();
            $table->string('commercial_register_number', 100)->nullable();
            $table->string('commercial_register_office')->nullable();
            $table->date('commercial_register_date')->nullable();
            $table->date('commercial_register_expiry_date')->nullable();
            $table->string('tax_card_number', 100)->nullable();
            $table->string('tax_file_number', 100)->nullable();
            $table->string('tax_office')->nullable();
            $table->string('vat_registration_number', 100)->nullable();
            $table->string('industrial_register_number', 100)->nullable();
            $table->string('import_card_number', 100)->nullable();
            $table->string('export_card_number', 100)->nullable();
            $table->string('national_id', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('whatsapp', 50)->nullable();
            $table->string('fax', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('country', 100)->nullable()->default('Egypt');
            $table->string('governorate', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('area', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('postal_code', 50)->nullable();
            $table->text('map_url')->nullable();
            $table->string('industry')->nullable();
            $table->string('activity_type')->nullable();
            $table->text('business_description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();
        });
    }

    private function addMissingColumns(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $this->addColumnIfMissing($table, 'doc_number', fn () => $table->integer('doc_number')->nullable()->index());
            $this->addColumnIfMissing($table, 'doc_num', fn () => $table->string('doc_num')->nullable()->index());
            $this->addColumnIfMissing($table, 'name', fn () => $table->string('name')->nullable()->index());
            $this->addColumnIfMissing($table, 'legal_name', fn () => $table->string('legal_name')->nullable());
            $this->addColumnIfMissing($table, 'commercial_name', fn () => $table->string('commercial_name')->nullable());
            $this->addColumnIfMissing($table, 'code', fn () => $table->string('code', 100)->nullable()->index());
            $this->addColumnIfMissing($table, 'logo', fn () => $table->string('logo')->nullable());
            $this->addColumnIfMissing($table, 'status', fn () => $table->string('status', 20)->default('active')->index());
            $this->addColumnIfMissing($table, 'is_main', fn () => $table->boolean('is_main')->default(false)->index());
            $this->addColumnIfMissing($table, 'notes', fn () => $table->text('notes')->nullable());
            $this->addColumnIfMissing($table, 'legal_form', fn () => $table->string('legal_form', 100)->nullable());
            $this->addColumnIfMissing($table, 'commercial_register_number', fn () => $table->string('commercial_register_number', 100)->nullable());
            $this->addColumnIfMissing($table, 'commercial_register_office', fn () => $table->string('commercial_register_office')->nullable());
            $this->addColumnIfMissing($table, 'commercial_register_date', fn () => $table->date('commercial_register_date')->nullable());
            $this->addColumnIfMissing($table, 'commercial_register_expiry_date', fn () => $table->date('commercial_register_expiry_date')->nullable());
            $this->addColumnIfMissing($table, 'tax_card_number', fn () => $table->string('tax_card_number', 100)->nullable());
            $this->addColumnIfMissing($table, 'tax_file_number', fn () => $table->string('tax_file_number', 100)->nullable());
            $this->addColumnIfMissing($table, 'tax_office', fn () => $table->string('tax_office')->nullable());
            $this->addColumnIfMissing($table, 'vat_registration_number', fn () => $table->string('vat_registration_number', 100)->nullable());
            $this->addColumnIfMissing($table, 'industrial_register_number', fn () => $table->string('industrial_register_number', 100)->nullable());
            $this->addColumnIfMissing($table, 'import_card_number', fn () => $table->string('import_card_number', 100)->nullable());
            $this->addColumnIfMissing($table, 'export_card_number', fn () => $table->string('export_card_number', 100)->nullable());
            $this->addColumnIfMissing($table, 'national_id', fn () => $table->string('national_id', 100)->nullable());
            $this->addColumnIfMissing($table, 'phone', fn () => $table->string('phone', 50)->nullable());
            $this->addColumnIfMissing($table, 'mobile', fn () => $table->string('mobile', 50)->nullable());
            $this->addColumnIfMissing($table, 'whatsapp', fn () => $table->string('whatsapp', 50)->nullable());
            $this->addColumnIfMissing($table, 'fax', fn () => $table->string('fax', 50)->nullable());
            $this->addColumnIfMissing($table, 'email', fn () => $table->string('email')->nullable());
            $this->addColumnIfMissing($table, 'website', fn () => $table->string('website')->nullable());
            $this->addColumnIfMissing($table, 'country', fn () => $table->string('country', 100)->nullable()->default('Egypt'));
            $this->addColumnIfMissing($table, 'governorate', fn () => $table->string('governorate', 100)->nullable());
            $this->addColumnIfMissing($table, 'city', fn () => $table->string('city', 100)->nullable());
            $this->addColumnIfMissing($table, 'area', fn () => $table->string('area', 100)->nullable());
            $this->addColumnIfMissing($table, 'address', fn () => $table->text('address')->nullable());
            $this->addColumnIfMissing($table, 'postal_code', fn () => $table->string('postal_code', 50)->nullable());
            $this->addColumnIfMissing($table, 'map_url', fn () => $table->text('map_url')->nullable());
            $this->addColumnIfMissing($table, 'industry', fn () => $table->string('industry')->nullable());
            $this->addColumnIfMissing($table, 'activity_type', fn () => $table->string('activity_type')->nullable());
            $this->addColumnIfMissing($table, 'business_description', fn () => $table->text('business_description')->nullable());
            $this->addColumnIfMissing($table, 'created_by', fn () => $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing($table, 'updated_by', fn () => $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing($table, 'deleted_by', fn () => $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete());

            if (! Schema::hasColumn('companies', 'created_at') && ! Schema::hasColumn('companies', 'updated_at')) {
                $table->timestamps();
            }

            if (! Schema::hasColumn('companies', 'deleted_at')) {
                $table->softDeletes()->index();
            }
        });
    }

    private function addColumnIfMissing(Blueprint $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn('companies', $column)) {
            $definition();
        }
    }

    private function createPostgreSqlIndexes(): void
    {
        foreach ($this->activeUniqueIndexes as $column => $index) {
            if (! Schema::hasColumn('companies', $column)) {
                continue;
            }

            $this->dropPostgreSqlSingleColumnUniqueIndexes($column);
            $this->createActiveUniqueIndex($column, $index, $this->columnRequiresNotNullPredicate($column));
        }

        $this->createMainCompanyIndex();
    }

    private function createSqliteIndexes(): void
    {
        foreach ($this->activeUniqueIndexes as $column => $index) {
            if (! Schema::hasColumn('companies', $column)) {
                continue;
            }

            $this->createActiveUniqueIndex($column, $index, $this->columnRequiresNotNullPredicate($column));
        }

        $this->createMainCompanyIndex();
    }

    private function dropPostgreSqlSingleColumnUniqueIndexes(string $column): void
    {
        $indexes = DB::select(<<<'SQL'
            SELECT
                index_class.relname AS index_name,
                pg_constraint.conname AS constraint_name
            FROM pg_index
            INNER JOIN pg_class AS index_class ON index_class.oid = pg_index.indexrelid
            INNER JOIN pg_class AS table_class ON table_class.oid = pg_index.indrelid
            INNER JOIN pg_namespace ON pg_namespace.oid = table_class.relnamespace
            LEFT JOIN pg_constraint ON pg_constraint.conindid = pg_index.indexrelid
            WHERE pg_namespace.nspname = current_schema()
                AND table_class.relname = 'companies'
                AND pg_index.indisunique = true
                AND pg_index.indpred IS NULL
                AND (
                    SELECT array_agg(pg_attribute.attname::text ORDER BY indexed_columns.ordinality)
                    FROM unnest(pg_index.indkey) WITH ORDINALITY AS indexed_columns(attnum, ordinality)
                    INNER JOIN pg_attribute
                        ON pg_attribute.attrelid = table_class.oid
                        AND pg_attribute.attnum = indexed_columns.attnum
                ) = ARRAY[?]
        SQL, [$column]);

        $wrappedTable = DB::getQueryGrammar()->wrapTable('companies');

        foreach ($indexes as $index) {
            if ($index->constraint_name !== null) {
                $wrappedConstraint = DB::getQueryGrammar()->wrap($index->constraint_name);

                DB::statement("ALTER TABLE {$wrappedTable} DROP CONSTRAINT IF EXISTS {$wrappedConstraint}");
            }

            $wrappedIndex = DB::getQueryGrammar()->wrap($index->index_name);

            DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
        }
    }

    private function createActiveUniqueIndex(string $column, string $index, bool $requireValue): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable('companies');
        $wrappedColumn = $grammar->wrap($column);
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        if ($requireValue) {
            $predicate .= " AND {$wrappedColumn} IS NOT NULL";
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$predicate}");
    }

    private function createMainCompanyIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap('companies_one_active_main_unique');
        $wrappedTable = $grammar->wrapTable('companies');
        $wrappedIsMain = $grammar->wrap('is_main');
        $wrappedDeletedAt = $grammar->wrap('deleted_at');
        $wrappedStatus = $grammar->wrap('status');
        $isMainPredicate = DB::getDriverName() === 'pgsql' ? "{$wrappedIsMain} = true" : "{$wrappedIsMain} = 1";

        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedIsMain}) WHERE {$isMainPredicate} AND {$wrappedStatus} = 'active' AND {$wrappedDeletedAt} IS NULL"
        );
    }

    private function columnRequiresNotNullPredicate(string $column): bool
    {
        return in_array($column, [
            'code',
            'email',
            'commercial_register_number',
            'tax_card_number',
            'vat_registration_number',
            'national_id',
        ], true);
    }
};
