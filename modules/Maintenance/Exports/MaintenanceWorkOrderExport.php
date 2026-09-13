<?php

namespace Modules\Maintenance\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

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

            $row = [
                $order->doc_num,
                $order->asset?->asset_code,
                $order->asset?->asset_name,
                __('maintenance.maintenance_types.'.$order->maintenance_type),
                __('maintenance.service_modes.'.$order->service_mode),
                $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal'),
                __('maintenance.priorities.'.$order->priority),
                $order->planned_start_at?->format('Y-m-d H:i:s'),
                $order->actual_start_at?->format('Y-m-d H:i:s'),
                $order->actual_end_at?->format('Y-m-d H:i:s'),
                $order->external_cost,
                $materialLines->sum('requested_quantity'),
                $materialLines->sum('issued_quantity'),
                $materialLines->sum('returned_quantity'),
                $expenseSummary,
                __('maintenance.statuses.'.$order->status),
                $order->diagnosis,
                $order->root_cause,
                $order->work_performed,
                $order->next_due_date?->toDateString(),
            ];

            if (! $this->canViewFinancial) {
                unset($row[10], $row[14]);
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
            __('maintenance.fields.external_cost'),
            __('maintenance.reports.requested_material_quantity'),
            __('maintenance.reports.issued_material_quantity'),
            __('maintenance.reports.returned_material_quantity'),
            __('maintenance.reports.expenses'),
            __('maintenance.fields.status'),
            __('maintenance.fields.diagnosis'),
            __('maintenance.fields.root_cause'),
            __('maintenance.fields.work_performed'),
            __('maintenance.fields.next_due_date'),
        ];

        if (! $this->canViewFinancial) {
            unset($headings[10], $headings[14]);
        }

        return array_values($headings);
    }

    public function title(): string
    {
        return __('maintenance.reports.sheet_title');
    }
}
