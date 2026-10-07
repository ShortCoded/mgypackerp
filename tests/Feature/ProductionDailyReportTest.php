<?php

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\HR\Models\HrShift;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionDailyReportService;
use Modules\Production\Services\ProductionHandoverService;
use Modules\Production\Services\ProductionQualityQuantityService;
use Modules\Production\Services\ProductionShiftEvidenceService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProductionPartialOutputEvidenceSupport.php';
require_once __DIR__.'/../ProductionHandoverSupport.php';

/** @return array<string, mixed> */
function dailyReportFixture(): array
{
    $f = partialOutputFixture('measured_material');
    $shift = HrShift::query()->create(['doc_number' => 992001, 'doc_num' => 'SYNTHETIC-DAILY-A',
        'name' => 'SYNTHETIC الوردية أ', 'start_time' => '12:00', 'end_time' => '20:00', 'status' => 'active']);
    test()->travelTo(Carbon::parse('2026-09-29 13:00:00'));
    foreach (['production.handovers.view', 'production.handovers.create', 'production.handovers.approve', 'production.handovers.cancel'] as $key) {
        $f['user']->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }

    return [...$f, 'hrShift' => $shift, 'dailyData' => ['sheet_kind' => 'injection', 'hr_shift_id' => $shift->id,
        'work_date' => '2026-09-29', 'working_hours' => '1', 'technician_names' => 'SYNTHETIC اسم بالتقرير فقط',
        'lines' => [['run_public_id' => $f['run']->public_id, 'quantity' => '6', 'cavities' => '8', 'piece_weight_grams' => '10',
            'actual_waste' => '1', 'waste_unit' => 'kg', 'notes' => 'SYNTHETIC actual machine output; raw consumption not measured here']]]];
}

test('injection paper quantities record output only and material owner settlement unlocks independent partial warehouse posting', function (): void {
    $f = dailyReportFixture();
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    $entry = app(ProductionDailyReportService::class)->record($f['run'], $f['dailyData'])->sole();
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($before)
        ->and($f['run']->fresh()->good_base_quantity)->toBe('6.00000000')
        ->and($entry->material_evidence)->toBeNull()->and($entry->material_documents)->toBeNull()
        ->and($f['requirement']->fresh()->consumed_quantity)->toBe('0.00000000');
    $report = app(ProductionShiftEvidenceService::class)->report($f['run']->fresh())->sole();
    expect($report->sheet_fields['time_basis'])->toBe('hr_schedule')->and($report->sheet_fields['actual_waste'])->toBe('1')
        ->and($report->crew_snapshot)->toBe([])->and($report->output_pieces)->toBeNull();
    $printed = view('reports.production.partials.shift-sheet', ['record' => $f['run']->fresh(), 'entry' => $report,
        'numbers' => app(NumericFormatService::class), 'dates' => app(DateFormatService::class)])->render();
    expect($printed)->toContain(__('production_daily_report.derived_time_help'))->toContain($f['dailyData']['technician_names'])
        ->not->toContain(__('production_execution.shift_evidence.actual_start'));
    partialOutputApprove($f, '6', '4');
    expect(app(ProductionQualityQuantityService::class)->availableQuantity($f['run']->fresh()))->toBe('4.00000000');
    $handoverService = app(ProductionHandoverService::class);
    $handover = $handoverService->approveHandover($handoverService->createHandover($f['run']->fresh(), $f['store']->id, now()->toDateString(),
        [['run_public_id' => $f['run']->public_id, 'quantity' => '4']]));
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    $warehouse = closureSyntheticUser();
    foreach (['inventory.production_receipts.create', 'inventory.production_receipts.approve'] as $key) {
        $warehouse->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    $this->actingAs($warehouse);
    request()->attributes->replace([]);
    request()->setUserResolver(fn () => $warehouse);
    $receipt = $handoverService->createWarehouseReceipt($handover, now()->toDateString(), [['line_public_id' => $handover->lines->sole()->public_id, 'quantity' => '4']]);
    expect(fn () => $handoverService->approveWarehouseReceipt($receipt))->toThrow(DomainException::class, __('production_daily_report.materials_pending'));
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    $this->actingAs($f['user']);
    request()->attributes->replace([]);
    request()->setUserResolver(fn () => $f['user']);
    $accounting = [$f['requirement']->id => ['consumed_quantity' => '12', 'waste_quantity' => '0']];
    $documents = $f['cycle']->accountMaterials($f['run']->fresh(), $f['store']->id, $accounting, dailyProgress: $entry);
    $after = [InventoryTransaction::count(), JournalEntry::count()];
    $f['cycle']->accountMaterials($f['run']->fresh(), $f['store']->id, $accounting, dailyProgress: $entry);
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($after)
        ->and($f['requirement']->fresh()->consumed_quantity)->toBe('12.00000000')
        ->and($entry->fresh()->material_documents[0]['id'])->toBe($documents['consumption']->id);
    $this->actingAs($warehouse);
    request()->attributes->replace([]);
    request()->setUserResolver(fn () => $warehouse);
    $receipt = $handoverService->approveWarehouseReceipt($receipt);
    expect($receipt->status)->toBe(InventoryDocument::StatusPosted)
        ->and($receipt->lines->sole()->total_cost)->toBe('80.00000000')
        ->and($f['run']->fresh()->received_base_quantity)->toBe('4.00000000')
        ->and(app(ProductionQualityQuantityService::class)->availableQuantity($f['run']->fresh()))->toBe('0.00000000');
});

test('daily report HTTP validates paper fields and retries once while the run summary exposes separate actions in both locales', function (): void {
    $f = dailyReportFixture();
    $payload = [...$f['dailyData'], '_submission_token' => (string) Str::uuid()];
    $url = route('admin.production.runs.daily-reports.store', $f['run']);
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk();
    expect($f['run']->fresh()->progressEntries()->count())->toBe(1)
        ->and($f['run']->fresh()->good_base_quantity)->toBe('6.00000000');
    $payload['lines'][0]['quantity'] = '7';
    $this->postJson($url, $payload)->assertConflict();
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->withSession(['locale' => $locale]);
        $this->get(route('admin.production.runs.daily-reports.create', $f['run']))->assertOk()
            ->assertSee('data-daily-report-line', false)->assertDontSee('name="good_weight_kg"', false)->assertDontSee('name="material_evidence', false);
        $this->withoutExceptionHandling();
        $this->get(route('admin.production.runs.show', $f['run']))->assertOk()
            ->assertSee('data-production-actions', false)->assertDontSee('name="base_quantity"', false);
        $this->get(route('admin.production.runs.operation', [$f['run'], 'account']))->assertOk()->assertSee('daily_progress_public_id', false);
    }
    $this->withExceptionHandling();
    $this->postJson(route('admin.production.runs.receive', $f['run']), ['_submission_token' => (string) Str::uuid(),
        'branch_store_id' => $f['store']->id, 'base_quantity' => '1'])->assertUnprocessable();
});

