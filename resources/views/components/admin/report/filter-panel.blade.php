@props([
    'id',
    'title' => __('reports.filters'),
    'description' => null,
    'applyLabel' => __('reports.apply_filter'),
    'resetLabel' => __('reports.reset'),
])

<div class="collapse report-filter-collapse erp-filter-panel" id="{{ $id }}">
    <div class="card mb-3 report-filter-card erp-filter-card">
        <div class="card-header py-2">
            <div class="min-w-0">
                <h6 class="mb-0">{{ $title }}</h6>
                @if ($description)
                    <div class="text-600 fs-11">{{ $description }}</div>
                @endif
            </div>
        </div>
        <div class="card-body py-3 erp-filter-body">
            <form class="js-report-filters">
                <div class="row gx-2 gy-2 erp-filter-grid">
                    {{ $slot }}
                </div>

                <div class="border-top mt-3 pt-3 report-filter-actions-row">
                    <div class="d-flex flex-wrap align-items-center gap-2 report-filter-buttons erp-filter-buttons">
                        <button class="btn btn-falcon-primary btn-sm" type="submit">{{ $applyLabel }}</button>
                        <button class="btn btn-falcon-default btn-sm js-report-reset" type="button">{{ $resetLabel }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
