<?php

namespace Modules\Production\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\FinancialPeriodService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrShift;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionRun;

class ProductionShiftEvidenceService
{
    public const NumericFields = ['cycle_seconds', 'piece_weight_grams', 'average_piece_weight_grams', 'machine_speed', 'pack_ratio'];

    public const TextFields = ['product_size', 'bag_type', 'bag_size', 'carton_type', 'carton_size', 'cover_components'];

    /** @param array<string, mixed> $data */
    public function saveDefaults(ProductionRun $run, array $data): HrShift
    {
        return DB::transaction(function () use ($run, $data): HrShift {
            Gate::authorize('production.runs.setup');
            $locked = $this->lockedRun($run);
            if (in_array($locked->status, [ProductionRun::StatusCompleted, ProductionRun::StatusCancelled], true)) {
                throw new DomainException(__('production_execution.messages.material_request_run_closed'));
            }
            $equipment = $this->equipment($locked);
            $crew = $this->crew($locked, $data['crew'] ?? [], now()->toDateString());
            $shift = $this->hrShift((int) $data['hr_shift_id']);
            $key = ['company_id' => $locked->company_id, 'branch_id' => $locked->branch_id, 'hr_shift_id' => $shift->id,
                'fixed_asset_id' => $equipment['fixed_asset_id'], 'production_machine_id' => $equipment['production_machine_id']];
            $prior = DB::table('production_shift_crews')->where($key)->first();
            DB::table('production_shift_crews')->updateOrInsert($key, ['crew_snapshot' => json_encode($crew, JSON_THROW_ON_ERROR),
                'updated_by' => auth()->id(), 'updated_at' => now(), 'created_at' => $prior?->created_at ?? now()]);
            $locked->update(['uses_hr_shift_evidence' => true]);
            $this->audit($locked, 'production.shift.defaults_saved', ['shift_id' => $shift->id, 'equipment' => $equipment, 'crew' => $crew]);

            return $shift;
        });
    }

