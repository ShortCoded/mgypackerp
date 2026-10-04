<?php

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Services\InventoryCostPolicyService;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Inventory\Services\InventoryMovementService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function manualCorrectionFixture(bool $serial = false, ?string $initialCost = '2', ?string $costMethod = null): array
{
    Carbon::setTestNow('2026-09-28 12:00:00');
    CarbonImmutable::setTestNow('2026-09-28 12:00:00');
    $suffix = '-MANUAL-CORRECTION-'.((int) Company::withTrashed()->max('doc_number') + 1);
    $f = manufacturingInventoryFixture($suffix, true);
    $f['finished']->update(['item_classification' => Product::ClassificationRawMaterial]);
    $f['reviewer'] = closureSyntheticUser();
    $permissions = collect(['view', 'correct_prepare', 'correct_approve', 'correct_later_period'])->map(fn ($name) => Permission::findOrCreate('inventory.documents.'.$name, 'web'));
    $f['user']->givePermissionTo($permissions);
    $f['reviewer']->givePermissionTo($permissions);
    if ($serial) {
        $f['finished']->update(['tracks_serials' => true]);
    }
    if ($costMethod !== null) {
        $f['store'] = BranchStore::query()->create(['branch_id' => $f['branch']->id, 'name' => 'SYNTHETIC selected-cost correction store', 'position' => 2]);
        $f['user']->givePermissionTo(Permission::findOrCreate('inventory.cost_policies.manage', 'web'));
        app(InventoryCostPolicyService::class)->createVersion($f['company']->id, [
            'branch_store_id' => $f['store']->id, 'method' => $costMethod,
            'effective_from' => $f['period']->from_date->toDateString(), 'reason' => 'SYNTHETIC explicit selected-cost policy',
        ], $f['user']->id);
    }
    $f['receipt'] = manualCorrectionMovement($f, InventoryDocument::TypeReceipt, $serial ? '1' : '10', $initialCost, $serial ? ['serial_number' => 'SYNTHETIC-MANUAL-0001'] : []);

    return $f;
}

function manualCorrectionMovement(array $f, string $type, string $quantity, ?string $cost = null, array $line = [], array $header = []): InventoryDocument
{
    return app(InventoryMovementService::class)->createAndPost(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'branch_store_id' => $f['store']->id, 'document_type' => $type,
        'document_date' => '2026-09-28', ...$header], [['product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id,
            'quantity' => $quantity, 'unit_cost' => $cost, ...$line]]);
}

function manualCorrectionActor(array $f, User $actor): void
{
    auth()->login($actor);
    request()->setUserResolver(fn () => $actor);
    request()->session()->put(manufacturingIntegritySession(['company' => $f['company'], 'branch' => $f['branch'], 'period' => $f['target'] ?? $f['period']]));
    test()->actingAs($actor)->withSession(request()->session()->all());
}

function manualCorrectionClose(array $f): array
{
    $f['period']->update(['is_closed' => true]);
    $number = (int) FinancialPeriod::withTrashed()->max('doc_number') + 1;
    $f['target'] = FinancialPeriod::query()->create(['company_id' => $f['company']->id, 'doc_number' => $number,
        'doc_num' => 'SYNTHETIC-MANUAL-CORRECTION-OCT-'.$number, 'name' => 'SYNTHETIC October manual corrections',
        'from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'is_closed' => false]);
    Carbon::setTestNow('2026-10-03 12:00:00');
    CarbonImmutable::setTestNow('2026-10-03 12:00:00');
    manualCorrectionActor($f, $f['user']);

    return $f;
}

function manualCorrectionPayload(array $f, InventoryDocument $document, string $operation = 'replace', string $quantity = '6', string $cost = '3'): array
{
    return ['source_fingerprint' => app(InventoryMovementCorrectionService::class)->preview($document)['source_fingerprint'],
        'operation' => $operation, 'posting_date' => isset($f['target']) ? '2026-10-02' : '2026-09-28', 'reason' => 'SYNTHETIC correct manual inventory quantity and cost',
        'lines' => [['line_id' => $document->lines->sole()->id, 'quantity' => $quantity, 'unit_cost' => $cost]]];
}

function manualCorrectionExecute(array $f, InventoryDocument $document, string $operation = 'reverse', string $quantity = '6', string $cost = '3'): InventoryMovementCorrection
{
    manualCorrectionActor($f, $f['user']);
    $proposal = app(InventoryMovementCorrectionService::class)->prepare($document, manualCorrectionPayload($f, $document, $operation, $quantity, $cost));
    manualCorrectionActor($f, $f['reviewer']);

    return app(InventoryMovementCorrectionService::class)->approve($document, $proposal->id, 'SYNTHETIC independent reviewer verified source and replacement');
}
