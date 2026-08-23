@props(['report'])

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp

<div {{ $attributes->class(['table-responsive']) }}>
    <table class="table table-sm table-bordered align-middle mb-0">
        <thead><tr>@foreach($report['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($report['rows'] as $row)
                <tr>
                    @foreach(array_keys($report['columns']) as $key)
                        @php($value = data_get($row, $key))
                        <td @if(is_numeric($value)) class="text-end" dir="ltr" @endif>
                            @if($value instanceof \DateTimeInterface)
                                {{ $dates->formatDate($value, '') }}
                            @elseif(is_numeric($value))
                                {{ $numbers->format($value) }}
                            @else
                                {{ $value ?: __('common.empty_value') }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ max(1, count($report['columns'])) }}" class="text-center text-600 py-4">{{ __('common.empty_value') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
