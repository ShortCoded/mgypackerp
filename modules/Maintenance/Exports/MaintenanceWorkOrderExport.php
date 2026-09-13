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
    public function __construct(private readonly Collection $orders) {}

    public function array(): array
    {
        return $this->orders->map(fn ($order): array => [
            $order->doc_num,
            $order->asset?->asset_code,
            $order->asset?->asset_name,
            $order->maintenance_type,
            $order->service_mode,
            $order->supplier?->name ?: $order->external_provider_name,
            $order->priority,
            $order->planned_start_at?->format('Y-m-d H:i:s'),
            $order->actual_start_at?->format('Y-m-d H:i:s'),
            $order->actual_end_at?->format('Y-m-d H:i:s'),
            $order->external_cost,
            $order->status,
            $order->diagnosis,
            $order->root_cause,
            $order->work_performed,
            $order->next_due_date?->toDateString(),
        ])->values()->all();
    }

    public function headings(): array
    {
        return [
            'Work Order', 'Asset Code', 'Asset', 'Maintenance Type', 'Service Mode', 'Provider',
            'Priority', 'Planned Start', 'Actual Start', 'Actual End', 'External Cost', 'Status',
            'Diagnosis', 'Root Cause', 'Work Performed', 'Next Due Date',
        ];
    }

    public function title(): string
    {
        return 'Maintenance Orders';
    }
}
