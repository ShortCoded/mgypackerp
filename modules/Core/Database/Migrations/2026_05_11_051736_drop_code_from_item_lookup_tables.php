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
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'code')) {
                continue;
            }

            $this->dropIndexIfExists("{$tableName}_code_unique_active");
            $this->dropIndexIfExists("{$tableName}_code_index");

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('code');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'code')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('code', 50)->nullable()->after('doc_num')->index();
            });
        }
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
    }
};
