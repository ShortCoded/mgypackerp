@extends('reports.layouts.pdf')

@section('report')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $attendanceColumnWidths = ['8%', '28%', '16%', '8%', '8%', '8%', '6%', '6%', '12%'];
    @endphp
    <style>
        .attendance-report .report-table { table-layout: fixed; }
        .attendance-report .report-table th, .attendance-report .report-table td { font-size: 12px; padding: 4px; line-height: 1.3; }
        .attendance-report .report-table th { white-space: normal; }
        .attendance-report .attendance-summary { width: 100%; border-collapse: separate; border-spacing: 3px; margin-bottom: 7px; }
        .attendance-report .attendance-summary td { padding: 4px 7px; background: #eef7f8; border: 1px solid #c8dfe9; color: #14335c; text-align: center; }
        .attendance-report .attendance-summary strong { display: block; font-size: 11px; }
        .attendance-report .attendance-events td { background: #f5f9fb; color: #506276; font-size: 11px; }
    </style>
    <div class="attendance-report" dir="{{ $direction ?? 'ltr' }}">
        @if($filters !== [])
            <div class="report-filter-summary">
                @if(filled($filters['date_from'] ?? null)){{ __('hr_attendance.labels.from') }}: <span dir="ltr">{{ $dates->formatDate($filters['date_from']) }}</span> @endif
                @if(filled($filters['date_to'] ?? null)){{ __('hr_attendance.labels.to') }}: <span dir="ltr">{{ $dates->formatDate($filters['date_to']) }}</span> @endif
                @if(filled($filters['branch'] ?? null)){{ __('hr_attendance.report.columns.branch') }}: <span dir="ltr">{{ $filters['branch'] }}</span> @endif
                @if(filled($filters['employee'] ?? null)){{ __('hr_attendance.report.columns.employee_code') }}: <span dir="ltr">{{ $filters['employee'] }}</span> @endif
                @if(filled($filters['status'] ?? null)){{ __('hr_attendance.report.columns.status') }}: {{ __('hr_attendance.session_status.'.$filters['status']) }} @endif
            </div>
        @endif
        @if($summaryRows !== [])
            <table class="attendance-summary"><tbody>
                @foreach(collect($summaryRows)->chunk(4) as $group)
                    <tr>
                        @foreach($group as [$label, $value])
                            <td>{{ $label }}<br><strong dir="ltr">{{ $value }}</strong></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody></table>
        @endif
        <table class="report-table">
            <colgroup>
                @foreach($attendanceColumnWidths as $width)
                    <col style="width: {{ $width }};">
                @endforeach
            </colgroup>
            <thead><tr>
                @foreach(['work_date', 'employee', 'branch', 'status', 'check_in', 'check_out', 'worked_minutes', 'break_minutes', 'location_result'] as $column)
                    <th style="width: {{ $attendanceColumnWidths[$loop->index] }};">{{ __('hr_attendance.report.columns.'.$column) }}@if($column === 'branch') / {{ __('hr_attendance.report.columns.shift') }}@endif</th>
                @endforeach
            </tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td dir="ltr">{{ $row[0] }}</td>
                        <td>{{ $row[2] }}<br><span dir="ltr">{{ $row[1] }}</span></td>
                        <td>{{ $row[3] }} @if($row[4] !== '')<br>{{ $row[4] }}@endif</td>
                        <td>{{ $row[5] }}</td>
                        <td dir="ltr">{{ $row[6] }}</td>
                        <td dir="ltr">{{ $row[7] }}</td>
                        <td dir="ltr">{{ $row[8] }}</td>
                        <td dir="ltr">{{ $row[9] }}</td>
                        <td>{{ $row[11] }}</td>
                    </tr>
                    @if($row[10] !== '')
                        <tr class="attendance-events"><td colspan="9">{{ __('hr_attendance.report.columns.events') }}: {{ $row[10] }}</td></tr>
                    @endif
                @empty
                    <tr><td colspan="9" class="report-empty-cell">{{ __('reports.no_data') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
