<?php

use App\Models\User;
use App\Services\PostingAccountResolver;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReceiptCostProposal;
use Modules\Inventory\Services\InventoryReceiptCostProposalService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryCostTransitionSupport.php';
require_once __DIR__.'/ManufacturingInventorySupport.php';

function receiptCompletionFixture(bool $isolatedCompany = true): array
{
    $fixture = costTransitionFixture(isolatedCompany: $isolatedCompany);
    $fixture['product']->update(['item_classification' => Product::ClassificationRawMaterial]);
    foreach (['inventory.documents.view', 'inventory.documents.propose_receipt_cost', 'inventory.documents.approve_receipt_cost'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $fixture['preparer']->givePermissionTo(['inventory.documents.view', 'inventory.documents.propose_receipt_cost']);
    $fixture['approver']->givePermissionTo(['inventory.documents.view', 'inventory.documents.approve_receipt_cost']);
    $fixture['counterpart'] = app(PostingAccountResolver::class)->resolve($fixture['company']->id,
        PostingAccountResolver::InventoryAdjustmentGain, 'SYNTHETIC approved cost source');
    test()->actingAs($fixture['preparer']);
    request()->setUserResolver(fn (): User => $fixture['preparer']);

    return $fixture;
}

function receiptCompletionPrepare(array $fixture, InventoryDocument $receipt, string $cost, string $date): InventoryReceiptCostProposal
{
    return app(InventoryReceiptCostProposalService::class)->prepare(request(), $receipt, [
        'basis' => InventoryReceiptCostProposal::BasisEstimate, 'source_reference' => 'SYNTHETIC costing evidence',
        'basis_note' => 'SYNTHETIC isolated independent estimate', 'posting_date' => $date,
        'counterpart_account_id' => $fixture['counterpart']->id,
    ], [$receipt->lines()->sole()->id => $cost], null);
}

function receiptCompletionApprove(array $fixture, InventoryDocument $receipt, InventoryReceiptCostProposal $proposal): void
{
    test()->actingAs($fixture['approver']);
    request()->setUserResolver(fn (): User => $fixture['approver']);
    app(InventoryReceiptCostProposalService::class)->approve(request(), $receipt, $proposal,
        'SYNTHETIC costing evidence', 'SYNTHETIC independent accounting approval');
}
