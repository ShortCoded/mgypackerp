@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $codeColumns = ['asset', 'document', 'journal_entry'];
    $rowGroups = $report['type'] === \Modules\FixedAssets\Services\FixedAssetReportService::AdditionsDisposals && $report['rows']->count() > 50
        ? $report['rows']->chunk(50)
        : collect([$report['rows']]);
@endphp

@foreach ($rowGroups as $rows)
    <table class="fa-pdf-report-table">
        <thead>
            <tr>@foreach ($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="fa-pdf-row-{{ data_get($row, '_row_state', 'normal') }}">
                    @foreach (array_keys($report['columns']) as $key)
                        @php($value = data_get($row, $key))
                        <td @class(['fa-pdf-number' => is_numeric($value)])>
                            @if ($value instanceof \DateTimeInterface)
                                {{ $dates->formatDate($value, '') }}
                            @elseif (is_numeric($value))
                                {{ $numbers->format($value) }}
                            @elseif (in_array($key, $codeColumns, true))
                                <span class="fa-pdf-code">{{ $value ?: __('common.empty_value') }}</span>
                            @else
                                {{ $value ?: __('common.empty_value') }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ max(1, count($report['columns'])) }}" class="fa-pdf-empty">{{ __('reports.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @if (! $loop->last)
        <pagebreak />
    @endif
@endforeach
