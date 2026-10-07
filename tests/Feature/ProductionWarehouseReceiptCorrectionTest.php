<?php

use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\JournalEntry;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryDocumentPostingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Services\ProductionHandoverService;
use Modules\Production\Services\ProductionReceiptCancellationService;
use Modules\Production\Services\ProductionWarehouseReceiptCorrectionService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProductionHandoverSupport.php';

/** @return array<string, mixed> */
function warehouseCorrectionFixture(bool $complete = false): array
{
    $f = productionHandoverFixture();
    $service = app(ProductionHandoverService::class);
    $handover = $service->approveHandover($service->createHandover($f['runs']->first(), $f['store']->id, now()->toDateString(),
        $f['runs']->map(fn (ProductionRun $run): array => ['run_public_id' => $run->public_id, 'quantity' => '10'])->all()));
    productionHandoverActor($f, $f['warehouse']);
    $receipt = $service->approveWarehouseReceipt($service->createWarehouseReceipt($handover, now()->toDateString(),
        $handover->lines->map(fn ($line): array => ['line_public_id' => $line->public_id, 'quantity' => '10'])->all()));
    if ($complete) {
        productionHandoverActor($f, $f['user']);
        foreach ($f['runs'] as $run) {
            $f['cycle']->completeRun($run->fresh());
        }
    }
    foreach (['inventory.production_receipts.correct_prepare', 'inventory.production_receipts.correct_approve'] as $key) {
        $f['warehouse']->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    $reviewer = closureSyntheticUser();
    $reviewer->givePermissionTo(Permission::findOrCreate('inventory.production_receipts.correct_approve', 'web'));
    productionHandoverActor($f, $f['warehouse']);

    return [...$f, ...compact('handover', 'receipt', 'reviewer')];
}

test('independent warehouse correction reverses three item stock and GL once and preserves source output evidence', function (): void {
    $f = warehouseCorrectionFixture();
    $service = app(ProductionWarehouseReceiptCorrectionService::class);
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    $original = $f['receipt']->lines->map->getRawOriginal()->all();
    $progress = $f['runs']->map(fn (ProductionRun $run) => $run->fresh()->progressEntries->map->getRawOriginal()->all())->all();
    expect(fn () => app(InventoryDocumentPostingService::class)->reverse($f['receipt'], 'SYNTHETIC generic bypass denied'))->toThrow(DomainException::class);
    $preview = $service->preview($f['receipt']);
    expect($preview['blockers'])->toBe([]);
    expect($preview['snapshot']['requirements'])->toHaveCount(3);
    $proposal = $service->prepare($f['receipt'], 'SYNTHETIC wrong warehouse quantity', $preview['fingerprint'], now()->toDateString());
    expect(fn () => $service->approve($f['receipt'], $proposal->id))->toThrow(DomainException::class);
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    productionHandoverActor($f, $f['reviewer']);
    $service->approve($f['receipt'], $proposal->id);
    $after = [InventoryTransaction::count(), JournalEntry::count()];
    $service->approve($f['receipt'], $proposal->id);
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($after)
        ->and($f['receipt']->fresh()->status)->toBe('reversed')->and($f['receipt']->fresh()->reversal_journal_entry_id)->not->toBeNull()
        ->and($f['receipt']->fresh()->lines->map->getRawOriginal()->all())->toBe($original)
        ->and($f['runs']->map(fn (ProductionRun $run) => $run->fresh()->received_base_quantity)->all())->toBe(['0.00000000', '0.00000000', '0.00000000'])
        ->and($f['runs']->map(fn (ProductionRun $run) => $run->fresh()->progressEntries->map->getRawOriginal()->all())->all())->toBe($progress)
        ->and(DB::table('activity_log')->where('event', 'production.warehouse_receipt_correction.approved')->where('subject_id', $f['receipt']->id)->count())->toBe(1);
    foreach (app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id) as $row) {
        expect($row['difference'])->toBe('0.0000');
    }
    productionHandoverActor($f, $f['user']);
    app(ProductionHandoverService::class)->cancelHandover($f['handover'], 'SYNTHETIC after owner reversal');
    expect($f['handover']->fresh()->status)->toBe('cancelled');
});

test('completed multi item runs reopen for replacement receipts only without recreating machine QC shift or labor facts', function (): void {
    $f = warehouseCorrectionFixture(true);
    $service = app(ProductionWarehouseReceiptCorrectionService::class);
    $originalDates = $f['receipt']->lines->map(fn ($line) => [$line->manufacture_date?->toDateString(), $line->expiry_date?->toDateString()])->all();
    $preview = $service->preview($f['receipt']);
    $proposal = $service->prepare($f['receipt'], 'SYNTHETIC exact receipt-only correction', $preview['fingerprint'], now()->toDateString());
    productionHandoverActor($f, $f['reviewer']);
    $service->approve($f['receipt'], $proposal->id);
    foreach ($f['runs'] as $run) {
        expect(app(ProductionReceiptCancellationService::class)->isReceiptOnlyRecovery($run->fresh()))->toBeTrue();
        expect(fn () => $f['cycle']->recordProgress($run->fresh(), ['good_base_quantity' => '1']))->toThrow(DomainException::class);
    }
    productionHandoverActor($f, $f['warehouse']);
    $replacement = app(ProductionHandoverService::class)->approveWarehouseReceipt(app(ProductionHandoverService::class)->createWarehouseReceipt($f['handover'], now()->toDateString(),
        $f['handover']->lines->map(fn ($line): array => ['line_public_id' => $line->public_id, 'quantity' => '10'])->all()));
    expect($replacement->lines->map(fn ($line) => [$line->manufacture_date?->toDateString(), $line->expiry_date?->toDateString()])->all())->toBe($originalDates);
    productionHandoverActor($f, $f['user']);
    $f['user']->givePermissionTo(Permission::findOrCreate('production.runs.complete', 'web'));
    $f['user']->givePermissionTo(Permission::findOrCreate('production.runs.account_materials', 'web'));
    test()->get(route('admin.production.runs.operation', [$f['runs']->first(), 'complete']))->assertOk()->assertSee('method="POST"', false);
    test()->get(route('admin.production.runs.operation', [$f['runs']->first(), 'account']))->assertOk()->assertDontSee('name="daily_progress_public_id"', false);
    foreach ($f['runs'] as $run) {
        $f['cycle']->completeRun($run->fresh());
        expect($run->fresh()->good_base_quantity)->toBe('10.00000000')->and($run->fresh()->received_base_quantity)->toBe('10.00000000')
            ->and($run->fresh()->progressEntries)->toHaveCount(1);
    }
});

