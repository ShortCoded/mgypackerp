<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;

class InventoryDocumentPostingService
{
    public function __construct(private readonly InventoryAvailabilityService $availability) {}

    public function post(InventoryDocument $document): InventoryDocument
    {
        return DB::transaction(function () use ($document): InventoryDocument {
            $locked = InventoryDocument::query()->with('lines')->lockForUpdate()->findOrFail($document->getKey());
            if ($locked->status === InventoryDocument::StatusPosted) {
                return $locked;
            }
            if ($locked->status !== InventoryDocument::StatusDraft) {
                throw new DomainException('Only a draft inventory document can be posted.');
            }

            BranchStore::query()->lockForUpdate()->findOrFail($locked->branch_store_id);
            foreach ($locked->lines as $line) {
                Product::query()->lockForUpdate()->findOrFail($line->product_id);
                $isOutbound = $locked->document_type === InventoryDocument::TypeSalesDelivery;
                $quantity = (string) $line->quantity;
                if (bccomp($quantity, '0', 8) <= 0) {
                    continue;
                }
                $postingKey = "inventory-document:{$locked->id}:line:{$line->id}";
                if (InventoryTransaction::query()->where('posting_key', $postingKey)->exists()) {
                    continue;
                }

                $unitCost = (string) ($line->unit_cost ?: $this->availability->averageCost((int) $locked->company_id, (int) $locked->branch_store_id, (int) $line->product_id));
                if ($isOutbound) {
                    $available = $this->availability->forProduct((int) $locked->company_id, (int) $locked->branch_store_id, (int) $line->product_id)['on_hand'];
                    if (bccomp($quantity, $available, 8) > 0) {
                        throw new DomainException('The delivery exceeds physical stock on hand.');
                    }
                }

                InventoryTransaction::query()->create([
                    'posting_key' => $postingKey, 'company_id' => $locked->company_id,
                    'financial_period_id' => $locked->financial_period_id, 'branch_id' => $locked->branch_id,
                    'branch_store_id' => $locked->branch_store_id, 'transaction_date' => $locked->document_date,
                    'transaction_type' => $locked->document_type, 'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id, 'quantity_in' => $isOutbound ? 0 : $quantity,
                    'quantity_out' => $isOutbound ? $quantity : 0, 'source_type' => InventoryDocument::class,
                    'source_id' => $locked->getKey(), 'source_doc_num' => $locked->doc_num,
                    'source_line_type' => $line->source_line_type, 'source_line_id' => $line->source_line_id,
                    'customer_id' => $locked->customer_id, 'unit_cost' => $unitCost,
                    'total_cost' => bcmul($quantity, $unitCost, 8), 'created_by' => auth()->id(),
                ]);
                $line->update(['unit_cost' => $unitCost, 'total_cost' => bcmul($quantity, $unitCost, 8)]);
            }

            $locked->update(['status' => InventoryDocument::StatusPosted, 'is_closed' => true, 'closed_by' => auth()->id(), 'closed_at' => now(), 'updated_by' => auth()->id()]);

            return $locked->refresh()->load('lines');
        });
    }
}
