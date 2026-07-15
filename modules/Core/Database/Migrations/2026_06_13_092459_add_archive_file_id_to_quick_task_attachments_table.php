<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('quick_task_attachments') || Schema::hasColumn('quick_task_attachments', 'archive_file_id')) {
            return;
        }

        Schema::table('quick_task_attachments', function (Blueprint $table): void {
            $table->foreignId('archive_file_id')
                ->nullable()
                ->after('quick_task_id')
                ->constrained('archive_files')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('quick_task_attachments') || ! Schema::hasColumn('quick_task_attachments', 'archive_file_id')) {
            return;
        }

        Schema::table('quick_task_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archive_file_id');
        });
    }
};
