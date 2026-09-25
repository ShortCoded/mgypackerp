<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\CustomerInvoiceLine;
use Modules\Sales\Models\SalesOrderLine;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoSharedHistoricalDeliveries();

        Schema::create('sales_issue_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->unique()->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('branch_store_id')->nullable()->constrained('branch_stores')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'doc_num']);
            $table->index(['company_id', 'branch_id', 'branch_store_id', 'status'], 'sales_issue_orders_lookup');
        });

        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->foreignId('sales_issue_order_id')->nullable()->after('customer_id')->constrained('sales_issue_orders')->restrictOnDelete();
        });

        Schema::create('sales_delivery_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('inventory_document_id')->unique()->constrained('inventory_documents')->restrictOnDelete();
            $table->string('recipient_name', 160);
            $table->string('recipient_phone', 80)->nullable();
            $table->timestamp('received_at');
            $table->string('signature_path');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'doc_num']);
        });

        DB::table('customer_invoices')
            ->where('document_type', 'invoice')
            ->where('posting_status', 'posted')
            ->whereNull('deleted_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('customer_invoice_lines')
                ->whereColumn('customer_invoice_lines.customer_invoice_id', 'customer_invoices.id')
                ->where('is_service', false))
            ->orderBy('id')
            ->chunkById(200, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $invoiceLines = DB::table('customer_invoice_lines')
                        ->where('customer_invoice_id', $invoice->id)->where('is_service', false)
                        ->get(['id', 'sales_order_line_id', 'quantity']);
                    $delivered = DB::table('inventory_document_lines as line')
                        ->join('customer_invoice_deliveries as link', 'link.inventory_document_id', '=', 'line.inventory_document_id')
                        ->join('inventory_documents as document', 'document.id', '=', 'line.inventory_document_id')
                        ->where('link.customer_invoice_id', $invoice->id)
                        ->where('document.status', 'posted')
                        ->whereNull('document.deleted_at')
                        ->select('line.source_line_type', 'line.source_line_id')
                        ->selectRaw('sum(line.transaction_quantity) as delivered_quantity')
                        ->groupBy('line.source_line_type', 'line.source_line_id')
                        ->get()->keyBy(fn ($row): string => $row->source_line_type.':'.$row->source_line_id);
                    $invoiceQuantities = [];
                    foreach ($invoiceLines as $line) {
                        $sourceType = $line->sales_order_line_id
                            ? SalesOrderLine::class
                            : CustomerInvoiceLine::class;
                        $sourceId = $line->sales_order_line_id ?: $line->id;
                        $key = $sourceType.':'.$sourceId;
                        $invoiceQuantities[$key] = bcadd($invoiceQuantities[$key] ?? '0', (string) $line->quantity, 8);
                    }
                    $complete = collect($invoiceQuantities)->every(function (string $quantity, string $key) use ($delivered): bool {
                        $deliveredQuantity = $delivered->get($key)?->delivered_quantity ?? '0';

                        return bccomp((string) $deliveredQuantity, $quantity, 8) >= 0;
                    });

                    $issueOrderId = DB::table('sales_issue_orders')->insertGetId([
                        'doc_num' => 'SIO-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT),
                        'company_id' => $invoice->company_id,
                        'financial_period_id' => $invoice->financial_period_id,
                        'branch_id' => $invoice->branch_id,
                        'customer_invoice_id' => $invoice->id,
                        'branch_store_id' => DB::table('sales_orders')->where('id', $invoice->sales_order_id)->value('branch_store_id'),
                        'status' => $complete ? 'issued' : 'pending',
                        'created_by' => $invoice->issued_by,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $linkedDocumentIds = DB::table('customer_invoice_deliveries as link')
                        ->join('inventory_documents as document', 'document.id', '=', 'link.inventory_document_id')
                        ->where('link.customer_invoice_id', $invoice->id)
                        ->where('document.document_type', 'sales_delivery')
                        ->where('document.status', 'posted')
                        ->whereNull('document.sales_issue_order_id')
                        ->pluck('document.id');
                    foreach ($linkedDocumentIds as $documentId) {
                        if (DB::table('customer_invoice_deliveries')->where('inventory_document_id', $documentId)->count() === 1) {
                            DB::table('inventory_documents')->where('id', $documentId)->update(['sales_issue_order_id' => $issueOrderId]);
                        }
                    }
                }
            });
    }

    public function down(): void
    {
        if (DB::table('sales_issue_orders')->exists()
            || DB::table('sales_delivery_receipts')->exists()
            || DB::table('inventory_documents')->whereNotNull('sales_issue_order_id')->exists()) {
            throw new RuntimeException('Sales issue orders and signed customer receipts contain business records; this migration cannot be rolled back.');
        }

        Schema::dropIfExists('sales_delivery_receipts');
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sales_issue_order_id');
        });
        Schema::dropIfExists('sales_issue_orders');
    }

    public function assertNoSharedHistoricalDeliveries(): void
    {
        $hasSharedDelivery = DB::table('customer_invoice_deliveries')
            ->select('inventory_document_id')
            ->groupBy('inventory_document_id')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasSharedDelivery) {
            throw new RuntimeException('Historical delivery documents linked to multiple invoices must be reconciled before installing sales issue orders.');
        }
    }
};
