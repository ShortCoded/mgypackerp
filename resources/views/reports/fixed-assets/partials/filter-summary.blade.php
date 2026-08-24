@if ($report['filters'] !== [])
    <div class="fa-pdf-filter-summary">
        <strong>{{ __('reports.active_filters') }}:</strong>
        @foreach ($report['filters'] as $label => $value)
            <span class="fa-pdf-filter-item"><strong>{{ $label }}:</strong> {{ $value }}</span>
        @endforeach
    </div>
@endif
