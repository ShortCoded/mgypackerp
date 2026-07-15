<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_stores', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'position']);
        });

        $this->createActiveUniqueIndex();
        $this->createPublicUuidIndex();
    }

    public function down(): void
    {
        $this->dropIndexIfExists('branch_stores_name_unique_active');
        $this->dropIndexIfExists('branch_stores_public_uuid_unique');

        Schema::dropIfExists('branch_stores');
    }

    private function createActiveUniqueIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap('branch_stores_name_unique_active');
        $wrappedTable = $grammar->wrapTable('branch_stores');
        $wrappedBranch = $grammar->wrap('branch_id');
        $wrappedName = $grammar->wrap('name');
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedBranch}, {$wrappedName}) WHERE {$predicate}");
    }

    private function createPublicUuidIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap('branch_stores_public_uuid_unique');
        $wrappedTable = $grammar->wrapTable('branch_stores');
        $wrappedColumn = $grammar->wrap('public_uuid');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn})");
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
    }
};
