@props([
    'title',
    'emptyMessage',
    'hasRows' => false,
    'columns' => 1,
    'wide' => false,
])

<div class="card mb-3 {{ $wide ? 'report-wide-card' : '' }}" @if($wide) style="--report-table-min-width: {{ max((int) $columns * 132, 1500) }}px" @endif>
    <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h6 class="mb-0">{{ $title }}</h6>
        @if($wide)
            <div class="btn-group btn-group-sm" role="group" aria-label="{{ __('production_execution.reports.control.scroll_table') }}">
                <button type="button" class="btn btn-falcon-default" data-report-scroll="right" aria-label="{{ __('production_execution.reports.control.scroll_right') }}"><span class="fas fa-arrow-right"></span></button>
                <button type="button" class="btn btn-falcon-default" data-report-scroll="left" aria-label="{{ __('production_execution.reports.control.scroll_left') }}"><span class="fas fa-arrow-left"></span></button>
            </div>
        @endif
    </div>
    <div class="table-responsive" @if($wide) data-report-scroll-area tabindex="0" @endif>
        <table class="table table-sm table-hover align-middle mb-0" @if($wide) data-report-wide @endif>
            <thead><tr>{{ $head }}</tr></thead>
            <tbody>
                @if($hasRows)
                    {{ $slot }}
                @else
                    <tr><td colspan="{{ $columns }}" class="text-center text-600 py-4">{{ $emptyMessage }}</td></tr>
                @endif
            </tbody>
        </table>
    </div>
</div>
