<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\ActivityLogger;
use Modules\HR\Models\HrShift;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionDailyReportCorrectionService;
use Modules\Production\Services\ProductionDailyReportService;
use Modules\Production\Services\ProductionHandoverService;
use Modules\Production\Services\ProductionQualityQuantityService;
use Modules\Production\Services\ProductionRunCorrectionService;
use Modules\Production\Services\ProductionShiftEvidenceService;
use Modules\Production\Services\ProductionWarehouseReceiptCorrectionService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProductionPartialOutputEvidenceSupport.php';
require_once __DIR__.'/../ProductionHandoverSupport.php';

/** @return array<string, mixed> */
function dailyCorrectionFixture(): array
{
    $f = partialOutputFixture('measured_material');
    test()->travelTo(Carbon::parse('2026-09-29 13:00:00'));
    foreach (['production.runs.correct', 'production.runs.correct_approve', 'production.handovers.create', 'production.handovers.approve', 'production.handovers.cancel',
        'inventory.production_receipts.create', 'inventory.production_receipts.approve', 'inventory.production_receipts.correct_prepare', 'inventory.production_receipts.correct_approve'] as $key) {
        $f['user']->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    $reviewer = closureSyntheticUser();
    foreach (['production.runs.correct', 'production.runs.correct_approve', 'production.quality.review', 'inventory.production_receipts.correct_approve', 'inventory.production_receipts.create', 'inventory.production_receipts.approve'] as $key) {
        $reviewer->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    $shift = HrShift::query()->create(['doc_number' => 993003, 'doc_num' => 'SYNTHETIC-DAILY-CORRECTION', 'name' => 'SYNTHETIC correction shift',
        'start_time' => '12:00', 'end_time' => '20:00', 'status' => 'active']);
    productionHandoverActor($f, $f['user']);
    $entry = app(ProductionDailyReportService::class)->record($f['run'], ['sheet_kind' => 'injection', 'hr_shift_id' => $shift->id,
        'work_date' => '2026-09-29', 'working_hours' => '1', 'lines' => [['run_public_id' => $f['run']->public_id, 'quantity' => '6']]])->sole();

    return [...$f, ...compact('reviewer', 'entry')];
}

test('daily quantity correction and cancellation append independent evidence and retain original shift materials and ledgers', function (string $locale): void {
    $f = dailyCorrectionFixture();
    app()->setLocale($locale);
    $service = app(ProductionDailyReportCorrectionService::class);
    $original = $f['entry']->fresh()->getRawOriginal();
    $shift = (array) DB::table('production_shift_entries')->find($f['entry']->production_shift_entry_id);
    $ledger = [JournalEntry::count(), InventoryTransaction::count()];
    $this->get(route('admin.production.runs.daily-reports.correction', [$f['run'], $f['entry']->public_id]))->assertOk()->assertSee(__('production_daily_report.correction.title'));
    $preview = $service->preview($f['run'], $f['entry']->public_id);
    $payload = ['quantity' => '4', 'reason' => 'SYNTHETIC source quantity mistyped', 'evidence' => 'SYNTHETIC measured count ref 001',
        'fingerprint' => $preview['fingerprint'], 'posting_date' => now()->toDateString(), 'document_error' => true, '_submission_token' => (string) Str::uuid()];
    $url = route('admin.production.runs.daily-reports.correction-prepare', [$f['run'], $f['entry']->public_id]);
    $response = $this->postJson($url, $payload)->assertOk();
    $id = $response->json('data.correction_id');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.correction_id', $id);
    expect(fn () => $service->approve($f['run'], $id))->toThrow(DomainException::class);
    productionHandoverActor($f, $f['reviewer']);
    app(ProductionRunCorrectionService::class)->approve($f['run'], $id);
    $service->approve($f['run'], $id);
    expect($f['run']->fresh()->good_base_quantity)->toBe('4.00000000')->and($f['run']->fresh()->correction_sequence)->toBe(0)
        ->and($f['entry']->fresh()->getRawOriginal())->toBe($original)->and((array) DB::table('production_shift_entries')->find($f['entry']->production_shift_entry_id))->toBe($shift)
        ->and($f['requirement']->fresh()->consumed_quantity)->toBe('0.00000000')->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($ledger);
    $report = app(ProductionShiftEvidenceService::class)->report($f['run']->fresh())->sole();
    expect($report->good_base_quantity)->toBe('4.00000000')->and($report->sheet_fields['reported_quantity'])->toBe('6');
    productionHandoverActor($f, $f['user']);
    $preview = $service->preview($f['run'], $f['entry']->public_id);
    $cancel = $service->prepare($f['run'], $f['entry']->public_id, '0', 'SYNTHETIC entire entry misattributed', 'SYNTHETIC measured count ref 002', $preview['fingerprint'], now()->toDateString());
    productionHandoverActor($f, $f['reviewer']);
    $service->approve($f['run'], $cancel->id);
    expect($f['run']->fresh()->good_base_quantity)->toBe('0.00000000')->and($f['run']->fresh()->progressEntries()->count())->toBe(3)
        ->and($f['run']->fresh()->status)->toBe('running')->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($ledger)
        ->and(DB::table('activity_log')->where('event', 'production.daily_report_correction.approved')->where('subject_id', $f['run']->id)->count())->toBe(2);
})->with(['ar', 'en']);

test('daily correction requires legitimate warehouse handover and independent quality owner recovery without unconsuming materials', function (): void {
    $f = dailyCorrectionFixture();
    $service = app(ProductionDailyReportCorrectionService::class);
    $baselineDifferences = collect(app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id))->pluck('difference', 'key')->all();
    partialOutputApprove($f, '6');
    $f['cycle']->accountMaterials($f['run']->fresh(), $f['store']->id,
        [$f['requirement']->id => ['consumed_quantity' => '12', 'waste_quantity' => '0']], dailyProgress: $f['entry']);
    $handoverService = app(ProductionHandoverService::class);
    $handover = $handoverService->approveHandover($handoverService->createHandover($f['run']->fresh(), $f['store']->id, now()->toDateString(), [['run_public_id' => $f['run']->public_id, 'quantity' => '6']]));
    productionHandoverActor($f, $f['reviewer']);
    $receipt = $handoverService->approveWarehouseReceipt($handoverService->createWarehouseReceipt($handover, now()->toDateString(), [['line_public_id' => $handover->lines->sole()->public_id, 'quantity' => '6']]));
    $batch = DB::table('production_quality_output_batches')->where('production_run_id', $f['run']->id)->first();
    $quality = app(ProductionQualityQuantityService::class);
    expect($service->preview($f['run'], $f['entry']->public_id)['blockers'])->not->toBeEmpty();
    productionHandoverActor($f, $f['reviewer']);
    expect(fn () => $quality->withdrawForDailyCorrection($f['run']->fresh(), $batch->id, 'SYNTHETIC wrong source output', 'SYNTHETIC counted qty'))->toThrow(DomainException::class);
    productionHandoverActor($f, $f['user']);
    $warehouse = app(ProductionWarehouseReceiptCorrectionService::class);
    $proposal = $warehouse->prepare($receipt, 'SYNTHETIC receipt quantity incorrect', $warehouse->preview($receipt)['fingerprint'], now()->toDateString());
    productionHandoverActor($f, $f['reviewer']);
    $warehouse->approve($receipt, $proposal->id);
    expect($warehouse->hasVerifiedReversal($receipt->fresh()))->toBeTrue();
    productionHandoverActor($f, $f['user']);
    $handoverService->cancelHandover($handover, 'SYNTHETIC after receipt owner inverse');
    productionHandoverActor($f, $f['reviewer']);
    $inspection = $f['run']->fresh()->inspections->sole()->getRawOriginal();
    $quality->withdrawForDailyCorrection($f['run']->fresh(), $batch->id, 'SYNTHETIC wrong output count', 'SYNTHETIC count ref 003');
    expect($service->preview($f['run'], $f['entry']->public_id)['blockers'])->toBe([]);
    $ledger = [JournalEntry::count(), InventoryTransaction::count()];
    productionHandoverActor($f, $f['user']);
    $preview = $service->preview($f['run'], $f['entry']->public_id);
    $proposal = $service->prepare($f['run'], $f['entry']->public_id, '4', 'SYNTHETIC incorrect daily quantity', 'SYNTHETIC physical count ref 003', $preview['fingerprint'], now()->toDateString());
    productionHandoverActor($f, $f['reviewer']);
    $service->approve($f['run'], $proposal->id);
    expect($f['run']->fresh()->good_base_quantity)->toBe('4.00000000')->and($f['run']->fresh()->received_base_quantity)->toBe('0.00000000')
        ->and($f['requirement']->fresh()->consumed_quantity)->toBe('12.00000000')->and($f['run']->fresh()->inspections->sole()->getRawOriginal())->toBe($inspection)
        ->and($quality->availableQuantity($f['run']->fresh()))->toBe('0.00000000')->and([JournalEntry::count(), InventoryTransaction::count()])->toBe($ledger);
    $inverse = $receipt->transactions()->where('is_reversal', true)->sole();
    $cost = $inverse->total_cost;
    $inverse->update(['total_cost' => '119']);
    expect($warehouse->hasVerifiedReversal($receipt->fresh()))->toBeFalse()->and($service->preview($f['run'], $f['entry']->public_id)['blockers'])->not->toBeEmpty();
    $inverse->update(['total_cost' => $cost]);
    productionHandoverActor($f, $f['user']);
    partialOutputApprove($f, '4');
    $replacementHandover = $handoverService->approveHandover($handoverService->createHandover($f['run']->fresh(), $f['store']->id, now()->toDateString(),
        [['run_public_id' => $f['run']->public_id, 'quantity' => '4']]));
    productionHandoverActor($f, $f['reviewer']);
    $replacement = $handoverService->approveWarehouseReceipt($handoverService->createWarehouseReceipt($replacementHandover, now()->toDateString(),
        [['line_public_id' => $replacementHandover->lines->sole()->public_id, 'quantity' => '4']]));
    expect($replacement->lines->sole()->total_cost)->toBe('120.00000000')->and($replacement->lines->sole()->unit_cost)->toBe('30.00000000')
        ->and($f['run']->fresh()->received_base_quantity)->toBe('4.00000000')->and($f['requirement']->fresh()->consumed_quantity)->toBe('12.00000000')
        ->and(app(ProductionCostService::class)->runPosition($f['run']->fresh())['wip'])->toBe('80.00000000');
    foreach (app(InventoryGlReconciliationService::class)->reconcile($f['company']->id, $f['period']->id, $f['branch']->id) as $row) {
        expect($row['difference'])->toBe($baselineDifferences[$row['key']]);
        if (in_array($row['key'], ['wip', 'finished_goods'], true)) {
            expect($row['difference'])->toBe('0.0000');
        }
    }
});

test('daily correction rejects tampered proposal and stale original evidence atomically and denies an unprivileged reader', function (): void {
    $f = dailyCorrectionFixture();
    $service = app(ProductionDailyReportCorrectionService::class);
    $proposal = $service->prepare($f['run'], $f['entry']->public_id, '4', 'SYNTHETIC wrong original', 'SYNTHETIC measured count', $service->preview($f['run'], $f['entry']->public_id)['fingerprint'], now()->toDateString());
    $output = json_decode($proposal->corrected_output, true);
    $output['quantity'] = '3.00000000';
    DB::table('production_run_corrections')->where('id', $proposal->id)->update(['corrected_output' => json_encode($output)]);
    productionHandoverActor($f, $f['reviewer']);
    expect(fn () => $service->approve($f['run'], $proposal->id))->toThrow(DomainException::class);
    DB::table('production_run_corrections')->where('id', $proposal->id)->update(['corrected_output' => $proposal->corrected_output]);
    $audit = Mockery::mock(ActivityLogger::class)->makePartial();
    $audit->shouldReceive('log')->withArgs(fn ($request, $module, $event): bool => $event === 'production.daily_report_correction.approved')->andThrow(new RuntimeException('SYNTHETIC audit rollback'));
    app()->instance(ActivityLogger::class, $audit);
    expect(fn () => $service->approve($f['run'], $proposal->id))->toThrow(RuntimeException::class, 'SYNTHETIC audit rollback')
        ->and($f['run']->fresh()->good_base_quantity)->toBe('6.00000000')->and($f['run']->fresh()->progressEntries()->count())->toBe(1)
        ->and(DB::table('production_run_corrections')->find($proposal->id)->status)->toBe('prepared');
    app()->forgetInstance(ActivityLogger::class);
    $f['entry']->update(['notes' => 'SYNTHETIC new evidence after preparation']);
    expect(fn () => $service->approve($f['run'], $proposal->id))->toThrow(DomainException::class)
        ->and($f['run']->fresh()->good_base_quantity)->toBe('6.00000000')->and($f['run']->fresh()->progressEntries()->count())->toBe(1);
    $viewer = closureSyntheticUser();
    productionHandoverActor($f, $viewer);
    $this->get(route('admin.production.runs.daily-reports.correction', [$f['run'], $f['entry']->public_id]))->assertForbidden();
});
