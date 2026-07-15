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
        if (! Schema::hasColumn('archive_files', 'hidden_from_picker')) {
            Schema::table('archive_files', function (Blueprint $table): void {
                $table->boolean('hidden_from_picker')->default(false)->index()->after('description');
            });
        }

        if (! Schema::hasColumn('archive_folders', 'hidden_from_picker')) {
            Schema::table('archive_folders', function (Blueprint $table): void {
                $table->boolean('hidden_from_picker')->default(false)->index()->after('path_cache');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('archive_files', 'hidden_from_picker')) {
            Schema::table('archive_files', function (Blueprint $table): void {
                $table->dropColumn('hidden_from_picker');
            });
        }

        if (Schema::hasColumn('archive_folders', 'hidden_from_picker')) {
            Schema::table('archive_folders', function (Blueprint $table): void {
                $table->dropColumn('hidden_from_picker');
            });
        }
    }
};
