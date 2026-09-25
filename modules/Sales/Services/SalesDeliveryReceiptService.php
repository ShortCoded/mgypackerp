<?php

namespace Modules\Sales\Services;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesDeliveryReceipt;
use Throwable;

class SalesDeliveryReceiptService
{
    public function __construct(private readonly SalesCycleAuditService $audit) {}

    /** @param array{recipient_name: string, recipient_phone?: string|null, notes?: string|null} $data */
    public function record(InventoryDocument $issue, array $data, UploadedFile $signature): SalesDeliveryReceipt
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($issue, $data, $signature, &$storedPath): SalesDeliveryReceipt {
                $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($issue->getKey());
                if ($locked->document_type !== InventoryDocument::TypeSalesDelivery
                    || $locked->status !== InventoryDocument::StatusPosted
                    || $locked->sales_issue_order_id === null
                    || $locked->customerDeliveryReceipt()->exists()) {
                    throw new DomainException(__('sales_issue.messages.receipt_not_eligible'));
                }

                $issueOrder = $locked->salesIssueOrder()->with('invoice')->firstOrFail();
                $invoice = $issueOrder->invoice;
                if ($invoice->document_type !== CustomerInvoice::TypeInvoice
                    || $invoice->posting_status !== CustomerInvoice::StatusPosted
                    || (int) $invoice->company_id !== (int) $locked->company_id) {
                    throw new DomainException(__('sales_issue.messages.receipt_not_eligible'));
                }

                $storedPath = $signature->store('sales/delivery-receipts/'.$locked->company_id, 'local');
                if (! $storedPath) {
                    throw new DomainException(__('sales_issue.messages.signature_upload_failed'));
                }

                $receipt = SalesDeliveryReceipt::query()->create([
                    'doc_num' => 'SDR-'.str_pad((string) $locked->getKey(), 6, '0', STR_PAD_LEFT),
                    'company_id' => $locked->company_id,
                    'branch_id' => $invoice->branch_id,
                    'customer_invoice_id' => $invoice->getKey(),
                    'inventory_document_id' => $locked->getKey(),
                    'recipient_name' => trim($data['recipient_name']),
                    'recipient_phone' => $data['recipient_phone'] ?? null,
                    'received_at' => now(),
                    'signature_path' => $storedPath,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]);
                $this->audit->record($receipt, 'sales_delivery_receipt.recorded', ['stock_issue' => $locked->doc_num]);

                return $receipt;
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }
}
