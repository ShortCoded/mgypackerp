<?php

namespace Modules\Production\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\HR\Models\HrShift;
use Modules\Production\Models\ProductionProgressEntry;
use Modules\Production\Models\ProductionRun;

class ProductionDailyReportService
{
    public function __construct(
        private readonly ProductionHandoverService $handovers,
        private readonly ProductionShiftEvidenceService $shifts,
        private readonly ProductionCycleService $cycle,
    ) {}

    /** @param array<string, mixed> $data @return Collection<int, ProductionProgressEntry> */
    public function record(ProductionRun $anchor, array $data): Collection
    {
        Gate::authorize('production.runs.progress');

        return DB::transaction(function () use ($anchor, $data): Collection {
            $runs = $this->handovers->runs($anchor)->keyBy('public_id');
            $shift = HrShift::query()->where('status', 'active')->findOrFail($data['hr_shift_id']);
            $date = CarbonImmutable::parse($data['work_date']);
            $kind = $data['sheet_kind'];
            $entries = collect();
            $seen = [];
            foreach ($data['lines'] as $line) {
                $run = $runs->get($line['run_public_id']);
                if (! $run instanceof ProductionRun || isset($seen[$run->id])) {
                    throw new DomainException(__('production_handover.invalid_line'));
                }
                $seen[$run->id] = true;
                $outcomes = [];
                foreach (ProductionStageOutputCostService::OutputFields as $outcome => $field) {
                    $outcomes[$field] = bcmul((string) ($line[$outcome === 'good' ? 'quantity' : $outcome.'_quantity'] ?? '0'), (string) $run->conversion_factor, 8);
                }
                if (! app(ProductionStageTransferService::class)->isManaged($run)
                    && collect($outcomes)->except('good_base_quantity')->contains(fn (string $quantity): bool => bccomp($quantity, '0', 8) !== 0)) {
                    throw new DomainException(__('production_stage_transfer.loss_requires_managed_run'));
                }
                $hours = $line['working_hours'] ?? $data['working_hours'] ?? null;
                if ($kind === 'injection') {
                    $start = CarbonImmutable::parse($date->toDateString().' '.$shift->start_time);
                    $end = $start->addSeconds((int) bcmul((string) $hours, '3600', 0));
                } else {
                    if (filled($line['started_time'] ?? null) !== filled($line['ended_time'] ?? null)) {
                        throw new DomainException(__('production_execution.shift_evidence.time_invalid'));
                    }
                    $start = CarbonImmutable::parse($date->toDateString().' '.($line['started_time'] ?? $data['started_time']));
                    $end = CarbonImmutable::parse($date->toDateString().' '.($line['ended_time'] ?? $data['ended_time']));
                    if ($end->lessThan($start) && $shift->crosses_midnight) {
                        $end = $end->addDay();
                    }
                }
                $fields = array_intersect_key($line, array_flip(['cavities', 'cycle_seconds', 'piece_weight_grams', 'average_piece_weight_grams',
                    'machine_speed', 'packing_ratio', 'actual_pieces', 'carton_count', 'bag_type', 'bag_size', 'carton_type', 'carton_size',
                    'cover_components', 'customer_name', 'material_used', 'material_unit', 'material_name', 'roll_reference', 'actual_waste', 'waste_unit', 'intact_roll_waste']));
                $fields = [...$fields, 'customer_name' => $run->order?->salesOrder?->customer?->name ?? ($line['customer_name'] ?? null), 'sheet_kind' => $kind, 'technician_names' => $line['technician_names'] ?? $data['technician_names'] ?? null,
                    'working_hours' => $kind === 'injection' ? (string) $hours : null,
                    'time_basis' => $kind === 'injection' ? 'hr_schedule' : 'reported_clock',
                    'reported_quantity' => (string) $line['quantity'],
                    'reported_outcomes' => ['good' => (string) $line['quantity'], 'rejected' => (string) ($line['rejected_quantity'] ?? '0'),
                        'rework' => (string) ($line['rework_quantity'] ?? '0'), 'scrap' => (string) ($line['scrap_quantity'] ?? '0')], 'reported_unit_name' => $run->unit?->name];
                $shiftEntry = $this->shifts->record($run, ['hr_shift_id' => $shift->id, 'work_date' => $date->toDateString(),
                    'started_at' => $start->toDateTimeString(), 'ended_at' => $end->toDateTimeString(),
                    'sheet_fields' => $fields, 'notes' => $line['notes'] ?? $data['notes'] ?? null], dailySheet: true);
                $entry = $this->cycle->recordDailyProgress($run, [...$outcomes,
                    'stage_input_base_quantity' => $line['stage_input_base_quantity'] ?? null,
                    'production_shift_entry_id' => $shiftEntry->id, 'notes' => $line['notes'] ?? $data['notes'] ?? null], $end);
                $entries->push($entry);
            }
            if ($entries->isEmpty()) {
                throw new DomainException(__('production_handover.positive_line_required'));
            }

            return $entries;
        }, 3);
    }
}
