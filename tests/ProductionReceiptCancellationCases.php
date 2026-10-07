<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionReceiptCancellationService;
use Modules\Sales\Models\SalesOrder;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

require_once __DIR__.'/ProductionReceiptCancellationSupport.php';

test('one completed production receipt cancellation retains evidence and precisely restores stock GL and WIP', function (): void {
    $f = receiptCancellationFixture(factoryWorkflow: true);
    $payload = receiptCancellationPayload($f);
    $originalLines = $f['receipt']->lines()->get()->toArray();
    $originalProgress = $f['run']->progressEntries()->get()->toArray();
    $originalQuality = $f['run']->inspections()->get()->toArray();
    $originalMaterials = $f['run']->requirements()->get()->toArray();
    $pieceApprovals = DB::table('production_piece_approvals')->where('production_run_id', $f['run']->id)->get()->all();
    $service = app(ProductionReceiptCancellationService::class);
    $proposal = $service->prepare($f['run'], $f['receipt']->doc_num, $payload['reason'], $payload['fingerprint'], $payload['posting_date'], $payload['correction_mode']);
    $this->actingAs($f['approver'])->withSession(manufacturingIntegritySession($f));
    $service->approve($f['run'], $proposal->id);
    expect($f['receipt']->fresh()->status)->toBe(InventoryDocument::StatusReversed)
        ->and($f['receipts'][1]->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($f['receipt']->lines()->get()->toArray())->toBe($originalLines)
        ->and($f['run']->progressEntries()->get()->toArray())->toBe($originalProgress)
        ->and($f['run']->inspections()->get()->toArray())->toBe($originalQuality)
        ->and($f['run']->requirements()->get()->toArray())->toBe($originalMaterials)
        ->and($f['run']->fresh()->good_base_quantity)->toBe('10.00000000')
        ->and($f['run']->fresh()->received_base_quantity)->toBe('6.00000000')
        ->and($f['run']->fresh()->correction_sequence)->toBe(0)
        ->and(app(ProductionCostService::class)->runPosition($f['run']->fresh())['wip'])->toBe('80.00000000');
    $original = $f['receipt']->transactions()->where('is_reversal', false)->sole();
    $inverse = $f['receipt']->transactions()->where('is_reversal', true)->sole();
    expect($inverse->quantity_out)->toBe($original->quantity_in)->and($inverse->total_cost)->toBe($original->total_cost);
    $sourceJournal = $f['receipt']->fresh()->journalEntry;
    $inverseJournal = $f['receipt']->fresh()->reversalJournalEntry;
    expect($sourceJournal->fresh()->reversed_entry_id)->toBe($inverseJournal->id);
    foreach ($sourceJournal->lines as $line) {
        $inverseLine = $inverseJournal->lines->firstWhere('account_id', $line->account_id);
        expect($inverseLine->debit_amount)->toBe($line->credit_amount)->and($inverseLine->credit_amount)->toBe($line->debit_amount);
    }
    $reconciliation = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id))->keyBy('key');
    expect($reconciliation['wip']['difference'])->toBe('0.0000')->and($reconciliation['finished_goods']['difference'])->toBe('0.0000');
    expect(fn () => $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '1']))->toThrow(DomainException::class)
        ->and(fn () => $f['cycle']->recordLabor($f['run']->fresh(), []))->toThrow(DomainException::class)
        ->and(fn () => $f['cycle']->completeRun($f['run']->fresh()))->toThrow(DomainException::class);
    $this->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '1');
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '3');
    $f['cycle']->completeRun($f['run']->fresh());
    expect($f['run']->fresh()->status)->toBe(ProductionRun::StatusCompleted)
        ->and($f['run']->fresh()->received_base_quantity)->toBe('10.00000000')
        ->and($f['run']->progressEntries()->get()->toArray())->toBe($originalProgress)
        ->and(DB::table('production_piece_approvals')->where('production_run_id', $f['run']->id)->get()->all())->toEqual($pieceApprovals)
        ->and(app(ProductionCostService::class)->runPosition($f['run']->fresh())['wip'])->toBe('0.00000000');
});

