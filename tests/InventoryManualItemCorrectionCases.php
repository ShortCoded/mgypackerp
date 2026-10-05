<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Exports\InventoryStockCardExport;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryMovementCorrection;
use Modules\Inventory\Models\InventoryReceiptLayer;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryMovementCorrectionService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryReportService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/InventoryMovementCorrectionSupport.php';

/** @return array<string, mixed> */
function manualItemFixture(string $type = InventoryDocument::TypeReceipt): array
{
    $f = manualCorrectionFixture();
    $f['alternate'] = Product::query()->create(['company_id' => $f['company']->id,
        'doc_number' => (int) Product::withTrashed()->max('doc_number') + 1, 'doc_num' => 'SYNTHETIC-ITEM-CORRECTION-'.$f['company']->id,
        'name' => 'SYNTHETIC replacement material', 'item_unit_id' => $f['unit']->id,
        'item_classification' => Product::ClassificationPackaging, 'status' => 'active']);
    $permissions = ['inventory.documents.receive', 'inventory.documents.issue', 'inventory.reports.operations.view',
        'inventory.reports.operations.export', 'inventory.reports.operations.print', 'inventory.reports.stock_balances.view'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $f['user']->givePermissionTo($permissions);
    $f['reviewer']->givePermissionTo($permissions);
    $header = ['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id, 'financial_period_id' => $f['period']->id,
        'branch_store_id' => $f['store']->id, 'document_date' => '2026-09-28', 'document_type' => InventoryDocument::TypeReceipt];
    if ($type === InventoryDocument::TypeIssue) {
        app(InventoryMovementService::class)->createAndPost($header, [['product_id' => $f['alternate']->id, 'quantity' => '12', 'unit_cost' => '2']]);
    }
    $f['document'] = app(InventoryMovementService::class)->createAndPost([...$header, 'document_type' => $type], [
        ['product_id' => $f['finished']->id, 'quantity' => '3', 'unit_cost' => '2'],
        ['product_id' => $type === InventoryDocument::TypeIssue ? $f['alternate']->id : $f['raw']->id, 'quantity' => '2', 'unit_cost' => '2'],
    ]);
    manualCorrectionActor($f, $f['user']);

    return $f;
}

/** @param array<string, mixed> $f @return array<string, mixed> */
function manualItemPayload(array $f): array
{
    return ['operation' => 'replace_items', 'posting_date' => '2026-09-28', 'reason' => 'SYNTHETIC reviewed replacement addition and removal',
        'source_fingerprint' => app(InventoryMovementCorrectionService::class)->preview($f['document'])['source_fingerprint'],
        'lines' => [['line_id' => $f['document']->lines->first()->id, 'product_doc_num' => $f['alternate']->doc_num,
            'unit_doc_num' => $f['unit']->doc_num, 'quantity' => $f['document']->document_type === InventoryDocument::TypeIssue ? '4' : '2', 'unit_cost' => '3'],
            ['product_doc_num' => $f['finished']->doc_num, 'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1', 'unit_cost' => '2']]];
}

test('manual item corrections independently reverse and replace the complete line list with reconciled stock cards', function (string $type): void {
    $f = manualItemFixture($type);
    $document = $f['document'];
    $original = $document->transactions()->orderBy('id')->get()->map->getAttributes()->all();
    $lines = $document->lines()->orderBy('id')->get()->map->getAttributes()->all();
    $journals = DB::table('journal_entries')->where('company_id', $f['company']->id)->count();
    $payload = manualItemPayload($f);
    $url = route('admin.inventory.documents.corrections.store', $document);
    $id = $this->postJson($url, $payload)->assertOk()->json('data.proposal_id');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.proposal_id', $id);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get(route('admin.inventory.documents.corrections.index', $document))->assertOk()
            ->assertSee(__('inventory_correction.before'))->assertSee(__('inventory_correction.after'));
    }
    $approve = route('admin.inventory.documents.corrections.approve', [$document, $id]);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC same reviewer'])->assertUnprocessable();
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC independent item review'])->assertOk();
    $proposal = InventoryMovementCorrection::findOrFail($id);
    expect($proposal->replacementDocument->lines->pluck('product_id')->all())->toBe([$f['alternate']->id, $f['finished']->id])
        ->and($document->fresh()->status)->toBe('reversed')
        ->and($document->transactions()->where('is_reversal', false)->orderBy('id')->get()->map->getAttributes()->all())->toBe($original)
        ->and($document->lines()->orderBy('id')->get()->map->getAttributes()->all())->toBe($lines)
        ->and(DB::table('journal_entries')->where('company_id', $f['company']->id)->count())->toBe($journals + 2);
    $this->postJson($approve, ['approval_reason' => 'SYNTHETIC repeat item review'])->assertOk()->assertJsonPath('data.proposal_id', $id);
    expect(DB::table('journal_entries')->where('company_id', $f['company']->id)->count())->toBe($journals + 2);
    foreach ([$f['finished'], $f['alternate'], $f['raw']] as $product) {
        $filters = ['as_of' => '2026-09-28', 'branch_store_id' => $f['store']->id, 'product_doc_num' => $product->doc_num,
            'allowed_branch_ids' => [$f['branch']->id]];
        $service = app(InventoryReportService::class);
        $inquiry = $service->stockBalanceInquiry($f['company']->id, [$f['branch']->id], $filters);
        $card = $service->stockCard($f['company']->id, $product->id, $filters);
        $totals = $service->stockCardTotals($f['company']->id, $product->id, $filters);
        $export = new InventoryStockCardExport($card, $totals);
        expect($totals['closing_balance'])->toBe($inquiry['totals']['on_hand'])
            ->and($card->last()?->running_balance ?? '0.00000000')->toBe($inquiry['totals']['on_hand'])
            ->and($export->array()[count($export->array()) - 1][7])->toBe($inquiry['totals']['on_hand']);
        if ($product->id === $f['finished']->id) {
            expect($card->where('is_reversal', true))->toHaveCount(1)
                ->and($totals['closing_balance'])->toBe($type === InventoryDocument::TypeReceipt ? '11.00000000' : '9.00000000');
        }
    }
})->with([InventoryDocument::TypeReceipt, InventoryDocument::TypeIssue]);

