<?php

namespace Modules\Maintenance\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Modules\Maintenance\Models\MaintenanceWorkOrder;

class MaintenanceWorkOrderExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
{
    public function __construct(
        private readonly Collection $orders,
        private readonly bool $canViewFinancial = false,
    ) {}

    public function array(): array
    {
        return $this->orders->map(function ($order): array {
            $materialLines = $order->materialRequests->flatMap->lines;
            $expenseSummary = $order->expenses
                ->groupBy(fn ($expense) => $expense->currency?->code ?: '—')
                ->map(fn ($rows, $currency) => $currency.': '.$rows->sum('amount'))
                ->implode(' | ');
            $grossMaterialCost = $order->materialRequests
                ->flatMap(fn ($request) => $request->issueDocument?->lines ?? collect())
                ->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->total_cost, 4), '0.0000');
            $returnedMaterialCost = $order->materialRequests
                ->flatMap(fn ($request) => $request->returnDocument?->lines ?? collect())
                ->reduce(fn (string $carry, $line): string => bcadd($carry, (string) $line->total_cost, 4), '0.0000');
            $netMaterialQuantity = $materialLines->reduce(
                fn (string $carry, $line): string => bcadd($carry, bcsub((string) $line->issued_quantity, (string) $line->returned_quantity, 8), 8),
                '0.00000000',
            );
            $materialQuantity = fn (string $field): string => $materialLines->reduce(
                fn (string $carry, $line): string => bcadd($carry, (string) $line->{$field}, 8),
                '0.00000000',
            );

            $row = [
                $order->doc_num,
                $order->asset?->doc_num ?? $order->mold?->code,
                $order->asset?->asset_name ?? $order->mold?->name,
                __('maintenance.maintenance_types.'.$order->maintenance_type),
                __('maintenance.service_modes.'.$order->service_mode),
                $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal'),
                __('maintenance.priorities.'.$order->priority),
                $order->planned_start_at?->format('Y-m-d H:i:s'),
                $order->actual_start_at?->format('Y-m-d H:i:s'),
                $order->actual_end_at?->format('Y-m-d H:i:s'),
                $order->machine_released_at?->format('Y-m-d H:i:s'),
                $order->total_paused_minutes + ($order->paused_at ? (int) $order->paused_at->diffInMinutes(now()) : 0),
                $order->test_result ? __('maintenance.test_results.'.$order->test_result) : null,
                $order->repair_outcome ? __('maintenance.repair_outcomes.'.$order->repair_outcome) : null,
                $order->external_cost,
                $materialQuantity('requested_quantity'),
                $materialQuantity('issued_quantity'),
                $materialQuantity('consumed_quantity'),
                $materialQuantity('returned_quantity'),
                $netMaterialQuantity,
                $this->materialTrace($order),
                $grossMaterialCost,
                $returnedMaterialCost,
                bcsub($grossMaterialCost, $returnedMaterialCost, 4),
                $expenseSummary,
                __('maintenance.statuses.'.$order->status),
                $order->diagnosis,
                $order->root_cause,
                $order->work_performed,
                $order->next_due_date?->toDateString(),
            ];

            if (! $this->canViewFinancial) {
                unset($row[14], $row[21], $row[22], $row[23], $row[24]);
            }

            return array_values($row);
        })->values()->all();
    }

    public function headings(): array
    {
        $headings = [
            __('maintenance.fields.work_order'),
            __('maintenance.reports.asset_code'),
            __('maintenance.fields.asset'),
            __('maintenance.fields.maintenance_type'),
            __('maintenance.fields.service_mode'),
            __('maintenance.fields.provider'),
            __('maintenance.fields.priority'),
            __('maintenance.fields.planned_start'),
            __('maintenance.fields.actual_start'),
            __('maintenance.fields.actual_end'),
            __('maintenance.fields.machine_released_at'),
            __('maintenance.fields.total_paused_minutes'),
            __('maintenance.fields.test_result'),
            __('maintenance.fields.repair_outcome'),
            __('maintenance.fields.external_cost'),
            __('maintenance.reports.requested_material_quantity'),
            __('maintenance.reports.issued_material_quantity'),
            __('maintenance.reports.consumed_material_quantity'),
            __('maintenance.reports.returned_material_quantity'),
            __('maintenance.reports.net_material_quantity'),
            __('maintenance.reports.material_trace'),
            __('maintenance.reports.gross_material_cost'),
            __('maintenance.reports.returned_material_cost'),
            __('maintenance.reports.net_material_cost'),
            __('maintenance.reports.expenses'),
            __('maintenance.fields.status'),
            __('maintenance.fields.diagnosis'),
            __('maintenance.fields.root_cause'),
            __('maintenance.fields.work_performed'),
            __('maintenance.fields.next_due_date'),
        ];

        if (! $this->canViewFinancial) {
            unset($headings[14], $headings[21], $headings[22], $headings[23], $headings[24]);
        }

        return array_values($headings);
    }

    public function title(): string
    {
        return __('maintenance.reports.sheet_title');
    }

    private function materialTrace(MaintenanceWorkOrder $order): string
    {
        return $order->materialRequests->flatMap(function ($request): array {
            return $request->lines->map(function ($line) use ($request): string {
                $issueLine = $request->issueDocument?->lines->firstWhere('source_line_id', $line->getKey());
                $returnLines = $request->returnDocument?->lines->filter(
                    fn ($returnLine): bool => (string) ($returnLine->source_line_id ?? null) === (string) $line->getKey(),
                ) ?? collect();
                $issued = (string) $line->issued_quantity;
                $returned = (string) $line->returned_quantity;
                $gross = $issueLine?->total_cost === null ? '0.0000' : bcadd((string) $issueLine->total_cost, '0', 4);
                $returnedCost = $returnLines->reduce(
                    fn (string $carry, $rl): string => $rl->total_cost === null
                        ? $carry
                        : bcadd($carry, (string) $rl->total_cost, 4),
                    '0.0000',
                );
                $quantityTrace = __('maintenance.reports.material_line_quantity', [
                    'product' => $line->product?->doc_num.' — '.$line->product?->name,
                    'unit' => $line->unit?->name ?? '—',
                    'issued' => $issued,
                    'returned' => $returned,
                    'net' => bcsub($issued, $returned, 8),
                ]);

                if (! $this->canViewFinancial) {
                    return $quantityTrace;
                }

                return $quantityTrace.' | '.__('maintenance.reports.material_line_cost', [
                    'unit_cost' => $issueLine?->unit_cost ?? '0.00000000',
                    'gross' => $gross,
                    'returned' => $returnedCost,
                    'net' => bcsub($gross, $returnedCost, 4),
                ]);
            })->all();
        })->implode(' || ');
    }
}
