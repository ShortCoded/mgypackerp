<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_employees', 'signature_archive_file_id')) {
                $table->foreignId('signature_archive_file_id')
                    ->nullable()
                    ->after('photo_archive_file_id')
                    ->constrained('archive_files')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (Schema::hasColumn('hr_employees', 'signature_archive_file_id')) {
                $table->dropForeign(['signature_archive_file_id']);
                $table->dropColumn('signature_archive_file_id');
            }
        });
    }
};
