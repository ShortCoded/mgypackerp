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
        Schema::create('archive_public_links', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 128)->unique();
            $table->text('token');
            $table->nullableMorphs('linkable');
            $table->string('linkable_doc_num')->index();
            $table->string('item_type', 20)->index();
            $table->boolean('allow_preview')->default(true);
            $table->boolean('allow_download')->default(false);
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createActiveUniqueIndex(),
            default => null,
        };
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('archive_public_links_linkable_active_unique')),
            default => null,
        };

        Schema::dropIfExists('archive_public_links');
    }

    private function createActiveUniqueIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('archive_public_links');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('archive_public_links_linkable_active_unique')." ON {$table} (".$grammar->wrap('linkable_type').', '.$grammar->wrap('linkable_id').') WHERE '.$grammar->wrap('revoked_at').' IS NULL');
    }
};
