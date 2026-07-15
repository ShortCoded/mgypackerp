<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('name');
                $table->string('name_en')->nullable();
                $table->string('code', 10);
                $table->string('minor_unit_name')->nullable();
                $table->unsignedInteger('minor_unit_factor')->default(100);
                $table->boolean('is_main')->default(false)->index();
                $table->string('status')->default('active')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();
            });
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        foreach ([
            'currencies_doc_number_unique_active',
            'currencies_doc_num_unique_active',
            'currencies_code_unique_active',
            'currencies_main_unique_active',
        ] as $index) {
            $wrappedIndex = DB::getQueryGrammar()->wrap($index);

            if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
                DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
            }
        }

        Schema::dropIfExists('currencies');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('currencies');
        $deletedAt = $grammar->wrap('deleted_at');

        foreach ([
            'doc_number' => 'currencies_doc_number_unique_active',
            'doc_num' => 'currencies_doc_num_unique_active',
            'code' => 'currencies_code_unique_active',
        ] as $column => $index) {
            $wrappedIndex = $grammar->wrap($index);
            $wrappedColumn = $grammar->wrap($column);

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$table} ({$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
        }

        $trueValue = DB::getDriverName() === 'pgsql' ? 'true' : '1';

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('currencies_main_unique_active').' ON '.$table.' ('.$grammar->wrap('is_main').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('status')." = 'active' AND ".$grammar->wrap('is_main').' = '.$trueValue);
    }
};