test('cover paper report preserves actual clock and packing facts without requiring injection measurements', function (): void {
    $f = dailyReportFixture();
    $data = [...$f['dailyData'], 'sheet_kind' => 'cover', 'started_time' => '12:00', 'ended_time' => '13:00',
        'lines' => [['run_public_id' => $f['run']->public_id, 'quantity' => '6', 'customer_name' => 'SYNTHETIC عميل مذكور بالورقة', 'actual_pieces' => '60', 'carton_count' => '6', 'packing_ratio' => '10', 'material_used' => '3', 'material_unit' => 'roll']]];
    unset($data['working_hours']);
    app(ProductionDailyReportService::class)->record($f['run'], $data);
    $entry = app(ProductionShiftEvidenceService::class)->report($f['run']->fresh())->sole();
    expect($entry->sheet_fields['customer_name'])->toBe('SYNTHETIC عميل مذكور بالورقة')
        ->and($entry->sheet_fields['packing_ratio'])->toBe('10')->and($entry->sheet_fields['time_basis'])->toBe('reported_clock')
        ->and($entry->output_pieces)->toBe('60')->and($entry->sheet_fields)->not->toHaveKey('cavities');
    expect(fn () => app(ProductionDailyReportService::class)->record($f['run']->fresh(), $data))->toThrow(DomainException::class);
});

