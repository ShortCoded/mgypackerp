<?php

use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrShift;
use Modules\Production\Models\ProductionShift;
use Modules\Production\Services\ProductionCostService;
use Modules\Production\Services\ProductionMaterialRequestService;
use Modules\Production\Services\ProductionReportService;
use Modules\Production\Services\ProductionShiftEvidenceService;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/../ProductionPartialOutputEvidenceSupport.php';

function shiftEvidenceEmployee(array $fixture, string $name): HrEmployee
{
    $number = max(999001, (int) HrEmployee::withTrashed()->max('doc_number') + 1);

    return HrEmployee::query()->create(['company_id' => $fixture['company']->id, 'branch_id' => $fixture['branch']->id,
        'doc_number' => $number, 'doc_num' => 'SYNTHETIC-SHIFT-'.$number, 'employee_code' => 'SYNTHETIC-SHIFT-'.$number,
        'full_name' => $name, 'name' => $name, 'person_type' => 'regular_labor', 'status' => 'active']);
}

function shiftEvidenceDefaults(HrEmployee $employee): array
{
    return ['hr_shift_id' => HrShift::query()->firstOrCreate(['name' => 'SYNTHETIC shift A'],
        ['doc_number' => 999001, 'doc_num' => 'SYNTHETIC-HR-SHIFT-A', 'start_time' => '12:00', 'end_time' => '20:00', 'status' => 'active'])->id,
        'crew' => [['employee_id' => $employee->id, 'role' => 'technician', 'planned_hours' => '8']]];
}

test('closed runs cannot change their historical HR shift evidence requirement', function (): void {
    $f = partialOutputFixture();
    $employee = shiftEvidenceEmployee($f, 'SYNTHETIC closed run technician');
    $f['run']->update(['status' => 'completed']);
    expect(fn () => app(ProductionShiftEvidenceService::class)->saveDefaults($f['run'], shiftEvidenceDefaults($employee)))
        ->toThrow(DomainException::class);
    expect($f['run']->fresh()->uses_hr_shift_evidence)->toBeFalse();
});

test('HR daily shift snapshots drive scoped control lookup and filtering without duplicating runs', function (): void {
    $f = partialOutputFixture();
    $f['branch']->update(['type' => Branch::TypeFactory]);
    Permission::findOrCreate('production.reports.control.view', 'web');
    $f['user']->givePermissionTo('production.reports.control.view');
    $employee = shiftEvidenceEmployee($f, 'SYNTHETIC HR report technician');
    $service = app(ProductionShiftEvidenceService::class);
    $shift = $service->saveDefaults($f['run'], shiftEvidenceDefaults($employee));
    $service->record($f['run'], ['hr_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(), 'sheet_fields' => ['sheet_kind' => 'injection']]);
    $snapshotName = $shift->name;
    $shift->update(['name' => 'SYNTHETIC renamed HR master']);
    $this->getJson(route('admin.production.reports.control.lookup', ['kind' => 'shift', 'q' => $snapshotName]))->assertOk()->assertJsonPath('results.0.id', $snapshotName);
    $report = app(ProductionReportService::class)->controlReport($f['company']->id, $f['period']->id, [$f['branch']->id], ['shift' => $snapshotName]);
    expect($report['controlRuns']->modelKeys())->toBe([$f['run']->id]);
    $this->getJson(route('admin.production.reports.control.lookup', ['kind' => 'shift', 'q' => 'SYNTHETIC renamed HR master']))->assertOk()->assertJsonPath('results', []);
});

test('machine crew defaults and daily overrides retain the previous recorded crew and piece conversion', function (): void {
    $f = partialOutputFixture();
    $service = app(ProductionShiftEvidenceService::class);
    $first = shiftEvidenceEmployee($f, 'SYNTHETIC original technician');
    $second = shiftEvidenceEmployee($f, 'SYNTHETIC replacement technician');
    $override = shiftEvidenceEmployee($f, 'SYNTHETIC daily override');
    $shift = $service->saveDefaults($f['run'], shiftEvidenceDefaults($first));
    $entry = $service->record($f['run'], ['hr_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(),
        'sheet_fields' => ['sheet_kind' => 'injection', 'pack_ratio' => '1', 'cavities' => 8]]);
    $this->travel(10)->minutes();
    $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '1', 'production_shift_entry_id' => $entry->id, 'notes' => 'SYNTHETIC actual shift progress']);
    $service->close($f['run'], $entry->id, now()->toDateTimeString());
    $service->saveDefaults($f['run'], shiftEvidenceDefaults($second));
    $this->travel(1)->days();
    $newEntry = $service->record($f['run'], ['hr_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(),
        'crew' => [['employee_id' => $override->id, 'role' => 'supervisor']], 'sheet_fields' => ['sheet_kind' => 'cover', 'pack_ratio' => '1']]);
    $report = $service->report($f['run']);
    expect($report->first()->crew_snapshot[0]['employee_id'])->toBe($first->id)->and($report->last()->crew_snapshot[0]['employee_id'])->toBe($override->id)
        ->and($report->first()->output_pieces)->toBe('1.00000000')->and($report->first()->working_hours)->toBe('0.16666666')
        ->and($report->first()->notes_log[0]['notes'])->toBe('SYNTHETIC actual shift progress');
    expect(fn () => $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '1', 'production_shift_entry_id' => $entry->id]))->toThrow(DomainException::class);
});

