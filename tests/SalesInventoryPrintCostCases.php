<?php

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryDocument;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/SalesCycleSupport.php';

test('inventory movement and sales delivery print variants omit every cost field and preserve valuation', function (string $type, string $locale): void {
    $f = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    foreach (['inventory.documents.view', 'inventory.documents.print', 'sales_deliveries.view', 'sales_deliveries.print'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $f['user']->givePermissionTo($permission);
    }
    $this->actingAs($f['user'])->withSession([...salesCycleSession($f), 'locale' => $locale]);
    app()->setLocale($locale);
    $document = InventoryDocument::query()->create(['doc_number' => 88001, 'doc_num' => 'SYNTHETIC-PRINT-'.$f['company']->id.'-88001',
        'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
        'branch_store_id' => $f['store']->id, 'document_type' => $type, 'document_date' => now()->toDateString(), 'status' => InventoryDocument::StatusDraft]);
    $line = $document->lines()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'line_number' => 1, 'product_id' => $f['finished']->id, 'unit_id' => $f['unit']->id,
        'quantity' => '2', 'transaction_quantity' => '2', 'base_quantity' => '2', 'unit_cost' => '123.4567', 'total_cost' => '246.9134']);
    $state = [$line->fresh()->getAttributes(), DB::table('journal_entries')->count(), DB::table('inventory_transactions')->count()];
    $response = $this->get(route('admin.inventory.documents.print', $document))->assertOk()->assertHeader('content-type', 'application/pdf');
    if ($type === InventoryDocument::TypeSalesDelivery) {
        file_put_contents('/tmp/mgypack-no-cost-sales-print-'.$locale.'-20261004.pdf', $response->getContent());
    }
    $document->load(['lines.product', 'lines.unit']);
    $payload = ['record' => $document, 'direction' => $locale === 'ar' ? 'rtl' : 'ltr', 'reportTitle' => 'Print test', 'companyName' => $f['company']->name, 'companyLogoPath' => null, 'companyPrintIdentity' => [], 'branding' => [], 'generatedAt' => now(), 'printIdentityPolicy' => 'none'];
    foreach (['reports.inventory.document', 'modules.inventory.documents.print'] as $view) {
        $html = view($view, $payload)->render();
        expect($html)->not->toContain('123.4567')->not->toContain('246.9134')->not->toContain(__('inventory.movements.fields.unit_cost'))->not->toContain(__('inventory.movements.receipt_pricing_total'));
    }
    if ($type === InventoryDocument::TypeSalesDelivery) {
        $salesPdf = $this->get(route('admin.sales.delivery-notes.print', $document))->assertOk()->assertHeader('content-type', 'application/pdf');
        expect(salesPdfText($salesPdf->getContent()))->not->toContain('123.4567')->not->toContain('246.9134');
    }
    $text = salesPdfText($response->getContent());
    expect($text)->not->toContain('123.4567')->not->toContain('246.9134')->not->toContain('246.91');
    if ($locale === 'en') {
        expect($text)->not->toContain(__('inventory.movements.fields.unit_cost'))->not->toContain(__('inventory.movements.receipt_pricing_total'));
    }
    expect([$line->fresh()->getAttributes(), DB::table('journal_entries')->count(), DB::table('inventory_transactions')->count()])->toBe($state);
})->with([InventoryDocument::TypeSalesDelivery, InventoryDocument::TypeSalesReturnReceipt, InventoryDocument::TypeIssue, InventoryDocument::TypeMaterialIssue, InventoryDocument::TypeAdditionalMaterialIssue, InventoryDocument::TypeMaterialReturn, InventoryDocument::TypeMaterialConsumption, InventoryDocument::TypeMaintenanceMaterialIssue, InventoryDocument::TypeMaintenanceMaterialReturn, InventoryDocument::TypeProductionWaste, InventoryDocument::TypeTransfer, InventoryDocument::TypeReceipt, InventoryDocument::TypeReturn, InventoryDocument::TypeAdjustmentIn, InventoryDocument::TypeAdjustmentOut, InventoryDocument::TypeDamage, InventoryDocument::TypeScrap, InventoryDocument::TypeProductionReceipt])->with(['en', 'ar']);

test('raw material production issue PDFs omit every cost column and preserve stored cost', function (): void {
    $f = salesCycleFixture(isolatedCompany: DB::getDriverName() === 'pgsql');
    $document = InventoryDocument::query()->create(['doc_number' => 88002, 'doc_num' => 'SYNTHETIC-PRINT-'.$f['company']->id.'-88002',
        'company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'branch_id' => $f['branch']->id,
        'branch_store_id' => $f['store']->id, 'document_type' => InventoryDocument::TypeMaterialIssue, 'document_date' => now()->toDateString(), 'status' => InventoryDocument::StatusDraft]);
    $document->lines()->create(['company_id' => $f['company']->id, 'financial_period_id' => $f['period']->id, 'line_number' => 1, 'product_id' => $f['raw']->id, 'unit_id' => $f['unit']->id,
        'quantity' => '2', 'transaction_quantity' => '2', 'base_quantity' => '2', 'unit_cost' => '123.4567', 'total_cost' => '246.9134']);
    $document->load(['lines.product', 'lines.unit']);
    $html = view('reports.inventory.document', ['record' => $document, 'direction' => 'ltr', 'reportTitle' => 'Print test', 'companyName' => $f['company']->name, 'companyLogoPath' => null, 'companyPrintIdentity' => [], 'branding' => [], 'generatedAt' => now(), 'printIdentityPolicy' => 'none'])->render();
    expect($html)->not->toContain('123.4567')->not->toContain('246.9134')->not->toContain(__('inventory.movements.fields.unit_cost'))->not->toContain(__('inventory.movements.receipt_pricing_total'));
    expect($document->lines->sole()->unit_cost)->toBe('123.45670000')->and($document->lines->sole()->total_cost)->toBe('246.91340000');
});
