<?php

namespace Modules\Auth\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Modules\Auth\Services\Reports\ActivityLogReport;
use Spatie\Activitylog\Models\Activity;

class ActivityLogsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly ActivityLogReport $report,
        private readonly array $filters = [],
    ) {}

    /**
     * @return Builder<Activity>
     */
    public function query(): Builder
    {
        return $this->report->query($this->filters)->latest(config('activitylog.table_name', 'activity_log').'.created_at');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->report->headings();
    }

    /**
     * @return list<string>
     */
    public function map($row): array
    {
        return $this->report->map($row);
    }
}