test('daily measured waste requires the native classification and reason and remains atomic before settlement', function (): void {
    $f = dailyReportFixture();
    $entry = app(ProductionDailyReportService::class)->record($f['run'], $f['dailyData'])->sole();
    $before = [InventoryTransaction::count(), JournalEntry::count(), InventoryDocument::count()];
    $accounting = [$f['requirement']->id => ['consumed_quantity' => '11', 'waste_quantity' => '1']];
    expect(fn () => $f['cycle']->accountMaterials($f['run']->fresh(), $f['store']->id, $accounting, dailyProgress: $entry))
        ->toThrow(DomainException::class, __('production_execution.evidence.waste_details_required'));
    expect([InventoryTransaction::count(), JournalEntry::count(), InventoryDocument::count()])->toBe($before)
        ->and($entry->fresh()->material_documents)->toBeNull();
    $accounting[$f['requirement']->id] += ['waste_classification' => 'roll_trim', 'notes' => 'SYNTHETIC actual measured trim'];
    $documents = $f['cycle']->accountMaterials($f['run']->fresh(), $f['store']->id, $accounting, dailyProgress: $entry);
    expect($f['requirement']->fresh()->waste_quantity)->toBe('1.00000000')
        ->and($entry->fresh()->material_evidence[0]['waste_classification'])->toBe('roll_trim')
        ->and($entry->fresh()->material_evidence[0]['unit_name'])->not->toBeNull()
        ->and(collect($documents)->firstWhere('document_type', InventoryDocument::TypeProductionWaste)->purpose)->toContain('roll_trim')->toContain('SYNTHETIC actual measured trim');
});

