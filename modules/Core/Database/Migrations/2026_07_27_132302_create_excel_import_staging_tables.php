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
        Schema::create('excel_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->string('module', 40)->index();
            $table->string('template_version', 20);
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->json('context_snapshot');
            $table->string('original_filename', 255);
            $table->string('private_path', 500);
            $table->string('checksum', 64);
            $table->string('status', 20)->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('created_result_count')->default(0);
            $table->json('result_summary')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['owner_user_id', 'module', 'status']);
            $table->index(['company_id', 'module', 'status']);
        });

        Schema::create('excel_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('excel_import_batches')->cascadeOnDelete();
            $table->string('sheet_key', 60);
            $table->unsignedInteger('excel_row');
            $table->string('status', 20)->index();
            $table->string('import_key', 80)->nullable();
            $table->json('data');
            $table->json('normalized_data')->nullable();
            $table->json('issues')->nullable();
            $table->string('created_doc_num', 120)->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'sheet_key', 'excel_row']);
            $table->index(['batch_id', 'status', 'id']);
            $table->index(['batch_id', 'sheet_key', 'excel_row']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('excel_import_rows');
        Schema::dropIfExists('excel_import_batches');
    }
};
