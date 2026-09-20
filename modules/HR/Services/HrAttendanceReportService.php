<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\Branch;
use Modules\HR\Models\HrAttendanceDailyRecord;
use Modules\HR\Models\HrAttendanceSession;
use Modules\HR\Models\HrEmployee;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HrAttendanceReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, HrAttendanceSession>
     */
    public function paginate(int $companyId, array $filters, ?array $branchIds, int $perPage = 30): LengthAwarePaginator
    {
        return $this->sessionQuery($companyId, $filters, $branchIds)
            ->with([
                'employee:id,doc_num,full_name',
                'assignedBranch:id,doc_num,name',
                'shift:id,doc_num,name',
                'events:id,session_id,event_type,occurred_at,geofence_status,distance_meters',
            ])
            ->latest('started_at')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{session_count: int, employee_count: int, open_count: int, worked_minutes: int, break_minutes: int, late_minutes: int, early_leave_minutes: int, overtime_minutes: int}
     */
    public function summary(int $companyId, array $filters, ?array $branchIds): array
    {
        $sessionSummary = $this->sessionQuery($companyId, $filters, $branchIds)
            ->toBase()
            ->selectRaw('COUNT(*) AS session_count')
            ->selectRaw('COUNT(DISTINCT employee_id) AS employee_count')
            ->selectRaw("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count")
            ->selectRaw('COALESCE(SUM(worked_minutes), 0) AS worked_minutes')
            ->selectRaw('COALESCE(SUM(total_break_minutes), 0) AS break_minutes')
            ->first();

        $dailySummary = $this->dailyRecordQuery($companyId, $filters, $branchIds)
            ->toBase()
            ->selectRaw('COALESCE(SUM(late_minutes), 0) AS late_minutes')
            ->selectRaw('COALESCE(SUM(early_leave_minutes), 0) AS early_leave_minutes')
            ->selectRaw('COALESCE(SUM(overtime_minutes), 0) AS overtime_minutes')
            ->first();

        return [
            'session_count' => (int) ($sessionSummary->session_count ?? 0),
            'employee_count' => (int) ($sessionSummary->employee_count ?? 0),
            'open_count' => (int) ($sessionSummary->open_count ?? 0),
            'worked_minutes' => (int) ($sessionSummary->worked_minutes ?? 0),
            'break_minutes' => (int) ($sessionSummary->break_minutes ?? 0),
            'late_minutes' => (int) ($dailySummary->late_minutes ?? 0),
            'early_leave_minutes' => (int) ($dailySummary->early_leave_minutes ?? 0),
            'overtime_minutes' => (int) ($dailySummary->overtime_minutes ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(int $companyId, array $filters, ?array $branchIds): StreamedResponse
    {
        $sessions = $this->sessionQuery($companyId, $filters, $branchIds)
            ->with([
                'employee:id,doc_num,full_name',
                'assignedBranch:id,doc_num,name',
                'shift:id,doc_num,name',
                'events:id,session_id,event_type,occurred_at,geofence_status,distance_meters',
            ])
            ->latest('started_at')
            ->latest('id');

        return response()->streamDownload(function () use ($sessions): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                __('hr_attendance.report.columns.work_date'),
                __('hr_attendance.report.columns.employee_code'),
                __('hr_attendance.report.columns.employee'),
                __('hr_attendance.report.columns.branch'),
                __('hr_attendance.report.columns.shift'),
                __('hr_attendance.report.columns.status'),
                __('hr_attendance.report.columns.check_in'),
                __('hr_attendance.report.columns.check_out'),
                __('hr_attendance.report.columns.worked_minutes'),
                __('hr_attendance.report.columns.break_minutes'),
                __('hr_attendance.report.columns.events'),
                __('hr_attendance.report.columns.location_result'),
            ], ',', '"', '\\');

            foreach ($sessions->lazy(200) as $session) {
                $locationResults = $session->events
                    ->pluck('geofence_status')
                    ->filter()
                    ->unique()
                    ->map(fn (string $status): string => __('hr_attendance.geofence.'.$status))
                    ->implode(' | ');

                fputcsv($stream, [
                    $session->work_date?->toDateString(),
                    $this->csvSafe($session->employee?->doc_num),
                    $this->csvSafe($session->employee?->full_name),
                    $this->csvSafe($session->assignedBranch?->name),
                    $this->csvSafe($session->shift?->name),
                    __('hr_attendance.session_status.'.$session->status),
                    $session->started_at?->toDateTimeString(),
                    $session->ended_at?->toDateTimeString(),
                    $session->worked_minutes,
                    $session->total_break_minutes,
                    $session->events->map(fn ($event): string => __('hr_attendance.actions.'.$event->event_type).' '.$event->occurred_at?->toDateTimeString())->implode(' | '),
                    $locationResults,
                ], ',', '"', '\\');
            }

            fclose($stream);
        }, 'employee-attendance-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<HrAttendanceSession>
     */
    private function sessionQuery(int $companyId, array $filters, ?array $branchIds): Builder
    {
        return HrAttendanceSession::query()
            ->where('company_id', $companyId)
            ->when($branchIds !== null, fn (Builder $query): Builder => $query->whereIn('assigned_branch_id', $branchIds !== [] ? $branchIds : [0]))
            ->when(filled($filters['employee'] ?? null), fn (Builder $query): Builder => $query->whereIn('employee_id', HrEmployee::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $filters['employee'])
                ->select('id')))
            ->when(filled($filters['branch'] ?? null), fn (Builder $query): Builder => $query->whereIn('assigned_branch_id', Branch::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $filters['branch'])
                ->select('id')))
            ->when(filled($filters['status'] ?? null), fn (Builder $query): Builder => $query->where('status', $filters['status']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $query): Builder => $query->where('work_date', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $query): Builder => $query->where('work_date', '<', CarbonImmutable::parse($filters['date_to'])->addDay()->toDateString()));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<HrAttendanceDailyRecord>
     */
    private function dailyRecordQuery(int $companyId, array $filters, ?array $branchIds): Builder
    {
        return HrAttendanceDailyRecord::query()
            ->where('company_id', $companyId)
            ->when($branchIds !== null, fn (Builder $query): Builder => $query->whereIn('branch_id', $branchIds !== [] ? $branchIds : [0]))
            ->when(filled($filters['employee'] ?? null), fn (Builder $query): Builder => $query->whereIn('employee_id', HrEmployee::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $filters['employee'])
                ->select('id')))
            ->when(filled($filters['branch'] ?? null), fn (Builder $query): Builder => $query->whereIn('branch_id', Branch::query()
                ->where('company_id', $companyId)
                ->where('doc_num', $filters['branch'])
                ->select('id')))
            ->when(($filters['status'] ?? null) === HrAttendanceSession::StatusOpen, fn (Builder $query): Builder => $query->whereNull('check_out_at'))
            ->when(($filters['status'] ?? null) === HrAttendanceSession::StatusClosed, fn (Builder $query): Builder => $query->whereNotNull('check_out_at'))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $query): Builder => $query->where('work_date', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $query): Builder => $query->where('work_date', '<', CarbonImmutable::parse($filters['date_to'])->addDay()->toDateString()));
    }

    private function csvSafe(?string $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^[=+@\-\t\r]/u', $value) === 1 ? "'".$value : $value;
    }
}