test('warehouse reversal fingerprints refuse newer source changes and cannot execute an unapproved proposal', function (): void {
    $f = warehouseCorrectionFixture();
    $service = app(ProductionWarehouseReceiptCorrectionService::class);
    $preview = $service->preview($f['receipt']);
    $proposal = $service->prepare($f['receipt'], 'SYNTHETIC source fingerprint check', $preview['fingerprint'], now()->toDateString());
    productionHandoverActor($f, $f['reviewer']);
    expect(fn () => app(InventoryDocumentPostingService::class)->reverseForProductionWarehouseCorrection($f['receipt'], $proposal->id))->toThrow(DomainException::class);
    $f['runs']->first()->progressEntries->sole()->update(['notes' => 'SYNTHETIC newer operator evidence']);
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    expect(fn () => $service->approve($f['receipt'], $proposal->id))->toThrow(DomainException::class);
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($before)->and($f['receipt']->fresh()->status)->toBe('posted');
});

test('native factory warehouse recovery reopens only the receipt stage and preserves completed manufacturing and quality evidence', function (): void {
    $f = partialOutputFixture(factoryWorkflow: true);
    foreach (['production.handovers.create', 'production.handovers.approve'] as $permission) {
        $f['user']->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '10']);
    partialOutputApprove($f, '10');
    $handovers = app(ProductionHandoverService::class);
    $handover = $handovers->approveHandover($handovers->createHandover($f['run']->fresh(), $f['store']->id, now()->toDateString(),
        [['run_public_id' => $f['run']->public_id, 'quantity' => '10']]));
    $warehouse = closureSyntheticUser();
    foreach (['inventory.production_receipts.create', 'inventory.production_receipts.approve', 'inventory.production_receipts.correct_prepare'] as $permission) {
        $warehouse->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    productionHandoverActor($f, $warehouse);
    $receipt = $handovers->approveWarehouseReceipt($handovers->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $handover->lines->sole()->public_id, 'quantity' => '10']]));
    $f['cycle']->completeRun($f['run']->fresh());
    $stages = $f['order']->orderStageSnapshots->sortBy('sequence')->values();
    $manufacturing = $stages[1]->fresh()->getRawOriginal();
    $quality = $stages[2]->fresh()->getRawOriginal();
    $service = app(ProductionWarehouseReceiptCorrectionService::class);
    $preview = $service->preview($receipt);
    $proposal = $service->prepare($receipt, 'SYNTHETIC completed factory warehouse recovery', $preview['fingerprint'], now()->toDateString());
    $reviewer = closureSyntheticUser();
    $reviewer->givePermissionTo(Permission::findOrCreate('inventory.production_receipts.correct_approve', 'web'));
    productionHandoverActor($f, $reviewer);
    $service->approve($receipt, $proposal->id);
    expect($stages[3]->fresh()->status)->toBe('in_progress')
        ->and($stages[1]->fresh()->getRawOriginal())->toBe($manufacturing)
        ->and($stages[2]->fresh()->getRawOriginal())->toBe($quality)
        ->and(app(ProductionReceiptCancellationService::class)->isReceiptOnlyRecovery($f['run']->fresh()))->toBeTrue();
    productionHandoverActor($f, $warehouse);
    $handovers->approveWarehouseReceipt($handovers->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $handover->lines->sole()->public_id, 'quantity' => '10']]));
    $f['cycle']->completeRun($f['run']->fresh());
    expect($stages[3]->fresh()->status)->toBe('completed')->and($f['run']->fresh()->progressEntries)->toHaveCount(1);
});

test('header-null multi-line receipt cancellation review opens its warehouse owner and never generic inventory reversal', function (string $locale): void {
    $f = warehouseCorrectionFixture();
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    foreach (['inventory.documents.view', 'inventory.documents.reverse'] as $key) {
        $f['warehouse']->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    expect($f['receipt']->production_run_id)->toBeNull()->and($f['receipt']->isHandoverWarehouseReceipt())->toBeTrue();
    $url = route('admin.inventory.documents.show', $f['receipt']);
    $owner = route('admin.inventory.production-receipts.corrections.index', $f['receipt']);
    $this->get($url)->assertOk()->assertSee($owner, false)
        ->assertDontSee(route('admin.inventory.documents.reversal-preview', $f['receipt']), false);
    $this->get($owner)->assertOk();
    $f['warehouse']->revokePermissionTo(['inventory.production_receipts.correct_prepare', 'inventory.production_receipts.correct_approve']);
    $this->get($url)->assertOk()->assertDontSee($owner, false)
        ->assertDontSee(route('admin.inventory.documents.reversal-preview', $f['receipt']), false);
    $this->get($owner)->assertForbidden();
})->with(['ar', 'en']);