test('a daily sheet on an existing legacy run capitalizes measured output only and retains future staged materials', function (): void {
    $f = dailyReportFixture();
    $f['run']->update(['material_accounting_mode' => 'legacy', 'material_evidence_policy' => null]);
    $entry = app(ProductionDailyReportService::class)->record($f['run']->fresh(), $f['dailyData'])->sole();
    partialOutputApprove($f, '6');
    $f['cycle']->accountMaterials($f['run']->fresh(), $f['store']->id,
        [$f['requirement']->id => ['consumed_quantity' => '12', 'waste_quantity' => '0']], dailyProgress: $entry);
    $service = app(ProductionHandoverService::class);
    $handover = $service->approveHandover($service->createHandover($f['run']->fresh(), $f['store']->id, now()->toDateString(),
        [['run_public_id' => $f['run']->public_id, 'quantity' => '6']]));
    $warehouse = closureSyntheticUser();
    foreach (['inventory.production_receipts.create', 'inventory.production_receipts.approve'] as $key) {
        $warehouse->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    $this->actingAs($warehouse);
    request()->attributes->replace([]);
    request()->setUserResolver(fn () => $warehouse);
    $receipt = $service->approveWarehouseReceipt($service->createWarehouseReceipt($handover, now()->toDateString(),
        [['line_public_id' => $handover->lines->sole()->public_id, 'quantity' => '6']]));
    expect($receipt->lines->sole()->total_cost)->toBe('120.00000000')
        ->and($f['run']->fresh()->material_accounting_mode)->toBe('legacy')
        ->and($f['requirement']->fresh()->consumed_quantity)->toBe('12.00000000')
        ->and(app(ProductionCostService::class)->runPosition($f['run']->fresh())['wip'])->toBe('80.00000000');
    expect(fn () => $f['cycle']->completeRun($f['run']->fresh()))->toThrow(DomainException::class);
});

test('daily paper keeps per-item technicians hours clocks and recorded roll identity with shared defaults', function (string $kind, string $locale): void {
    $f = productionHandoverFixture(recordOutput: false);
    foreach (['production.runs.progress', 'production.runs.view'] as $key) {
        $f['user']->givePermissionTo(Permission::findOrCreate($key, 'web'));
    }
    productionHandoverActor($f, $f['user']);
    $this->travelTo(Carbon::parse('2026-09-29 23:59:00'));
    app()->setLocale($locale);
    $this->withSession(['locale' => $locale]);
    $shift = HrShift::query()->create(['doc_number' => 992002, 'doc_num' => 'SYNTHETIC-PAPER-LINES',
        'name' => 'SYNTHETIC shared shift', 'start_time' => '12:00', 'end_time' => '20:00', 'status' => 'active']);
    $runs = $f['runs']->values();
    $anchor = $runs->first();
    $lines = $runs->map(fn ($run): array => ['run_public_id' => $run->public_id, 'quantity' => '1'])->all();
    $lines[0] += ['technician_names' => 'SYNTHETIC item technician A', 'working_hours' => '8',
        'started_time' => '12:00', 'ended_time' => '13:00', 'material_name' => 'SYNTHETIC PET / PP',
        'roll_reference' => 'SYNTHETIC ROLL-023', 'material_used' => '2', 'material_unit' => 'roll'];
    $lines[1] += ['technician_names' => 'SYNTHETIC item technician B', 'working_hours' => '4',
        'started_time' => '13:00', 'ended_time' => '14:00'];
    $data = ['sheet_kind' => $kind, 'hr_shift_id' => $shift->id, 'work_date' => '2026-09-29',
        'working_hours' => '2', 'started_time' => '14:00', 'ended_time' => '15:00',
        'technician_names' => 'SYNTHETIC shared technician C', 'lines' => $lines];
    $before = [InventoryTransaction::count(), JournalEntry::count()];
    $this->postJson(route('admin.production.runs.daily-reports.store', $anchor), [...$data,
        '_submission_token' => (string) Str::uuid()])->assertOk();
    expect([InventoryTransaction::count(), JournalEntry::count()])->toBe($before);
    $snapshots = [];
    foreach ($runs as $index => $run) {
        $entry = app(ProductionShiftEvidenceService::class)->report($run->fresh())->sole();
        $snapshots[$run->id] = $entry->sheet_fields;
        expect($entry->sheet_fields['technician_names'])->toBe([
            'SYNTHETIC item technician A', 'SYNTHETIC item technician B', 'SYNTHETIC shared technician C'][$index])
            ->and($entry->sheet_fields['time_basis'])->toBe($kind === 'injection' ? 'hr_schedule' : 'reported_clock')
            ->and($entry->crew_snapshot)->toBe([])->and($run->fresh()->good_base_quantity)->toBe('1.00000000');
        if ($kind === 'injection') {
            expect(bccomp((string) $entry->working_hours, ['8', '4', '2'][$index], 4))->toBe(0);
        } else {
            expect(Carbon::parse($entry->started_at)->format('H:i'))->toBe(['12:00', '13:00', '14:00'][$index])
                ->and(Carbon::parse($entry->ended_at)->format('H:i'))->toBe(['13:00', '14:00', '15:00'][$index]);
        }
        $printed = view('reports.production.partials.shift-sheet', ['record' => $run->fresh(), 'entry' => $entry,
            'numbers' => app(NumericFormatService::class), 'dates' => app(DateFormatService::class),
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr'])->render();
        expect($printed)->toContain($entry->sheet_fields['technician_names']);
        if ($kind === 'cover' && $index === 0) {
            expect($printed)->toContain('SYNTHETIC PET / PP')->toContain('SYNTHETIC ROLL-023');
        }
    }
    $shift->update(['name' => 'SYNTHETIC renamed shift', 'start_time' => '08:00']);
    foreach ($runs as $run) {
        expect(app(ProductionShiftEvidenceService::class)->report($run->fresh())->sole()->sheet_fields)->toBe($snapshots[$run->id]);
    }
    $form = $this->get(route('admin.production.runs.daily-reports.create', [$anchor, 'kind' => $kind]))->assertOk()
        ->assertSee('lines[0][technician_names]', false);
    if ($kind === 'cover') {
        $form->assertSee('lines[0][started_time]', false)->assertSee('lines[0][ended_time]', false)
            ->assertSee('lines[0][material_name]', false)->assertSee('lines[0][roll_reference]', false);
    } else {
        $form->assertSee('lines[0][working_hours]', false)->assertSee(__('production_daily_report.derived_time_help'));
    }
})->with([['injection', 'ar'], ['injection', 'en'], ['cover', 'ar'], ['cover', 'en']]);

test('per-line daily overrides reject incomplete clock pairs and out-of-range hours without recording any output', function (): void {
    $f = dailyReportFixture();
    $url = route('admin.production.runs.daily-reports.store', $f['run']);
    $cover = [...$f['dailyData'], 'sheet_kind' => 'cover', 'started_time' => '12:00', 'ended_time' => '13:00'];
    $cover['lines'][0]['started_time'] = '12:05';
    $this->postJson($url, [...$cover, '_submission_token' => (string) Str::uuid()])->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.ended_time');
    $injection = $f['dailyData'];
    $injection['lines'][0]['working_hours'] = '25';
    $this->postJson($url, [...$injection, '_submission_token' => (string) Str::uuid()])->assertUnprocessable()
        ->assertJsonValidationErrors('lines.0.working_hours');
    expect($f['run']->fresh()->good_base_quantity)->toBe('0.00000000')
        ->and($f['run']->progressEntries()->count())->toBe(0)
        ->and(app(ProductionShiftEvidenceService::class)->entries($f['run'])->count())->toBe(0);
});
