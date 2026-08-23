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
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('authorized_signatory_name')->nullable()->after('commercial_name');
            $table->string('authorized_signatory_title')->nullable()->after('authorized_signatory_name');
            $table->foreignId('company_stamp_archive_file_id')
                ->nullable()
                ->after('favicon')
                ->constrained('archive_files')
                ->nullOnDelete();
            $table->foreignId('authorized_signatory_signature_archive_file_id')
                ->nullable()
                ->after('company_stamp_archive_file_id')
                ->constrained('archive_files')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['authorized_signatory_signature_archive_file_id']);
            $table->dropForeign(['company_stamp_archive_file_id']);
            $table->dropColumn([
                'authorized_signatory_name',
                'authorized_signatory_title',
                'company_stamp_archive_file_id',
                'authorized_signatory_signature_archive_file_id',
            ]);
        });
    }
};