test('manual item corrections preserve permissions tenant scope translations and reviewed products', function (string $type): void {
    $f = manualItemFixture($type);
    $payload = manualItemPayload($f);
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get(route('admin.inventory.documents.corrections.index', $f['document']))
            ->assertOk()->assertSee(__('inventory_correction.replace_items'))->assertSee('data-posted-document-correction', false)
            ->assertDontSee('inventory_correction.items_', false);
    }
    $permission = $type === InventoryDocument::TypeReceipt ? 'inventory.documents.receive' : 'inventory.documents.issue';
    $f['user']->revokePermissionTo($permission);
    $url = route('admin.inventory.documents.corrections.store', $f['document']);
    $this->postJson($url, $payload)->assertForbidden();
    if ($type === InventoryDocument::TypeIssue) {
        $this->getJson(route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $f['store']->public_uuid,
            'product_doc_num' => $f['finished']->doc_num, 'document_date' => '2026-09-28']))->assertForbidden();
    }
    $f['user']->givePermissionTo($permission);
    if ($type === InventoryDocument::TypeIssue) {
        $this->getJson(route('admin.inventory.documents.select2.receipt-layers', ['branch_store_uuid' => $f['store']->public_uuid,
            'product_doc_num' => $f['finished']->doc_num, 'document_date' => '2026-09-28']))->assertOk();
    }
    $bad = $payload;
    $bad['lines'][0]['product_doc_num'] = 'SYNTHETIC-FOREIGN-PRODUCT';
    $this->postJson($url, $bad)->assertUnprocessable();
    $bad = $payload;
    $bad['lines'][0]['unit_doc_num'] = 'SYNTHETIC-UNCONFIGURED-UNIT';
    $this->postJson($url, $bad)->assertUnprocessable();
    $this->withSession([OperatingContextService::BranchIdKey => $f['branch']->id + 100000])->postJson($url, $payload)->assertNotFound();
    manualCorrectionActor($f, $f['user']);
    $id = $this->postJson($url, $payload)->assertOk()->json('data.proposal_id');
    manualCorrectionActor($f, $f['reviewer']);
    $f['reviewer']->revokePermissionTo($permission);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['document'], $id]), ['approval_reason' => 'SYNTHETIC denied item review'])->assertForbidden();
    expect($f['document']->fresh()->status)->toBe('posted');
})->with([InventoryDocument::TypeReceipt, InventoryDocument::TypeIssue]);

