<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_refrigerators')) {
            Schema::create('branch_refrigerators', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->string('name');
                $table->unsignedInteger('position')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes()->index();
            });
        }

        if (! Schema::hasTable('branch_refrigerator_capacities')) {
            Schema::create('branch_refrigerator_capacities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_refrigerator_id')->constrained('branch_refrigerators')->cascadeOnDelete();
                $table->foreignId('item_unit_id')->nullable()->constrained('item_units')->nullOnDelete();
                $table->decimal('max_capacity', 15, 3)->nullable();
                $table->unsignedInteger('position')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();
            });
        }

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createRefrigeratorNameIndex(),
            default => null,
        };
    }

    public function down(): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap('branch_refrigerators_branch_name_unique_active');

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            default => null,
        };

        Schema::dropIfExists('branch_refrigerator_capacities');
        Schema::dropIfExists('branch_refrigerators');
    }

    private function createRefrigeratorNameIndex(): void
    {
        if (! Schema::hasTable('branch_refrigerators')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap('branch_refrigerators_branch_name_unique_active');
        $wrappedTable = $grammar->wrapTable('branch_refrigerators');
        $wrappedBranch = $grammar->wrap('branch_id');
        $wrappedName = $grammar->wrap('name');
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedBranch}, {$wrappedName}) WHERE {$predicate}");
    }
};
