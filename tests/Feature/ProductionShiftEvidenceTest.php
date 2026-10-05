<?php

use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\HR\Models\HrEmployee;
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
    return ['shift_code' => 'A', 'shift_name' => 'SYNTHETIC shift A', 'starts_at' => '12:00', 'ends_at' => '20:00',
        'crew' => [['employee_id' => $employee->id, 'role' => 'technician', 'planned_hours' => '8']]];
}

test('machine crew defaults and daily overrides retain the previous recorded crew and piece conversion', function (): void {
    $f = partialOutputFixture();
    $service = app(ProductionShiftEvidenceService::class);
    $first = shiftEvidenceEmployee($f, 'SYNTHETIC original technician');
    $second = shiftEvidenceEmployee($f, 'SYNTHETIC replacement technician');
    $override = shiftEvidenceEmployee($f, 'SYNTHETIC daily override');
    $shift = $service->saveDefaults($f['run'], shiftEvidenceDefaults($first));
    $entry = $service->record($f['run'], ['production_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(),
        'sheet_fields' => ['sheet_kind' => 'injection', 'pack_ratio' => '1', 'cavities' => 8]]);
    $this->travel(10)->minutes();
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '1', 'production_shift_entry_id' => $entry->id, 'notes' => 'SYNTHETIC actual shift progress']);
    $service->close($f['run'], $entry->id, now()->toDateTimeString());
    $service->saveDefaults($f['run'], shiftEvidenceDefaults($second));
    $this->travel(1)->days();
    $newEntry = $service->record($f['run'], ['production_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(),
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
    $payload = ['production_shift_id' => $shift->id, 'work_date' => now()->toDateString(), 'started_at' => now()->toDateTimeString(), 'sheet_fields' => ['sheet_kind' => 'cover', 'pack_ratio' => '2']];
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
    $payload = ['_submission_token' => (string) Str::uuid(), 'production_shift_id' => $response->json('data.shift_id'), 'work_date' => now()->toDateString(),
        'started_at' => now()->toDateTimeString(), 'sheet_fields' => ['sheet_kind' => 'injection', 'pack_ratio' => '1', 'cavities' => 8]];
    $url = route('admin.production.runs.shifts.store', $f['run']);
    $response = $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.entry_id', $response->json('data.entry_id'));
    $f['cycle']->recordProgress($f['run'], ['good_base_quantity' => '1', 'production_shift_entry_id' => $response->json('data.entry_id')]);
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
