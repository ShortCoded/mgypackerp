<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryDocumentLine;
use Modules\Inventory\Models\InventoryReservation;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/InventoryDocumentLineageSupport.php';
require_once __DIR__.'/SalesCycleSupport.php';

beforeEach(function (): void {
    if (getenv('MGYPACK_ISSUE_REPRO') !== '1' || DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Explicit synthetic before-fix visual reproduction only.');
    }
    $identity = DB::selectOne('select current_database() as db, inet_server_addr() as host, inet_server_port() as port');
    expect($identity->db)->toBe('mgypack_wht_race_pg_20261005')->and($identity->host)->toBe('127.0.0.1')
        ->and($identity->port)->toBe(5432)->and(DB::transactionLevel())->toBe(0);
});

test('native issue browser and print reads preserve the committed synthetic financial source', function (): void {
    $fixture = json_decode(file_get_contents(storage_path('app/test-artifacts/mgypack-final-issue-browser-fixture-20261005.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($fixture['database'])->toBe('mgypack_wht_race_pg_20261005')
        ->and(str_starts_with($fixture['context']['company_doc_num'], 'SYNTHETIC-'))->toBeTrue();
    $snapshot = $fixture['financial_state'];
    expect(InventoryDocument::query()->findOrFail($snapshot['document']['id'])->getAttributes())->toBe($snapshot['document'])
        ->and(InventoryDocumentLine::query()->findOrFail($snapshot['line']['id'])->getAttributes())->toBe($snapshot['line'])
        ->and(InventoryReservation::query()->findOrFail($snapshot['reservation']['id'])->getAttributes())->toBe($snapshot['reservation']);
});

test('capture the reported cost and UUID defects using a committed synthetic native material issue', function (): void {
    $path = storage_path('app/test-artifacts/mgypack-final-issue-browser-fixture-20261005.json');
    if (is_file($path)) {
        throw new RuntimeException('Refusing to recreate committed synthetic browser fixture.');
    }
    $f = DB::transaction(fn (): array => inventoryDocumentLineageFixture());
    expect(str_starts_with($f['company']->doc_num, 'SYNTHETIC-'))->toBeTrue()
        ->and(str_starts_with($f['user']->doc_num, 'SYNTHETIC-CLOSURE-USER-'))->toBeTrue()
        ->and(Hash::check('password', $f['user']->password))->toBeTrue();
    $proof = [];
    foreach (['ar', 'en'] as $locale) {
        $this->withSession(['locale' => $locale]);
        app()->setLocale($locale);
        $html = $this->get(route('admin.inventory.documents.show', $f['document']))->assertOk()->getContent();
        expect($html)->toContain($f['reservation']->public_id)->toContain($f['requirement']->public_id);
        $pdf = $this->get(route('admin.inventory.documents.print', $f['document']))->assertOk()->assertHeader('content-type', 'application/pdf');
        if ($locale === 'en') {
            expect(salesPdfText($pdf->getContent()))->toContain(__('inventory.movements.fields.unit_cost'))->toContain(__('inventory.movements.receipt_pricing_total'));
        }
        file_put_contents(storage_path('app/test-artifacts/mgypack-final-before-issue-'.$locale.'-20261005.html'), $html);
        file_put_contents(storage_path('app/test-artifacts/mgypack-final-before-issue-'.$locale.'-20261005.pdf'), $pdf->getContent());
        $proof[] = ['locale' => $locale, 'before_exposed_uuids' => true, 'before_cost_columns' => true];
    }
    $fixture = ['database' => 'mgypack_wht_race_pg_20261005', 'username' => $f['user']->username,
        'document' => $f['document']->doc_num, 'document_id' => $f['document']->id, 'item_code' => $f['raw']->doc_num,
        'item_name' => $f['raw']->name, 'material_request' => $f['materialRequest']->doc_num, 'run' => $f['run']->run_number,
        'reservation_uuid' => $f['reservation']->public_id, 'requirement_uuid' => $f['requirement']->public_id,
        'context' => ['company_doc_num' => $f['company']->doc_num, 'branch_doc_num' => $f['branch']->doc_num, 'financial_period_doc_num' => $f['period']->doc_num],
        'proof' => $proof, 'financial_state' => ['document' => $f['document']->fresh()->getAttributes(),
            'line' => $f['line']->fresh()->getAttributes(), 'reservation' => $f['reservation']->fresh()->getAttributes()]];
    file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});
