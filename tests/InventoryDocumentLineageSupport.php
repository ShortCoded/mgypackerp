<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Production\Services\ProductionMaterialRequestService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/ManufacturingInventorySupport.php';

/** @return array<string, mixed> */
function inventoryDocumentLineageFixture(): array
{
    test()->travelTo('2026-09-28 10:00:00');
    $fixture = manufacturingInventoryFixture('-LINEAGE-'.Str::random(8), isolatedCompany: DB::getDriverName() === 'pgsql');
    $fixture['branch']->update(['type' => Branch::TypeFactory]);
    foreach (['inventory.documents.view', 'inventory.documents.print', 'production.material_requests.view', 'production.runs.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    test()->actingAs($fixture['user'])->withSession(manufacturingIntegritySession($fixture));
    $details = manufacturingIntegrityRun($fixture);
    $requirement = $details['run']->requirements->sole();
    $materials = app(ProductionMaterialRequestService::class);
    $materialRequest = $materials->approve($materials->create($details['run'], $fixture['store']->id, [$requirement->id => '2']));
    $requestLine = $materialRequest->fresh()->lines->sole();
    $document = $materials->issue($materialRequest, [$requestLine->id => '2']);
    $line = $document->fresh()->lines->sole();

    return [...$fixture, ...$details, 'requirement' => $requirement, 'materialRequest' => $materialRequest,
        'requestLine' => $requestLine, 'document' => $document, 'line' => $line, 'reservation' => $line->reservation];
}