test('receipt cancellation requires independent approval and native retries cannot reverse twice or target a sibling', function (): void {
    $f = receiptCancellationFixture();
    $payload = ['_submission_token' => (string) Str::uuid(), ...receiptCancellationPayload($f)];
    $url = route('admin.production.runs.receipt-cancellations.store', [$f['run'], $f['receipt']->doc_num]);
    $id = $this->postJson($url, $payload)->assertOk()->json('data.correction_id');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.correction_id', $id);
    $approve = route('admin.production.runs.receipt-cancellations.approve', [$f['run'], $f['receipt']->doc_num, $id]);
    $token = ['_submission_token' => (string) Str::uuid()];
    $this->postJson($approve, $token)->assertUnprocessable();
    $this->actingAs($f['approver'])->withSession(manufacturingIntegritySession($f));
    $this->postJson(route('admin.production.runs.receipt-cancellations.approve', [$f['run'], $f['receipts'][1]->doc_num, $id]), ['_submission_token' => (string) Str::uuid()])->assertNotFound();
    $this->postJson($approve, $token)->assertOk();
    $this->postJson($approve, $token)->assertOk();
    $this->postJson($approve, ['_submission_token' => (string) Str::uuid()])->assertOk();
    expect($f['receipt']->transactions()->where('is_reversal', true)->count())->toBe(1)
        ->and($f['receipts'][1]->transactions()->where('is_reversal', true)->count())->toBe(0)
        ->and($f['run']->fresh()->received_base_quantity)->toBe('6.00000000');
});

test('receipt cancellation rejects used stock with owner dependency links and preserves the original', function (): void {
    $f = receiptCancellationFixture();
    $document = app(InventoryMovementService::class)->createAndPost(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'financial_period_id' => $f['period']->id, 'branch_store_id' => $f['store']->id, 'document_date' => now()->toDateString(),
        'document_type' => InventoryDocument::TypeAdjustmentOut], [['product_id' => $f['finished']->id, 'quantity' => '1',
            'batch_lot' => $f['receipt']->lines->sole()->batch_lot, 'warehouse_location_id' => $f['receipt']->lines->sole()->destination_warehouse_location_id]]);
    $preview = app(ProductionReceiptCancellationService::class)->preview($f['run'], $f['receipt']->doc_num);
    expect($preview['blockers'])->not->toBeEmpty()->and($preview['correction_steps'])->not->toBeEmpty()
        ->and($f['receipt']->fresh()->status)->toBe(InventoryDocument::StatusPosted);
    expect(fn () => app(ProductionReceiptCancellationService::class)->prepare($f['run'], $f['receipt']->doc_num,
        'SYNTHETIC used stock', $preview['fingerprint'], now()->toDateString(), 'original_period'))->toThrow(DomainException::class);
    expect($document->fresh()->status)->toBe(InventoryDocument::StatusPosted);
});

test('receipt cancellation rolls back all effects when the audit fails and rejects stale source evidence', function (): void {
    $f = receiptCancellationFixture();
    $payload = receiptCancellationPayload($f);
    $service = app(ProductionReceiptCancellationService::class);
    $proposal = $service->prepare($f['run'], $f['receipt']->doc_num, $payload['reason'], $payload['fingerprint'], $payload['posting_date'], $payload['correction_mode']);
    $before = InventoryTransaction::query()->count();
    $this->actingAs($f['approver'])->withSession(manufacturingIntegritySession($f));
    $this->mock(ActivityLogger::class, function ($mock): void {
        $mock->shouldReceive('log')->andThrow(new RuntimeException('SYNTHETIC audit failure'));
    });
    expect(fn () => app(ProductionReceiptCancellationService::class)->approve($f['run'], $proposal->id))->toThrow(RuntimeException::class, 'SYNTHETIC audit failure');
    expect(InventoryTransaction::query()->count())->toBe($before)->and($f['receipt']->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($f['run']->fresh()->received_base_quantity)->toBe('10.00000000')
        ->and(DB::table('production_run_corrections')->find($proposal->id)->status)->toBe('prepared');
    $this->app->forgetInstance(ActivityLogger::class);
    $f['run']->update(['notes' => 'SYNTHETIC changed after proposal']);
    expect(fn () => app(ProductionReceiptCancellationService::class)->approve($f['run']->fresh(), $proposal->id))->toThrow(DomainException::class);
});

test('receipt cancellation preserves general production inventory safeguards and bilingual discovery', function (string $locale): void {
    $f = receiptCancellationFixture();
    app()->setLocale($locale);
    $url = route('admin.production.runs.receipt-cancellations.index', [$f['run'], $f['receipt']->doc_num]);
    $this->get($url)->assertOk()->assertSee(__('production_receipt_cancellation.title'))->assertSee('name="reason"', false);
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($f['receipt'], 'SYNTHETIC forbidden generic reversal'))->toThrow(DomainException::class);
    $this->actingAs(closureSyntheticUser())->withSession(manufacturingIntegritySession($f));
    $this->get($url)->assertForbidden();
})->with(['ar', 'en']);

