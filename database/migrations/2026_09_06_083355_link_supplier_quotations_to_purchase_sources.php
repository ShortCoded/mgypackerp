<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->foreignId('request_for_quotation_id')->nullable()->change();
            $table->foreignId('purchase_requisition_id')->nullable()->after('request_for_quotation_id')->constrained('purchase_requisitions')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->after('purchase_requisition_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('source_type', 40)->nullable()->after('purchase_order_id');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_doc_num', 100)->nullable()->after('source_id');
            $table->index(['company_id', 'source_type', 'source_id', 'status'], 'supplier_quotations_source_status_index');
        });

        Schema::table('supplier_quotation_lines', function (Blueprint $table): void {
            $table->foreignId('request_for_quotation_line_id')->nullable()->change();
            $table->foreignId('purchase_requisition_line_id')->nullable()->after('request_for_quotation_line_id')->constrained('purchase_requisition_lines')->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->after('purchase_requisition_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->unique(['supplier_quotation_id', 'purchase_requisition_line_id'], 'supplier_quotation_lines_quote_pr_line_unique');
            $table->unique(['supplier_quotation_id', 'purchase_order_line_id'], 'supplier_quotation_lines_quote_po_line_unique');
        });

        DB::table('supplier_quotations')
            ->whereNotNull('request_for_quotation_id')
            ->orderBy('id')
            ->eachById(function (object $quotation): void {
                $source = DB::table('request_for_quotations')->where('id', $quotation->request_for_quotation_id)->first(['id', 'doc_num']);
                if ($source) {
                    DB::table('supplier_quotations')->where('id', $quotation->id)->update([
                        'source_type' => 'request_for_quotation',
                        'source_id' => $source->id,
                        'source_doc_num' => $source->doc_num,
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('supplier_quotations')->whereNull('request_for_quotation_id')->delete();

        Schema::table('supplier_quotation_lines', function (Blueprint $table): void {
            $table->dropUnique('supplier_quotation_lines_quote_po_line_unique');
            $table->dropUnique('supplier_quotation_lines_quote_pr_line_unique');
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropConstrainedForeignId('purchase_requisition_line_id');
            $table->foreignId('request_for_quotation_line_id')->nullable(false)->change();
        });

        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->dropIndex('supplier_quotations_source_status_index');
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropConstrainedForeignId('purchase_requisition_id');
            $table->dropColumn(['source_type', 'source_id', 'source_doc_num']);
            $table->foreignId('request_for_quotation_id')->nullable(false)->change();
        });
    }
};