test('manual item correction blocks closed linked consumed and later movement cases with original effects retained', function (string $blocker): void {
    $f = manualItemFixture();
    if ($blocker === 'closed') {
        $f = manualCorrectionClose($f);
    } elseif ($blocker === 'linked') {
        $f['document']->update(['source_document_type' => 'SYNTHETIC-SOURCE', 'source_document_id' => 1]);
    } elseif ($blocker === 'consumed') {
        manualCorrectionMovement($f, InventoryDocument::TypeIssue, '11');
    } else {
        manualCorrectionMovement($f, InventoryDocument::TypeReceipt, '1', '2');
    }
    $before = $f['document']->transactions()->orderBy('id')->get()->map->getAttributes()->all();
    $payload = manualItemPayload($f);
    if ($blocker === 'closed') {
        $payload['posting_date'] = '2026-10-02';
    }
    $this->postJson(route('admin.inventory.documents.corrections.store', $f['document']), $payload)->assertUnprocessable();
    expect($f['document']->fresh()->status)->toBe('posted')
        ->and($f['document']->transactions()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(InventoryMovementCorrection::where('inventory_document_id', $f['document']->id)->count())->toBe(0);
})->with(['closed', 'linked', 'consumed', 'later']);

test('manual item approval rejects stale candidate evidence and rolls back an injected replacement failure', function (string $failure, string $type): void {
    $f = manualItemFixture($type);
    $id = $this->postJson(route('admin.inventory.documents.corrections.store', $f['document']), manualItemPayload($f))->assertOk()->json('data.proposal_id');
    $original = $f['document']->transactions()->orderBy('id')->get()->map->getAttributes()->all();
    $count = DB::table('journal_entries')->where('company_id', $f['company']->id)->count();
    if ($failure === 'candidate') {
        $f['alternate']->update(['name' => 'SYNTHETIC changed reviewed candidate']);
    } else {
        InventoryDocument::creating(function ($record) use ($f): void {
            if ((int) $record->company_id === (int) $f['company']->id) {
                throw new DomainException('SYNTHETIC replacement failure');
            }
        });
    }
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['document'], $id]), ['approval_reason' => 'SYNTHETIC failure review'])->assertUnprocessable();
    expect($f['document']->fresh()->status)->toBe('posted')->and($f['document']->fresh()->reversal_journal_entry_id)->toBeNull()
        ->and(InventoryMovementCorrection::findOrFail($id)->status)->toBe('prepared')
        ->and(DB::table('journal_entries')->where('company_id', $f['company']->id)->count())->toBe($count)
        ->and($f['document']->transactions()->orderBy('id')->get()->map->getAttributes()->all())->toBe($original);
})->with([
    ['candidate', InventoryDocument::TypeReceipt], ['posting', InventoryDocument::TypeReceipt], ['posting', InventoryDocument::TypeIssue],
]);

test('manual item receipt corrections preserve an original serial or post a distinct replacement serial', function (bool $replace): void {
    $f = manualCorrectionFixture(true);
    foreach ([$f['user'], $f['reviewer']] as $actor) {
        $actor->givePermissionTo(Permission::findOrCreate('inventory.documents.receive', 'web'));
    }
    $product = $f['finished'];
    if ($replace) {
        $product = Product::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) Product::withTrashed()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-NEW-SERIAL-'.$f['company']->id, 'name' => 'SYNTHETIC replacement serial item',
            'item_unit_id' => $f['unit']->id, 'item_classification' => Product::ClassificationRawMaterial, 'tracks_serials' => true, 'status' => 'active']);
    }
    manualCorrectionActor($f, $f['user']);
    $payload = ['operation' => 'replace_items', 'posting_date' => '2026-09-28', 'reason' => 'SYNTHETIC reviewed serial item correction',
        'source_fingerprint' => app(InventoryMovementCorrectionService::class)->preview($f['receipt'])['source_fingerprint'],
        'lines' => [['line_id' => $f['receipt']->lines->sole()->id, 'product_doc_num' => $product->doc_num,
            'unit_doc_num' => $f['unit']->doc_num, 'quantity' => '1', 'unit_cost' => '3',
            'serial_number' => $replace ? 'SYNTHETIC-NEW-SERIAL-0001' : 'SYNTHETIC-MANUAL-0001']]];
    $id = $this->postJson(route('admin.inventory.documents.corrections.store', $f['receipt']), $payload)->assertOk()->json('data.proposal_id');
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['receipt'], $id]), ['approval_reason' => 'SYNTHETIC verified serial identity'])->assertOk();
    $replacement = InventoryMovementCorrection::findOrFail($id)->replacementDocument;
    expect($replacement->lines->sole()->serialIdentity->serial_number)->toBe($payload['lines'][0]['serial_number'])
        ->and($replacement->lines->sole()->product_id)->toBe($product->id)
        ->and(InventoryReceiptLayer::where('receipt_transaction_id', $replacement->transactions()->sole()->id)->sole()->remaining_quantity)->toBe('1.00000000');
})->with([false, true]);