test('shift crew and pack metadata reject foreign workers duplicate records and recipe conversion drift', function (): void {
    $f = partialOutputFixture();
    $service = app(ProductionShiftEvidenceService::class);
    $employee = shiftEvidenceEmployee($f, 'SYNTHETIC technician');
    $shift = $service->saveDefaults($f['run'], shiftEvidenceDefaults($employee));
    $payload = ['hr_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(), 'sheet_fields' => ['sheet_kind' => 'cover', 'pack_ratio' => '2']];
    expect(fn () => $service->record($f['run'], $payload))->toThrow(DomainException::class);
    $payload['sheet_fields']['pack_ratio'] = '1';
    $service->record($f['run'], $payload);
    expect(fn () => $service->record($f['run'], $payload))->toThrow(DomainException::class);
    $employee->update(['branch_id' => Branch::query()->where('company_id', '!=', $f['company']->id)->firstOrFail()->id]);
    expect(fn () => $service->saveDefaults($f['run'], shiftEvidenceDefaults($employee)))->toThrow(DomainException::class);
});

test('native daily shift entry and report render in Arabic and English and preserve idempotency', function (): void {
    $f = partialOutputFixture();
    $f['branch']->update(['type' => Branch::TypeFactory]);
    foreach (['production.runs.print'] as $name) {
        Permission::findOrCreate($name, 'web');
        $f['user']->givePermissionTo($name);
    }
    $employee = shiftEvidenceEmployee($f, 'SYNTHETIC browser ready technician');
    $payload = [...shiftEvidenceDefaults($employee), '_submission_token' => (string) Str::uuid()];
    $url = route('admin.production.runs.shift-defaults', $f['run']);
    $response = $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.shift_id', $response->json('data.shift_id'));
    $payload = ['_submission_token' => (string) Str::uuid(), 'hr_shift_id' => $response->json('data.shift_id'), 'work_date' => now()->toDateString(),
        'started_at' => now()->toDateTimeString(), 'sheet_fields' => ['sheet_kind' => 'injection', 'pack_ratio' => '1', 'cavities' => 8]];
    $url = route('admin.production.runs.shifts.store', $f['run']);
    $response = $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.entry_id', $response->json('data.entry_id'));
    $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '1', 'production_shift_entry_id' => $response->json('data.entry_id')]);
    $this->travel(10)->minutes();
    $close = ['_submission_token' => (string) Str::uuid(), 'entry_id' => $response->json('data.entry_id'), 'ended_at' => now()->toDateTimeString(), 'downtime_minutes' => '2'];
    $this->postJson(route('admin.production.runs.shifts.close', $f['run']), $close)->assertOk();
    $this->postJson(route('admin.production.runs.shifts.close', $f['run']), $close)->assertOk();
    expect(app(ProductionShiftEvidenceService::class)->report($f['run'])->sole()->working_hours)->toBe('0.13333333');
    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);
        $this->get(route('admin.production.runs.show', $f['run']))->assertOk()->assertSee(__('production_execution.shift_evidence.report_title'))
            ->assertSee('SYNTHETIC browser ready technician')->assertDontSee('production_execution.shift_evidence.');
        $this->get(route('admin.production.runs.shifts.print', $f['run']))->assertOk()->assertHeader('content-type', 'application/pdf');
    }
});