    /** @param array<string, mixed> $data */
    public function record(ProductionRun $run, array $data, bool $dailySheet = false): object
    {
        return DB::transaction(function () use ($run, $data, $dailySheet): object {
            Gate::authorize('production.runs.progress');
            $locked = $this->lockedRun($run);
            if (! in_array($locked->status, [ProductionRun::StatusRunning, ProductionRun::StatusHeld], true)) {
                throw new DomainException(__('production_execution.shift_evidence.active_run_required'));
            }
            $shift = $this->hrShift((int) $data['hr_shift_id']);
            $equipment = $this->equipment($locked);
            $date = CarbonImmutable::parse($data['work_date'])->toDateString();
            $start = CarbonImmutable::parse($data['started_at']);
            $end = filled($data['ended_at'] ?? null) ? CarbonImmutable::parse($data['ended_at']) : null;
            $shiftDate = $shift->crosses_midnight && filled($shift->end_time) && $start->format('H:i:s') <= $shift->end_time
                ? $start->subDay()->toDateString() : $start->toDateString();
            if (! in_array($date, [$start->toDateString(), $shiftDate], true) || $start->isFuture() || ($locked->actual_start_at !== null && ($dailySheet && ($data['sheet_fields']['time_basis'] ?? null) === 'hr_schedule'
                    ? $date < $locked->actual_start_at->toDateString() : $start->lessThan($locked->actual_start_at)))
                || ($end !== null && ($end->isFuture() || $end->lessThan($start))) || $start->diffInHours($end ?? now()) > 24) {
                throw new DomainException(__('production_execution.shift_evidence.time_invalid'));
            }
            $minutes = $this->decimal($start->diffInMinutes($end ?? now()));
            if (bccomp((string) ($data['downtime_minutes'] ?? '0'), '0', 4) < 0 || bccomp((string) ($data['downtime_minutes'] ?? '0'), $minutes, 4) > 0) {
                throw new DomainException(__('production_execution.shift_evidence.time_invalid'));
            }
            $period = app(FinancialPeriodService::class)->resolveOpenForPostingDate((int) $locked->company_id, $date, lockForUpdate: true);
            $defaults = DB::table('production_shift_crews')->where(['company_id' => $locked->company_id, 'branch_id' => $locked->branch_id,
                'hr_shift_id' => $shift->id, 'fixed_asset_id' => $equipment['fixed_asset_id'], 'production_machine_id' => $equipment['production_machine_id']])->first();
            $submittedCrew = $data['crew'] ?? null;
            $crewRows = $submittedCrew ?? json_decode($defaults?->crew_snapshot ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $crew = $dailySheet && $crewRows === [] ? [] : $this->crew($locked, $crewRows, $date);
            $fields = $data['sheet_fields'] ?? [];
            if ($dailySheet) {
                $fields['entry_source'] = 'daily_sheet';
            } else {
                unset($fields['entry_source'], $fields['time_basis']);
            }
            $basis = (string) ($locked->orderLine->bom_snapshot['basis_base_quantity'] ?? '1');
            if (filled($fields['pack_ratio'] ?? null) && bccomp((string) $fields['pack_ratio'], $basis, 8) !== 0) {
                throw new DomainException(__('production_execution.shift_evidence.pack_ratio_mismatch'));
            }
            $materialId = $fields['primary_material_requirement_public_id'] ?? null;
            if (filled($materialId) && ! $locked->requirements()->where('public_id', $materialId)->exists()) {
                throw new DomainException(__('production_execution.evidence.policy_invalid'));
            }
            $fields = [...$fields, 'basis_base_quantity' => $basis, 'basis_unit_name' => $locked->orderLine->bom_snapshot['basis_unit_name'] ?? $locked->unit?->name,
                'product_name' => $locked->product?->name, 'shift_name' => $shift->name,
                'hr_shift_snapshot' => ['id' => $shift->id, 'doc_num' => $shift->doc_num, 'name' => $shift->name,
                    'start_time' => $shift->start_time, 'end_time' => $shift->end_time, 'break_minutes' => $shift->break_minutes, 'crosses_midnight' => $shift->crosses_midnight], 'crew_source' => $crew === [] ? 'sheet_only' : ($submittedCrew === null ? 'default' : 'override')];
            if ($this->entries($locked)->where('hr_shift_id', $shift->id)->whereDate('work_date', $date)->exists()) {
                throw new DomainException(__('production_execution.shift_evidence.entry_exists'));
            }
            $id = DB::table('production_shift_entries')->insertGetId(['public_id' => (string) Str::uuid(), 'company_id' => $locked->company_id,
                'branch_id' => $locked->branch_id, 'financial_period_id' => $period->id, 'production_run_id' => $locked->id,
                'hr_shift_id' => $shift->id, 'work_date' => $date, 'started_at' => $start, 'ended_at' => $end,
                'downtime_minutes' => $data['downtime_minutes'] ?? '0', 'equipment_snapshot' => json_encode($equipment, JSON_THROW_ON_ERROR),
                'crew_snapshot' => json_encode($crew, JSON_THROW_ON_ERROR), 'sheet_fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                'notes' => $data['notes'] ?? null, 'recorded_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $locked->update(['uses_hr_shift_evidence' => true]);
            $entry = $this->entries($locked)->where('id', $id)->first();
            $this->audit($locked, 'production.shift.entry_recorded', ['entry_id' => $id, 'crew_source' => $fields['crew_source'], 'work_date' => $date]);

            return $entry;
        });
    }

    public function assertProgressEntry(ProductionRun $run, ?int $entryId, ?CarbonImmutable $recordedAt = null): void
    {
        if ($entryId === null) {
            if ($run->uses_hr_shift_evidence) {
                throw new DomainException(__('production_execution.shift_evidence.entry_required'));
            }

            return;
        }
        $at = $recordedAt ?? CarbonImmutable::now();
        $entry = $this->entries($run)->where('id', $entryId)->first();
        if ($entry === null || $at->isFuture() || CarbonImmutable::parse($entry->started_at)->greaterThan($at)
            || CarbonImmutable::parse($entry->started_at)->diffInHours($at) > 24
            || ($entry->ended_at !== null && CarbonImmutable::parse($entry->ended_at)->lessThan($at))) {
            throw new DomainException(__('production_execution.shift_evidence.entry_invalid'));
        }
        $equipment = json_decode($entry->equipment_snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (($equipment['fixed_asset_id'] ?? null) !== $run->fixed_asset_id || ($equipment['production_machine_id'] ?? null) !== $run->production_machine_id) {
            throw new DomainException(__('production_execution.shift_evidence.entry_invalid'));
        }
    }

    public function close(ProductionRun $run, int $entryId, string $endedAt, ?string $downtimeMinutes = null): void
    {
        DB::transaction(function () use ($run, $entryId, $endedAt, $downtimeMinutes): void {
            Gate::authorize('production.runs.progress');
            $locked = $this->lockedRun($run);
            $entry = $this->entries($locked)->where('id', $entryId)->lockForUpdate()->first();
            $end = CarbonImmutable::parse($endedAt);
            $lastProgress = $locked->progressEntries()->where('production_shift_entry_id', $entryId)->max('recorded_at');
            if ($entry === null || $entry->ended_at !== null || $end->isFuture() || $end->lessThan(CarbonImmutable::parse($entry->started_at))
                || CarbonImmutable::parse($entry->started_at)->diffInHours($end) > 24
                || ($lastProgress !== null && $end->lessThan(CarbonImmutable::parse($lastProgress)))) {
                throw new DomainException(__('production_execution.shift_evidence.time_invalid'));
            }
            $downtime = $downtimeMinutes === null ? (string) $entry->downtime_minutes : $this->decimal($downtimeMinutes);
            if (bccomp($downtime, '0', 8) < 0 || bccomp($downtime, $this->decimal(CarbonImmutable::parse($entry->started_at)->diffInMinutes($end)), 8) > 0) {
                throw new DomainException(__('production_execution.shift_evidence.time_invalid'));
            }
            $this->entries($locked)->where('id', $entryId)->update(['ended_at' => $end, 'downtime_minutes' => $downtime, 'updated_at' => now()]);
            $this->audit($locked, 'production.shift.closed', ['entry_id' => $entryId, 'ended_at' => $end->toIso8601String()]);
        });
    }

    public function closeForRunCompletion(ProductionRun $run): void
    {
        foreach ($this->entries($run)->whereNull('ended_at')->lockForUpdate()->get() as $entry) {
            if (CarbonImmutable::parse($entry->started_at)->diffInHours(now()) > 24) {
                throw new DomainException(__('production_execution.shift_evidence.close_prior_shift'));
            }
            $this->entries($run)->where('id', $entry->id)->update(['ended_at' => now(), 'updated_at' => now()]);
            $this->audit($run, 'production.shift.closed_with_run', ['entry_id' => $entry->id, 'ended_at' => now()->toIso8601String()]);
        }
    }

    /** @return Collection<int, object> */
    public function report(ProductionRun $run): Collection
    {
        $progress = $run->progressEntries()->orderBy('recorded_at')->get()->groupBy('production_shift_entry_id');

        return $this->entries($run)->orderBy('work_date')->orderBy('id')->get()->map(function (object $entry) use ($progress): object {
            foreach (['equipment_snapshot', 'crew_snapshot', 'sheet_fields'] as $field) {
                $entry->{$field} = json_decode($entry->{$field}, true, flags: JSON_THROW_ON_ERROR);
            }
            $rows = $progress->get($entry->id, collect());
            $entry->good_base_quantity = $rows->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->good_base_quantity, 8), '0.00000000');
            $entry->has_quantity_corrections = $rows->contains(fn ($row): bool => $row->production_run_correction_id !== null);
            $entry->output_pieces = ($entry->sheet_fields['entry_source'] ?? null) === 'daily_sheet'
                ? ($entry->sheet_fields['actual_pieces'] ?? null) : bcmul($entry->good_base_quantity, $entry->sheet_fields['basis_base_quantity'], 8);
            $entry->scrap_weight_kg = $rows->reduce(fn (string $sum, $row): string => bcadd($sum, (string) ($row->production_scrap_weight_kg ?? '0'), 8), '0.00000000');
            $entry->notes_log = $rows->map(fn ($row): array => ['at' => $row->recorded_at, 'notes' => $row->notes, 'materials' => $row->material_evidence ?? []])->all();
            $entry->material_used = '0.00000000';
            $entry->material_waste = '0.00000000';
            foreach ($rows as $row) {
                foreach ($row->material_evidence ?? [] as $material) {
                    if ($material['requirement_public_id'] === ($entry->sheet_fields['primary_material_requirement_public_id'] ?? null)) {
                        $entry->material_used = bcadd($entry->material_used, $material['consumed_quantity'], 8);
                        $entry->material_waste = bcadd($entry->material_waste, $material['waste_quantity'], 8);
                    }
                }
            }
            $elapsed = $this->decimal(max(0, CarbonImmutable::parse($entry->started_at)->diffInMinutes($entry->ended_at ? CarbonImmutable::parse($entry->ended_at) : now())));
            $entry->working_hours = $entry->sheet_fields['working_hours'] ?? bcdiv(bcsub((string) $elapsed, (string) $entry->downtime_minutes, 8), '60', 8);

            return $entry;
        });
    }

    public function entries(ProductionRun $run): Builder
    {
        return DB::table('production_shift_entries')->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)->where('production_run_id', $run->id);
    }

    public function hasDailyReports(ProductionRun $run): bool
    {
        return $this->entries($run)->where('sheet_fields->entry_source', 'daily_sheet')->exists();
    }

    public function pendingDailyReports(ProductionRun $run): bool
    {
        return $run->progressEntries()->whereNull('material_documents')->whereIn('production_shift_entry_id',
            $this->entries($run)->where('sheet_fields->entry_source', 'daily_sheet')->select('id'))->exists();
    }

    private function hrShift(int $id): HrShift
    {
        $shift = HrShift::query()->where('status', 'active')->lockForUpdate()->find($id);
        if ($shift === null) {
            throw new DomainException(__('production_execution.shift_evidence.shift_invalid'));
        }

        return $shift;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function crew(ProductionRun $run, array $rows, string $date): array
    {
        if ($rows === [] || count($rows) > 100 || count(array_unique(array_column($rows, 'employee_id'))) !== count($rows)) {
            throw new DomainException(__('production_execution.shift_evidence.crew_invalid'));
        }
        $employees = HrEmployee::query()->where('company_id', $run->company_id)->where('status', 'active')
            ->assignedToPayrollBranchesDuring([(int) $run->branch_id], $date, $date)->whereIn('id', array_column($rows, 'employee_id'))->get()->keyBy('id');

        return array_map(function (array $row) use ($employees): array {
            $employee = $employees->get((int) ($row['employee_id'] ?? 0));
            if ($employee === null || ! in_array($row['role'] ?? null, ['supervisor', 'technician', 'operator', 'foreman'], true)) {
                throw new DomainException(__('production_execution.shift_evidence.crew_invalid'));
            }
            if (isset($row['planned_hours']) && (bccomp((string) $row['planned_hours'], '0', 4) < 0 || bccomp((string) $row['planned_hours'], '24', 4) > 0)) {
                throw new DomainException(__('production_execution.shift_evidence.crew_invalid'));
            }

            return ['employee_id' => $employee->id, 'employee_doc_num' => $employee->doc_num, 'name' => $employee->full_name ?: $employee->name,
                'role' => $row['role'], 'planned_hours' => $row['planned_hours'] ?? null];
        }, $rows);
    }

    /** @return array<string, mixed> */
    private function equipment(ProductionRun $run): array
    {
        $asset = $run->fixed_asset_id ? FixedAsset::query()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)->where('status', 'active')->find($run->fixed_asset_id) : null;
        $machine = $run->production_machine_id ? ProductionMachine::query()->where('company_id', $run->company_id)->where('branch_id', $run->branch_id)->where('status', ProductionMachine::StatusAvailable)->find($run->production_machine_id) : null;
        if (($asset === null) === ($machine === null)) {
            throw new DomainException(__('production_execution.shift_evidence.equipment_invalid'));
        }

        return ['fixed_asset_id' => $asset?->id, 'production_machine_id' => $machine?->id, 'number' => $asset?->doc_num ?? $machine?->code,
            'name' => $asset?->asset_name ?? $machine?->name];
    }

    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(8, RoundingMode::HalfUp);
    }

    private function lockedRun(ProductionRun $run): ProductionRun
    {
        $context = app(OperatingContextService::class)->snapshot(request());
        Company::query()->whereKey($run->company_id)->lockForUpdate()->firstOrFail();
        $locked = ProductionRun::query()->with(['orderLine', 'product', 'unit'])->lockForUpdate()->findOrFail($run->id);
        if ((int) $locked->company_id !== (int) $context['company_id'] || (int) $locked->branch_id !== (int) $context['branch_id']
            || (int) $locked->financial_period_id !== (int) $context['financial_period_id']) {
            throw new DomainException(__('production_execution.messages.operating_context_required'));
        }

        app(ProductionReceiptCancellationService::class)->assertManufacturingAllowed($locked);

        return $locked;
    }

    /** @param array<string, mixed> $properties */
    private function audit(ProductionRun $run, string $event, array $properties): void
    {
        app(ActivityLogger::class)->log(request(), 'production', $event, 'success', ['subject' => $run, 'causer' => auth()->user(), 'company_id' => $run->company_id,
            'properties_only' => true, 'properties' => $properties]);
    }
}
