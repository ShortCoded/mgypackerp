@props(['report'])

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $displayValue = static function (mixed $value) use ($numbers, $dates): string {
        if ($value instanceof \DateTimeInterface) {
            return $dates->formatDate($value, '');
        }

        if (is_numeric($value)) {
            return $numbers->format($value);
        }

        return filled($value) ? (string) $value : __('common.empty_value');
    };
    $rowClass = static fn (array $row): string => match (data_get($row, '_row_state')) {
        'difference' => 'table-danger',
        'reversed' => 'table-warning',
        default => '',
    };
    $cellLink = static fn (array $row, string $key): ?string => match ($key) {
        'document' => data_get($row, '_url'),
        'asset' => data_get($row, '_asset_url'),
        'account' => data_get($row, '_account_url'),
        'journal_entry' => data_get($row, '_journal_url'),
        default => null,
    };
@endphp

@if($report['rows']->isEmpty())
    <div class="text-center px-3 py-5">
        <span class="fas fa-folder-open fa-2x text-300 mb-3"></span>
        <h6>{{ __('fixed_assets.reports.no_results') }}</h6>
        <p class="text-600 mb-0">{{ __('fixed_assets.reports.no_results_help') }}</p>
    </div>
@else
    <div class="d-md-none alert alert-info rounded-0 border-0 mb-0 py-2 small"><span class="fas fa-arrows-alt-h me-1"></span>{{ __('fixed_assets.product.scroll_table_hint') }}</div>
    <div {{ $attributes->class(['table-responsive d-none d-md-block']) }}>
        <table class="table table-sm table-bordered align-middle mb-0" style="min-width: max-content">
            <thead><tr>@foreach($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach($report['rows'] as $row)
                    <tr class="{{ $rowClass($row) }}">
                        @foreach(array_keys($report['columns']) as $key)
                            @php($value = data_get($row, $key))
                            <td @if(is_numeric($value)) class="text-end" dir="ltr" @endif>
                                @if($cellLink($row, $key) && filled($value))<a href="{{ $cellLink($row, $key) }}">{{ $displayValue($value) }}</a>@else{{ $displayValue($value) }}@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="d-md-none">
        @foreach($report['rows'] as $row)
            @php($primaryKeys = array_slice(array_keys($report['columns']), 0, 2))
            <article class="fa-report-mobile-row border-bottom {{ $rowClass($row) }}">
                <details @if($loop->first) open @endif>
                    <summary class="p-3 fw-semibold">
                        @foreach($primaryKeys as $primaryKey)<span class="d-block">{{ $displayValue(data_get($row, $primaryKey)) }}</span>@endforeach
                    </summary>
                    <div class="px-3 pb-3">
                @foreach($report['columns'] as $key => $label)
                    @continue(in_array($key, $primaryKeys, true))
                    @php($value = data_get($row, $key))
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                        <span class="text-600 small">{{ $label }}</span>
                        <span class="text-end fw-semibold text-break" @if(is_numeric($value)) dir="ltr" @endif>
                            @if($cellLink($row, $key) && filled($value))<a href="{{ $cellLink($row, $key) }}">{{ $displayValue($value) }}</a>@else{{ $displayValue($value) }}@endif
                        </span>
                    </div>
                @endforeach
                    </div>
                </details>
            </article>
        @endforeach
    </div>
@endif