test('manual replacement unit conversions post base quantities and keep the queried stock card exact', function (): void {
    $f = manualItemFixture();
    $carton = ItemUnit::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) ItemUnit::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-ITEM-CARTON-'.$f['company']->id, 'name' => 'SYNTHETIC carton', 'status' => 'active']);
    $f['alternate']->update(['equivalent_unit_id' => $carton->id, 'equivalent_value' => '0.1']);
    $payload = manualItemPayload($f);
    $payload['lines'][0]['unit_doc_num'] = $carton->doc_num;
    $id = $this->postJson(route('admin.inventory.documents.corrections.store', $f['document']), $payload)->assertOk()->json('data.proposal_id');
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['document'], $id]), ['approval_reason' => 'SYNTHETIC unit conversion review'])->assertOk();
    $line = InventoryMovementCorrection::findOrFail($id)->replacementDocument->lines->first();
    expect($line->quantity)->toBe('20.00000000')->and($line->transaction_quantity)->toBe('2.00000000')
        ->and($line->conversion_factor)->toBe('10.00000000')->and($line->unit_id)->toBe($f['unit']->id)
        ->and($line->transaction_unit_id)->toBe($carton->id);
    $service = app(InventoryReportService::class);
    $filters = ['as_of' => '2026-09-28', 'product_doc_num' => $f['alternate']->doc_num, 'branch_store_id' => $f['store']->id];
    expect($service->stockCard($f['company']->id, $f['alternate']->id, $filters)->last()->running_balance)->toBe('20.00000000')
        ->and($service->stockBalanceInquiry($f['company']->id, [$f['branch']->id], $filters)['totals']['on_hand'])->toBe('20.00000000');
});

test('stock card retains correction and reversal history with opening cutoff scope and bilingual exports', function (): void {
    $f = manualItemFixture();
    $payload = manualItemPayload($f);
    $payload['posting_date'] = '2026-09-29';
    $id = $this->postJson(route('admin.inventory.documents.corrections.store', $f['document']), $payload)->assertOk()->json('data.proposal_id');
    manualCorrectionActor($f, $f['reviewer']);
    $this->postJson(route('admin.inventory.documents.corrections.approve', [$f['document'], $id]), ['approval_reason' => 'SYNTHETIC dated history review'])->assertOk();
    manualCorrectionMovement($f, InventoryDocument::TypeReceipt, '4', '2', [], ['document_date' => '2026-09-30']);
    app(InventoryMovementService::class)->createDraft(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'branch_store_id' => $f['store']->id, 'document_type' => InventoryDocument::TypeReceipt,
        'document_date' => '2026-09-28'], [['product_id' => $f['finished']->id, 'quantity' => '99', 'unit_cost' => '2']]);
    $service = app(InventoryReportService::class);
    $filters = ['branch_store_id' => $f['store']->id, 'product_doc_num' => $f['finished']->doc_num,
        'allowed_branch_ids' => [$f['branch']->id], 'as_of' => '2026-09-29', 'from' => '2026-09-29'];
    $card = $service->stockCard($f['company']->id, $f['finished']->id, $filters);
    $totals = $service->stockCardTotals($f['company']->id, $f['finished']->id, $filters);
    expect($card)->toHaveCount(2)->and($card->pluck('is_reversal')->all())->toBe([true, false])
        ->and($card->pluck('running_balance')->all())->toBe(['10.00000000', '11.00000000'])
        ->and($totals['opening_balance'])->toBe('13.00000000')->and($totals['closing_balance'])->toBe('11.00000000')
        ->and($service->stockBalanceInquiry($f['company']->id, [$f['branch']->id], $filters)['totals']['on_hand'])->toBe('11.00000000')
        ->and($service->stockCardTotals($f['company']->id, $f['finished']->id, [...$filters, 'as_of' => '2026-09-28', 'from' => null])['closing_balance'])->toBe('13.00000000')
        ->and($service->stockCard($f['company']->id + 100000, $f['finished']->id, $filters))->toBeEmpty()
        ->and($service->stockCard($f['company']->id, $f['finished']->id, [...$filters, 'allowed_branch_ids' => []]))->toBeEmpty();
    $query = ['as_of' => '2026-09-29', 'from' => '2026-09-29', 'product_doc_num' => $f['finished']->doc_num,
        'branch_store_uuid' => $f['store']->public_uuid];
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale])->get(route('admin.inventory.reports.stock-card', $query))
            ->assertOk()->assertSee(__('inventory_correction.card.title'))->assertSee($f['document']->doc_num)
            ->assertViewHas('totals', fn (array $values): bool => $values['closing_balance'] === '11.00000000')
            ->assertViewHas('movements', fn ($rows): bool => $rows->count() === 2);
    }
    $this->get(route('admin.inventory.reports.stock-card.export', $query))->assertOk()->assertDownload('stock-card.xlsx');
    $this->get(route('admin.inventory.reports.stock-card.print', $query))->assertOk()->assertHeader('content-type', 'application/pdf');
    $f['reviewer']->revokePermissionTo('inventory.reports.operations.view');
    $this->get(route('admin.inventory.reports.stock-card', $query))->assertForbidden();
});

