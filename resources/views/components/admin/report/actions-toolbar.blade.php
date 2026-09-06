@props([
    'filterTarget' => null,
    'filterLabel' => __('reports.filters'),
    'filterTitle' => __('reports.filters'),
    'showFilters' => true,
    'showRefresh' => true,
    'refreshLabel' => __('reports.refresh'),
    'refreshUrl' => null,
    'exportLabel' => __('reports.export'),
    'exportOptions' => [],
])

@php
    $filterTargetId = $filterTarget ? ltrim((string) $filterTarget, '#') : null;
    $visibleExportOptions = collect($exportOptions)
        ->filter(function (array $option): bool {
            $permission = $option['permission'] ?? null;

            return ! is_string($permission) || auth()->user()?->can($permission);
        })
        ->values();
@endphp

<div {{ $attributes->class(['d-flex flex-wrap align-items-center justify-content-start justify-content-lg-end gap-2 report-actions-toolbar report-filter-actions']) }}>
    @isset($extraActions)
        {{ $extraActions }}
    @endisset

    @if ($showRefresh)
        @if ($refreshUrl)
            <a class="btn btn-falcon-default btn-sm" href="{{ $refreshUrl }}">
                <span class="fas fa-sync-alt me-1"></span>{{ $refreshLabel }}
            </a>
        @else
            <button class="btn btn-falcon-default btn-sm js-report-refresh" type="button">
                <span class="fas fa-sync-alt me-1"></span>{{ $refreshLabel }}
            </button>
        @endif
    @endif

    @if ($visibleExportOptions->isNotEmpty())
        <div class="dropdown">
            <button class="btn btn-falcon-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <span class="fas fa-download me-1"></span>{{ $exportLabel }}
            </button>
            <div class="dropdown-menu dropdown-menu-end">
                @foreach ($visibleExportOptions as $option)
                    <a
                        class="dropdown-item js-report-export"
                        href="{{ $option['url'] }}"
                        @if ($option['newTab'] ?? false) target="_blank" rel="noopener" data-open-in-new-tab="true" @endif
                    >
                        @if (! empty($option['icon']))
                            <span class="fas fa-{{ $option['icon'] }} me-2"></span>
                        @endif
                        {{ $option['label'] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($showFilters && $filterTargetId)
        <button
            class="btn btn-falcon-default btn-sm js-report-filter-toggle"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#{{ $filterTargetId }}"
            data-report-filter-toggle
            aria-expanded="false"
            aria-controls="{{ $filterTargetId }}"
            title="{{ $filterTitle }}"
        >
            <span class="fas fa-sliders-h me-1"></span>{{ $filterLabel }}
            <span class="fas fa-chevron-down ms-1 report-filter-toggle-indicator"></span>
        </button>
    @endif
</div>
