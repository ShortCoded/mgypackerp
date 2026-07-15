<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_halls', function (Blueprint $table): void {
            if (! Schema::hasColumn('branch_halls', 'public_uuid')) {
                $table->uuid('public_uuid')->nullable()->after('id');
            }

            if (! Schema::hasColumn('branch_halls', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('position')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('branch_halls', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('branch_halls', 'deleted_by')) {
                $table->foreignId('deleted_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('branch_halls', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });

        Schema::table('branch_refrigerators', function (Blueprint $table): void {
            if (! Schema::hasColumn('branch_refrigerators', 'public_uuid')) {
                $table->uuid('public_uuid')->nullable()->after('id');
            }
        });

        Schema::table('branch_refrigerator_capacities', function (Blueprint $table): void {
            if (! Schema::hasColumn('branch_refrigerator_capacities', 'public_uuid')) {
                $table->uuid('public_uuid')->nullable()->after('id');
            }
        });

        $this->backfillPublicUuids();
        $this->replaceBranchHallUniqueIndex();
        $this->createPublicUuidIndexes();
    }

    public function down(): void
    {
        $this->dropIndexIfExists('branch_halls_name_unique_active');
        $this->dropIndexIfExists('branch_halls_public_uuid_unique');
        $this->dropIndexIfExists('branch_refrigerators_public_uuid_unique');
        $this->dropIndexIfExists('branch_refrigerator_capacities_public_uuid_unique');

        Schema::table('branch_halls', function (Blueprint $table): void {
            if (Schema::hasColumn('branch_halls', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            foreach (['deleted_by', 'updated_by', 'created_by'] as $column) {
                if (Schema::hasColumn('branch_halls', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            if (Schema::hasColumn('branch_halls', 'public_uuid')) {
                $table->dropColumn('public_uuid');
            }
        });

        Schema::table('branch_refrigerator_capacities', function (Blueprint $table): void {
            if (Schema::hasColumn('branch_refrigerator_capacities', 'public_uuid')) {
                $table->dropColumn('public_uuid');
            }
        });

        Schema::table('branch_refrigerators', function (Blueprint $table): void {
            if (Schema::hasColumn('branch_refrigerators', 'public_uuid')) {
                $table->dropColumn('public_uuid');
            }
        });

        Schema::table('branch_halls', function (Blueprint $table): void {
            $table->unique(['branch_id', 'name']);
        });
    }

    private function backfillPublicUuids(): void
    {
        foreach (['branch_halls', 'branch_refrigerators', 'branch_refrigerator_capacities'] as $table) {
            if (! Schema::hasColumn($table, 'public_uuid')) {
                continue;
            }

            DB::table($table)
                ->whereNull('public_uuid')
                ->orderBy('id')
                ->get(['id'])
                ->each(function (object $row) use ($table): void {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['public_uuid' => (string) Str::uuid()]);
                });
        }
    }

    private function replaceBranchHallUniqueIndex(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.DB::getQueryGrammar()->wrapTable('branch_halls').' DROP CONSTRAINT IF EXISTS '.DB::getQueryGrammar()->wrap('branch_halls_branch_id_name_unique'));
        }

        $this->dropIndexIfExists('branch_halls_branch_id_name_unique');

        if (! Schema::hasColumn('branch_halls', 'deleted_at')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap('branch_halls_name_unique_active');
        $wrappedTable = $grammar->wrapTable('branch_halls');
        $wrappedBranch = $grammar->wrap('branch_id');
        $wrappedName = $grammar->wrap('name');
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedBranch}, {$wrappedName}) WHERE {$predicate}");
    }

    private function createPublicUuidIndexes(): void
    {
        $this->createUniqueIndex('branch_halls', 'branch_halls_public_uuid_unique', 'public_uuid');
        $this->createUniqueIndex('branch_refrigerators', 'branch_refrigerators_public_uuid_unique', 'public_uuid');
        $this->createUniqueIndex('branch_refrigerator_capacities', 'branch_refrigerator_capacities_public_uuid_unique', 'public_uuid');
    }

    private function createUniqueIndex(string $table, string $index, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn})");
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
    }
};
