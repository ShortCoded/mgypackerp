<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Services\InventoryDocumentLineageService;

require_once __DIR__.'/InventoryDocumentLineageSupport.php';
require_once __DIR__.'/SalesCycleSupport.php';

test('native material issue view and PDF show item source references without exposed UUIDs in both locales', function (string $locale): void {
    $f = inventoryDocumentLineageFixture();
    $this->withSession(['locale' => $locale]);
    app()->setLocale($locale);
    $before = [$f['document']->fresh()->getAttributes(), $f['line']->fresh()->getAttributes(), $f['reservation']->fresh()->getAttributes(),
        DB::table('journal_entries')->count(), DB::table('inventory_transactions')->count()];
    $response = $this->get(route('admin.inventory.documents.show', $f['document']))->assertOk()
        ->assertSee('data-inventory-lineage', false)->assertSee($f['raw']->doc_num)->assertSee($f['raw']->name)
        ->assertSee($f['materialRequest']->doc_num)->assertSee($f['run']->run_number)
        ->assertDontSeeText($f['reservation']->public_id)->assertDontSeeText($f['requirement']->public_id);
    $html = $response->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    $chain = (new DOMXPath($dom))->query('//*[@data-inventory-source-chain]')->item(0)->textContent;
    $lineageText = (new DOMXPath($dom))->query('//*[@data-inventory-lineage]')->item(0)->textContent;
    expect($lineageText)->toContain($f['run']->run_number)->not->toContain('@else')
        ->not->toContain(__('inventory.movements.lineage.unavailable'));
    expect(strpos($chain, $f['order']->doc_num))->toBeLessThan(strpos($chain, $f['run']->run_number))
        ->and(strpos($chain, $f['run']->run_number))->toBeLessThan(strpos($chain, $f['materialRequest']->doc_num));
    $rows = app(InventoryDocumentLineageService::class)->forDocument($f['document']);
    expect($rows[$f['line']->id]['source_label'])->toContain($f['materialRequest']->doc_num)->toContain($f['raw']->doc_num)
        ->and($rows[$f['line']->id]['request_line'])->toBe(1)->and($rows[$f['line']->id]['requirement_line'])->toBe(1);
    $pdf = $this->get(route('admin.inventory.documents.print', $f['document']))->assertOk()->assertHeader('content-type', 'application/pdf');
    $text = salesPdfText($pdf->getContent());
    expect($text)->toContain($f['raw']->doc_num)->toContain($f['materialRequest']->doc_num)
        ->not->toContain($f['reservation']->public_id)->not->toContain($f['requirement']->public_id);
    $path = storage_path('app/test-artifacts/mgypack-final-issue-lineage-'.$locale.'-20261005.pdf');
    file_put_contents($path, $pdf->getContent());
    expect([$f['document']->fresh()->getAttributes(), $f['line']->fresh()->getAttributes(), $f['reservation']->fresh()->getAttributes(),
        DB::table('journal_entries')->count(), DB::table('inventory_transactions')->count()])->toBe($before);
})->with(['ar', 'en']);

test('material lineage hides foreign company and branch references and respects link permission', function (): void {
    $f = inventoryDocumentLineageFixture();
    $service = app(InventoryDocumentLineageService::class);
    $f['user']->revokePermissionTo('production.material_requests.view');
    expect($service->forDocument($f['document'])[$f['line']->id]['request_url'])->toBeNull();
    $f['user']->givePermissionTo('production.material_requests.view');
    $foreign = $f['reservation']->replicate(['public_id']);
    $foreign->public_id = (string) Str::uuid();
    $branch = Branch::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) Branch::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-LINEAGE-FOREIGN-'.Str::random(8), 'name' => 'SYNTHETIC foreign branch', 'type' => Branch::TypeFactory, 'status' => 'active']);
    $foreign->branch_id = $branch->id;
    $foreign->save();
    $f['line']->update(['inventory_reservation_id' => $foreign->id]);
    $row = $service->forDocument($f['document']->fresh())[$f['line']->id];
    expect($row['source_label'])->toBe(__('inventory.movements.lineage.unavailable'))->and($row['item_code'])->toBeNull();
    $company = Company::factory()->create(['doc_number' => (int) Company::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-LINEAGE-COMPANY-'.Str::random(8), 'name' => 'SYNTHETIC foreign company']);
    $foreign->update(['branch_id' => $f['branch']->id, 'company_id' => $company->id]);
    $row = $service->forDocument($f['document']->fresh())[$f['line']->id];
    expect($row['source_label'])->toBe(__('inventory.movements.lineage.unavailable'))->and($row['request_number'])->toBeNull();
    $this->withSession([OperatingContextService::BranchIdKey => $branch->id, OperatingContextService::BranchDocNumKey => $branch->doc_num]);
    $this->get(route('admin.inventory.documents.show', $f['document']))->assertNotFound();
});

test('material lineage keeps deleted item identity and never substitutes an unresolved source', function (): void {
    $f = inventoryDocumentLineageFixture();
    $service = app(InventoryDocumentLineageService::class);
    $f['raw']->delete();
    $row = $service->forDocument($f['document']->fresh())[$f['line']->id];
    expect($row['item_code'])->toBe($f['raw']->doc_num)->and($row['item_name'])->toBe($f['raw']->name);
    $f['materialRequest']->delete();
    $row = $service->forDocument($f['document']->fresh())[$f['line']->id];
    expect($row['request_number'])->toBeNull()->and($row['request_url'])->toBeNull()->and($row['run_number'])->toBe($f['run']->run_number);
    $f['line']->update(['inventory_reservation_id' => null, 'source_line_public_id' => (string) Str::uuid()]);
    $row = $service->forDocument($f['document']->fresh())[$f['line']->id];
    expect($row['source_label'])->toBe(__('inventory.movements.lineage.unavailable'))->and($row['item_code'])->toBeNull();
});

test('material lineage resolves many lines in stable order with a constant query budget', function (): void {
    $f = inventoryDocumentLineageFixture();
    $service = app(InventoryDocumentLineageService::class);
    $document = $f['document']->fresh()->load('lines');
    $service->forDocument($document);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $service->forDocument($document);
    $oneLineQueries = count(DB::getQueryLog());
    $lines = collect();
    foreach (range(20, 1) as $number) {
        $line = clone $document->lines->sole();
        $line->id = 900000 + $number;
        $line->line_number = $number;
        $lines->push($line);
    }
    $document->setRelation('lines', $lines);
    DB::flushQueryLog();
    $rows = $service->forDocument($document);
    $manyLineQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect(array_column($rows, 'line_number'))->toBe(range(1, 20))->and($manyLineQueries)->toBe($oneLineQueries)
        ->and(array_unique(array_column($rows, 'item_code')))->toBe([$f['raw']->doc_num]);
});