test('closed source period receipt cancellation posts into the authorized open period and preserves historical lot dates', function (): void {
    $f = receiptCancellationFixture(parts: [['quantity' => '4', 'date' => '2026-09-29 12:00:00'], ['quantity' => '6', 'date' => '2026-09-30 12:00:00']], tracksExpiry: true);
    $dates = $f['receipt']->lines->sole()->only(['batch_lot', 'manufacture_date', 'expiry_date']);
    $f['period']->update(['is_closed' => true]);
    $target = FinancialPeriod::query()->create(['company_id' => $f['company']->id, 'doc_number' => (int) FinancialPeriod::withTrashed()->max('doc_number') + 1,
        'doc_num' => 'SYNTHETIC-RECEIPT-OCT', 'name' => 'SYNTHETIC open receipt correction period',
        'from_date' => '2026-10-01', 'to_date' => '2026-12-31', 'is_closed' => false]);
    foreach ([$f['user'], $f['approver']] as $user) {
        Permission::findOrCreate('production.runs.correct_later_period', 'web');
        $user->givePermissionTo('production.runs.correct_later_period');
    }
    $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
    $session = [...manufacturingIntegritySession($f), OperatingContextService::FinancialPeriodIdKey => $target->id, OperatingContextService::FinancialPeriodDocNumKey => $target->doc_num];
    $this->actingAs($f['user'])->withSession($session);
    request()->session()->put($session);
    $preview = app(ProductionReceiptCancellationService::class)->preview($f['run']->fresh(), $f['receipt']->doc_num);
    $proposal = app(ProductionReceiptCancellationService::class)->prepare($f['run']->fresh(), $f['receipt']->doc_num,
        'SYNTHETIC closed period receipt error', $preview['fingerprint'], '2026-10-05', 'later_period');
    $this->actingAs($f['approver'])->withSession($session);
    app(ProductionReceiptCancellationService::class)->approve($f['run'], $proposal->id);
    expect($f['receipt']->transactions()->where('is_reversal', true)->sole()->financial_period_id)->toBe($target->id)
        ->and($f['receipt']->fresh()->reversalJournalEntry->financial_period_id)->toBe($target->id);
    $this->actingAs($f['user'])->withSession($session);
    $f['finished']->update(['default_shelf_life_days' => 1]);
    $replacement = $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '4');
    expect($replacement->financial_period_id)->toBe($target->id)->and($replacement->lines->sole()->only(array_keys($dates)))->toEqual($dates)
        ->and($f['receipts'][1]->fresh()->status)->toBe(InventoryDocument::StatusPosted);
    $f['cycle']->completeRun($f['run']->fresh());
    expect($f['run']->fresh()->good_base_quantity)->toBe('10.00000000')->and($f['run']->fresh()->received_base_quantity)->toBe('10.00000000')
        ->and(app(ProductionCostService::class)->runPosition($f['run']->fresh())['wip'])->toBe('0.00000000');
});

test('receipt cancellation cannot rewrite the sealed source snapshot or use a foreign operating company', function (): void {
    $f = receiptCancellationFixture();
    $payload = receiptCancellationPayload($f);
    $proposal = app(ProductionReceiptCancellationService::class)->prepare($f['run'], $f['receipt']->doc_num,
        $payload['reason'], $payload['fingerprint'], $payload['posting_date'], $payload['correction_mode']);
    DB::table('production_run_corrections')->where('id', $proposal->id)->update(['source_snapshot' => '{}']);
    $this->actingAs($f['approver'])->withSession(manufacturingIntegritySession($f));
    expect(fn () => app(ProductionReceiptCancellationService::class)->approve($f['run'], $proposal->id))->toThrow(DomainException::class);
    request()->session()->put(OperatingContextService::CompanyIdKey, 1);
    expect(fn () => app(ProductionReceiptCancellationService::class)->preview($f['run'], $f['receipt']->doc_num))->toThrow(NotFoundHttpException::class);
    expect($f['receipt']->fresh()->status)->toBe(InventoryDocument::StatusPosted)->and($f['run']->fresh()->received_base_quantity)->toBe('10.00000000');
});