test('stock card pagination retains precise running quantities across dates stores and all exported movements', function (): void {
    $f = manualItemFixture();
    $otherStore = BranchStore::query()->create(['branch_id' => $f['branch']->id, 'name' => 'SYNTHETIC second card store', 'position' => 2]);
    $template = $f['receipt']->transactions()->sole()->getAttributes();
    $rows = [];
    for ($index = 0; $index < 501; $index++) {
        $rows[] = [...collect($template)->except('id')->all(), 'posting_key' => 'SYNTHETIC-CARD-PAGE-'.$f['company']->id.'-'.$index,
            'branch_store_id' => $index % 2 === 0 ? $f['store']->id : $otherStore->id,
            'transaction_date' => $index < 400 ? '2026-09-28' : '2026-09-29', 'quantity_in' => '0.00000001',
            'quantity_out' => '0', 'unit_cost' => '2', 'total_cost' => '0.00000002'];
    }
    InventoryTransaction::query()->insert($rows);
    $service = app(InventoryReportService::class);
    $filters = ['as_of' => '2026-09-29', 'product_doc_num' => $f['finished']->doc_num, 'allowed_branch_ids' => [$f['branch']->id]];
    $all = $service->stockCard($f['company']->id, $f['finished']->id, $filters);
    $first = $service->stockCard($f['company']->id, $f['finished']->id, $filters, 1);
    $second = $service->stockCard($f['company']->id, $f['finished']->id, $filters, 2);
    $totals = $service->stockCardTotals($f['company']->id, $f['finished']->id, $filters);
    expect($all)->toHaveCount(503)->and($first)->toHaveCount(500)->and($second)->toHaveCount(3)
        ->and($first->pluck('id')->concat($second->pluck('id'))->all())->toBe($all->pluck('id')->all())
        ->and($all->last()->running_balance)->toBe('13.00000501')
        ->and($second->last()->running_balance)->toBe($all->last()->running_balance)
        ->and($totals['closing_balance'])->toBe('13.00000501')
        ->and($service->stockBalanceInquiry($f['company']->id, [$f['branch']->id], $filters)['totals']['on_hand'])->toBe($totals['closing_balance'])
        ->and((new InventoryStockCardExport($all, $totals))->array())->toHaveCount(505);
    foreach ([$f['store'], $otherStore] as $store) {
        $scope = [...$filters, 'branch_store_id' => $store->id];
        expect($service->stockCard($f['company']->id, $f['finished']->id, $scope)->last()->running_balance)
            ->toBe($service->stockBalanceInquiry($f['company']->id, [$f['branch']->id], $scope)['totals']['on_hand']);
    }
});
