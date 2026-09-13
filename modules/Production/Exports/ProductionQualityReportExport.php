<?php

namespace Modules\Production\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProductionQualityReportExport implements FromArray, ShouldAutoSize, WithHeadings, WithStrictNullComparison, WithTitle
{
    public function __construct(private readonly Collection $inspections) {}

    public function array(): array
    {
        return $this->inspections->map(fn ($inspection): array => [
            $inspection->doc_num,
            $inspection->reinspection_number,
            $inspection->requested_at?->format('Y-m-d H:i:s'),
            $inspection->received_at?->format('Y-m-d H:i:s'),
            $inspection->started_at?->format('Y-m-d H:i:s'),
            $inspection->sampled_at?->format('Y-m-d H:i:s'),
            __('production_execution.quality_subjects.'.$inspection->subject_type),
            $inspection->run?->product?->name ?? $inspection->product?->name,
            $inspection->branchStore?->name,
            $inspection->stock_status ? __('production_execution.stock_statuses.'.$inspection->stock_status) : null,
            $inspection->batch_lot,
            $inspection->run?->run_number,
            $inspection->run?->order?->doc_num,
            $inspection->run?->order?->salesOrder?->doc_num,
            $inspection->stageSnapshot?->stage_name,
            $inspection->qualityType?->name,
            $inspection->result,
            $inspection->disposition,
            $inspection->affected_base_quantity,
            $inspection->status,
            $inspection->defect_code,
            $inspection->notes,
            $inspection->corrective_action,
            $inspection->rework_notes,
            count($inspection->evidence ?? []),
            $inspection->reports->count(),
            $inspection->reviewed_at?->format('Y-m-d H:i:s'),
            $inspection->closed_at?->format('Y-m-d H:i:s'),
        ])->values()->all();
    }

    public function headings(): array
    {
        return [
            __('production_execution.fields.inspection'), __('production_execution.fields.reinspection'), __('production_execution.fields.requested_at'), __('production_execution.fields.received_at'), __('production_execution.fields.started_at'), __('production_execution.fields.sampled_at'),
            __('production_execution.fields.inspection_subject'), __('production_execution.fields.product'), __('production_execution.fields.store'), __('production_execution.fields.stock_status'), __('production_execution.fields.batch_lot'),
            __('production_execution.fields.run'), __('production_execution.fields.production_order'), __('production_execution.fields.sales_order'), __('production_execution.fields.stage'),
            __('production_execution.fields.inspection_type'), __('production_execution.fields.result'), __('production_execution.fields.disposition'), __('production_execution.fields.affected_quantity'), __('production_execution.fields.status'),
            __('production_execution.fields.defect_code'), __('production_execution.fields.notes'), __('production_execution.fields.corrective_action'), __('production_execution.fields.rework_notes'), __('production_execution.fields.attachments'), __('production_execution.fields.reports_count'), __('production_execution.fields.reviewed_at'), __('production_execution.fields.closed_at'),
        ];
    }

    public function title(): string
    {
        return mb_substr(__('production_execution.quality.report_title'), 0, 31);
    }
}
