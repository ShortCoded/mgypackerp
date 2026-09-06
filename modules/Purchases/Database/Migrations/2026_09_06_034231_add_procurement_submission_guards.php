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
        Schema::create('document_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('operation', 180);
            $table->uuid('token');
            $table->char('payload_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('response_headers')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'operation', 'token'], 'document_submission_identity');
        });

        DB::statement("CREATE UNIQUE INDEX purchase_invoice_supplier_reference_unique ON purchase_invoices (company_id, supplier_id, LOWER(TRIM(supplier_invoice_number))) WHERE deleted_at IS NULL AND status <> 'cancelled' AND supplier_invoice_number IS NOT NULL AND TRIM(supplier_invoice_number) <> ''");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropIndex('purchase_invoice_supplier_reference_unique'));
        Schema::dropIfExists('document_submissions');
    }
};
