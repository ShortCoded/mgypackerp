<?php

namespace Modules\Production\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Production\Models\ProductionRun;

class ProductionCostService
{
    /** @return array{issued: string, returned: string, waste: string, capitalizable: string, finished_goods: string, wip: string} */
    public function runPosition(ProductionRun $run): array
    {
        $issued = $this->documentCost($run, [
            InventoryDocument::TypeMaterialIssue,
            InventoryDocument::TypeAdditionalMaterialIssue,
        ]);
        $returned = $this->documentCost($run, [InventoryDocument::TypeMaterialReturn]);
        $waste = $this->documentCost($run, [InventoryDocument::TypeProductionWaste]);
        $finishedGoods = $this->documentCost($run, [InventoryDocument::TypeProductionReceipt]);
        $capitalizable = bcsub(bcsub($issued, $returned, 8), $waste, 8);

        return [
            'issued' => $issued,
            'returned' => $returned,
            'waste' => $waste,
            'capitalizable' => $capitalizable,
            'finished_goods' => $finishedGoods,
            'wip' => bcsub($capitalizable, $finishedGoods, 8),
        ];
    }

    public function receiptCost(ProductionRun $run, string $receiptBaseQuantity): string
    {
        $position = $this->runPosition($run);
        $remainingGood = bcsub((string) $run->good_base_quantity, (string) $run->received_base_quantity, 8);

        if (bccomp($receiptBaseQuantity, $remainingGood, 8) === 0) {
            return $position['wip'];
        }

        return bcdiv(
            bcmul($position['capitalizable'], $receiptBaseQuantity, 8),
            (string) $run->good_base_quantity,
            8,
        );
    }

    /** @param list<string> $documentTypes */
    private function documentCost(ProductionRun $run, array $documentTypes): string
    {
        $cost = DB::table('inventory_document_lines')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->where('inventory_documents.company_id', $run->company_id)
            ->where('inventory_documents.production_run_id', $run->getKey())
            ->whereIn('inventory_documents.document_type', $documentTypes)
            ->where('inventory_documents.status', InventoryDocument::StatusPosted)
            ->whereNull('inventory_document_lines.deleted_at')
            ->sum('inventory_document_lines.total_cost');

        return bcadd((string) $cost, '0', 8);
    }
}
