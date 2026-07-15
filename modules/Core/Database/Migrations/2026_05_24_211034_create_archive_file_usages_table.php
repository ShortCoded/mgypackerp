<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('archive_file_usages')) {
            Schema::create('archive_file_usages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('archive_file_id');
                $table->string('usable_type');
                $table->unsignedBigInteger('usable_id');
                $table->string('company_attachable_type')->nullable();
                $table->unsignedBigInteger('company_attachable_id')->nullable();
                $table->string('collection');
                $table->string('role')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();

                $table->foreign('archive_file_id')
                    ->references('id')
                    ->on('archive_files')
                    ->restrictOnDelete();

                $table->index('archive_file_id', 'archive_file_usages_file_index');
                $table->index(['usable_type', 'usable_id'], 'archive_file_usages_usable_index');
                $table->index(['company_attachable_type', 'company_attachable_id'], 'archive_file_usages_company_attachable_index');
                $table->index('collection', 'archive_file_usages_collection_index');
                $table->index('role', 'archive_file_usages_role_index');
                $table->index(['archive_file_id', 'usable_type', 'usable_id', 'collection', 'role'], 'archive_file_usages_file_usable_collection_role_index');
            });
        }

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createSingleRoleUniqueIndex(),
            default => null,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('archive_file_usages') && in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('archive_file_usages_single_role_unique_active'));
        }

        Schema::dropIfExists('archive_file_usages');
    }

    private function createSingleRoleUniqueIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('archive_file_usages');
        $index = $grammar->wrap('archive_file_usages_single_role_unique_active');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$index.' ON '.$table.' ('
            .$grammar->wrap('usable_type').', '
            .$grammar->wrap('usable_id').', '
            .$grammar->wrap('collection').', '
            .$grammar->wrap('role')
            .') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('role').' IS NOT NULL');
    }
};