test('one hundred cartons over eight HR shifts use partial requests output quality and receipts with residual raw WIP', function (): void {
    $f = partialOutputFixture(factoryWorkflow: true, plannedQuantity: '100', issueInitialMaterials: false, startAt: '2026-09-20 12:00:00');
    $service = app(ProductionShiftEvidenceService::class);
    $materials = app(ProductionMaterialRequestService::class);
    $original = shiftEvidenceEmployee($f, 'SYNTHETIC eight day original technician');
    $replacement = shiftEvidenceEmployee($f, 'SYNTHETIC day three technician');
    $shift = $service->saveDefaults($f['run'], shiftEvidenceDefaults($original));
    $masterCounts = [HrShift::query()->count(), ProductionShift::query()->count()];
    $leftover = '0';
    foreach ([10, 15, 12, 13, 10, 15, 12, 13] as $day => $quantity) {
        $used = bcmul((string) $quantity, '2', 8);
        $toIssue = bcadd(bcsub($used, $leftover, 8), $day === 0 ? '4' : '0', 8);
        $request = $materials->create($f['run']->fresh(), $f['store']->id, [$f['requirement']->id => $toIssue]);
        $request = $materials->approve($request);
        $materials->issue($request);
        if ($day === 0) {
            $f['run'] = $f['cycle']->startRun($f['run']->fresh());
        }
        $data = ['hr_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(),
            'sheet_fields' => ['sheet_kind' => 'injection', 'pack_ratio' => '1', 'primary_material_requirement_public_id' => $f['requirement']->public_id]];
        if ($day === 2) {
            $data['crew'] = [['employee_id' => $replacement->id, 'role' => 'technician']];
        }
        $entry = $service->record($f['run']->fresh(), $data);
        $this->travel(1)->minutes();
        $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => (string) $quantity, 'production_shift_entry_id' => $entry->id]);
        partialOutputApprove($f, (string) $quantity);
        $f['cycle']->receiveFinishedGoods($f['run']->fresh(), $f['store']->id, (string) $quantity);
        $leftover = $day === 0 ? '4' : '0';
        expect($f['run']->fresh()->status)->toBe('running');
        if ($day === 0) {
            expect(app(ProductionCostService::class)->runPosition($f['run']->fresh())['wip'])->toBe('40.00000000');
            expect(fn () => $f['cycle']->completeRun($f['run']->fresh()))->toThrow(DomainException::class);
        }
        $this->travel(9)->minutes();
        $service->close($f['run']->fresh(), $entry->id, now()->toDateTimeString());
        if ($day < 7) {
            $this->travel(1)->days();
        }
    }
    $report = $service->report($f['run']->fresh());
    expect($report)->toHaveCount(8)->and($report[0]->crew_snapshot[0]['employee_id'])->toBe($original->id)
        ->and($report[2]->crew_snapshot[0]['employee_id'])->toBe($replacement->id)->and($report[3]->crew_snapshot[0]['employee_id'])->toBe($original->id)
        ->and([HrShift::query()->count(), ProductionShift::query()->count()])->toBe($masterCounts)
        ->and($f['run']->fresh()->received_base_quantity)->toBe('100.00000000')
        ->and(app(ProductionCostService::class)->runPosition($f['run']->fresh())['wip'])->toBe('0.00000000');
    $f['cycle']->completeRun($f['run']->fresh());
    expect($f['run']->fresh()->status)->toBe('completed');
});

test('HR shift references reject inactive masters missing linkage and stale open shift entries while preserving old snapshots', function (): void {
    $f = partialOutputFixture();
    $service = app(ProductionShiftEvidenceService::class);
    $employee = shiftEvidenceEmployee($f, 'SYNTHETIC guarded HR worker');
    $shift = $service->saveDefaults($f['run'], shiftEvidenceDefaults($employee));
    expect(fn () => $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '1']))->toThrow(DomainException::class);
    $data = ['hr_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(), 'sheet_fields' => ['sheet_kind' => 'injection']];
    $entry = $service->record($f['run']->fresh(), $data);
    $shift->update(['status' => 'inactive']);
    expect(fn () => $service->record($f['run']->fresh(), $data))->toThrow(DomainException::class);
    expect($service->report($f['run']->fresh())->sole()->sheet_fields['hr_shift_snapshot']['name'])->toBe('SYNTHETIC shift A');
    $this->travel(25)->hours();
    expect(fn () => $f['cycle']->recordProgress($f['run']->fresh(), ['good_base_quantity' => '1', 'production_shift_entry_id' => $entry->id]))->toThrow(DomainException::class);
    expect(fn () => $service->closeForRunCompletion($f['run']->fresh()))->toThrow(DomainException::class);
});