test('receipt-only recovery preserves a real approved piece wage and its original approval actor and date', function (): void {
    $f = receiptCancellationFixture(withPiece: true);
    $before = DB::table('production_piece_approvals')->where('production_run_id', $f['run']->id)->get()->all();
    $labor = $f['run']->labor_details;
    expect($before)->toHaveCount(1)->and(bcadd((string) $before[0]->quantity, '0', 8))->toBe('10.00000000');
    $payload = receiptCancellationPayload($f);
    $proposal = app(ProductionReceiptCancellationService::class)->prepare($f['run'], $f['receipt']->doc_num,
        $payload['reason'], $payload['fingerprint'], $payload['posting_date'], $payload['correction_mode']);
    $this->actingAs($f['approver'])->withSession(manufacturingIntegritySession($f));
    app(ProductionReceiptCancellationService::class)->approve($f['run'], $proposal->id);
    $this->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '4');
    $f['cycle']->completeRun($f['run']->fresh());
    expect(DB::table('production_piece_approvals')->where('production_run_id', $f['run']->id)->get()->all())->toEqual($before)
        ->and($f['run']->fresh()->labor_details)->toEqual($labor)->and($f['run']->fresh()->correction_sequence)->toBe(0);
});

test('one unambiguous finished sales reservation is exactly released and rebuilt without cancelling the sales demand', function (): void {
    $f = receiptCancellationFixture(parts: ['10'], forSales: true);
    $reservation = InventoryReservation::query()->where('production_run_id', $f['run']->id)->whereNotNull('sales_order_line_id')->sole();
    expect($reservation->remaining_quantity)->toBe('10.00000000')->and($f['salesLine']->fresh()->produced_base_quantity)->toBe('10.00000000');
    $payload = receiptCancellationPayload($f);
    $proposal = app(ProductionReceiptCancellationService::class)->prepare($f['run'], $f['receipt']->doc_num,
        $payload['reason'], $payload['fingerprint'], $payload['posting_date'], $payload['correction_mode']);
    $this->actingAs($f['approver'])->withSession(manufacturingIntegritySession($f));
    app(ProductionReceiptCancellationService::class)->approve($f['run'], $proposal->id);
    expect($reservation->fresh()->status)->toBe(InventoryReservation::StatusReleased)
        ->and($reservation->fresh()->released_quantity)->toBe('10.00000000')
        ->and($f['salesLine']->fresh()->reserved_base_quantity)->toBe('0.00000000')
        ->and($f['salesLine']->fresh()->produced_base_quantity)->toBe('0.00000000')
        ->and($f['salesOrder']->fresh()->status)->toBe(SalesOrder::StatusApproved);
    $this->actingAs($f['user'])->withSession(manufacturingIntegritySession($f));
    $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, '10');
    $f['cycle']->completeRun($f['run']->fresh());
    expect($f['salesLine']->fresh()->reserved_base_quantity)->toBe('10.00000000')
        ->and($f['salesLine']->fresh()->produced_base_quantity)->toBe('10.00000000')
        ->and($reservation->fresh()->status)->toBe(InventoryReservation::StatusReleased);
});

test('sales reservations shared across sibling receipt dimensions block cancellation instead of blanket release', function (): void {
    $f = receiptCancellationFixture(forSales: true);
    $preview = app(ProductionReceiptCancellationService::class)->preview($f['run'], $f['receipt']->doc_num);
    expect($preview['blockers'])->toContain(__('production_receipt_cancellation.reservation_ambiguous'));
    expect(fn () => app(ProductionReceiptCancellationService::class)->prepare($f['run'], $f['receipt']->doc_num,
        'SYNTHETIC ambiguous reservation must be explicitly resolved', $preview['fingerprint'], now()->toDateString(), 'original_period'))->toThrow(DomainException::class);
    expect($f['salesLine']->fresh()->reserved_base_quantity)->toBe('10.00000000')
        ->and($f['salesLine']->fresh()->produced_base_quantity)->toBe('10.00000000')
        ->and($f['receipt']->fresh()->status)->toBe(InventoryDocument::StatusPosted)
        ->and($f['receipts'][1]->fresh()->status)->toBe(InventoryDocument::StatusPosted);
});
