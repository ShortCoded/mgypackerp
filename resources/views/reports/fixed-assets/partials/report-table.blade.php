@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $codeColumns = ['asset', 'document', 'journal_entry'];
    $rowGroups = $report['type'] === \Modules\FixedAssets\Services\FixedAssetReportService::AdditionsDisposals && $report['rows']->count() > 50
        ? $report['rows']->chunk(50)
        : collect([$report['rows']]);
    $columnKeys = array_keys($report['columns']);
    $identityKeys = array_values(array_intersect(['asset', 'name', 'document'], $columnKeys));
    if ($identityKeys === []) {
        $identityKeys = [array_shift($columnKeys)];
    }
    $valueKeys = array_values(array_diff($columnKeys, $identityKeys));
    $columnGroups = array_map(fn (array $group): array => [...$identityKeys, ...$group], array_chunk($valueKeys, max(1, 7 - count($identityKeys))));
    if ($columnGroups === []) {
        $columnGroups = [$identityKeys];
    }
@endphp

@foreach ($rowGroups as $rows)
    @foreach ($columnGroups as $columnGroup)
    <table class="fa-pdf-report-table">
        <thead>
            <tr>@foreach ($columnGroup as $key)<th>{{ $report['columns'][$key] }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="fa-pdf-row-{{ data_get($row, '_row_state', 'normal') }}">
                    @foreach ($columnGroup as $key)
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
                <tr><td colspan="{{ max(1, count($columnGroup)) }}" class="fa-pdf-empty">{{ __('reports.no_data') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @endforeach
    @if (! $loop->last)
        <pagebreak />
    @endif
@endforeach
