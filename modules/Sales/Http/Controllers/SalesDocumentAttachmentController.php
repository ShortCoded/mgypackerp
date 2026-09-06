<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Services\ArchiveFileUsageService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Models\SalesReturn;

class SalesDocumentAttachmentController extends Controller
{
    public function store(Request $request, string $kind, string $document, OperatingContextService $context, FilePickerService $picker, ArchiveFileUsageService $usages): JsonResponse
    {
        $profile = match ($kind) {
            'sales_request' => [SalesRequest::class, 'sales_requests.edit'],
            'sales_order' => [SalesOrder::class, 'sales_orders.edit'],
            'invoice', 'credit_note' => [CustomerInvoice::class, 'customer_invoices.edit'],
            'customer_receipt' => [CustomerReceipt::class, 'customer_receipts.create'],
            'sales_delivery' => [InventoryDocument::class, 'sales_deliveries.create'],
            'sales_return' => [SalesReturn::class, 'sales_returns.create'],
            'customer' => [Customer::class, 'customers.edit'],
            default => abort(404),
        };
        abort_unless($request->user()?->can($profile[1]) && $request->user()?->can('file_manager.view'), 403);
        $data = $request->validate(['attachment_doc_nums' => ['required', 'array', 'min:1', 'max:20'], 'attachment_doc_nums.*' => ['required', 'string', 'max:160', 'distinct']]);
        $scope = $context->snapshot($request);

        return DB::transaction(function () use ($profile, $kind, $document, $data, $scope, $picker, $usages): JsonResponse {
            $query = $profile[0]::query()->where('company_id', $scope['company_id'])->where('doc_num', $document);
            if ($kind !== 'customer') {
                $query->where('branch_id', $scope['branch_id']);
            }
            if ($kind === 'sales_delivery') {
                $query->where('document_type', InventoryDocument::TypeSalesDelivery);
            }
            $record = $query->lockForUpdate()->firstOrFail();
            foreach ($data['attachment_doc_nums'] as $publicId) {
                $file = $picker->selectableFileByPublicId($publicId, (int) $scope['company_id'], FilePickerService::AcceptDocument);
                abort_unless($file instanceof ArchiveFile, 422, __('The selected attachment is unavailable.'));
                if (! ArchiveFileUsage::query()->whereMorphedTo('usable', $record)->where('collection', 'sales_documents')->where('archive_file_id', $file->id)->exists()) {
                    $usages->attachFileToRecord($file, $record, 'sales_documents');
                }
            }

            return response()->json(['message' => __('Saved successfully.')]);
        });
    }
}
