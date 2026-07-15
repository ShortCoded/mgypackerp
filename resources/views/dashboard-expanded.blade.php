@extends('layouts.app')

@section('title', __('dashboard.expanded.title'))

@push('styles')
    <style>
        .erp-dashboard-kpi {
            min-height: 8.75rem;
        }

        .erp-dashboard-chart {
            min-height: 17.5rem;
        }

        .erp-dashboard-list {
            max-height: 23rem;
            overflow: auto;
        }
    </style>
@endpush

@section('content')
    <div class="mb-3 row g-3">
        <div class="col-12">
            <div class="shadow-sm card">
                <div class="py-3 card-body">
                    <div class="gap-3 d-flex flex-column flex-xl-row align-items-xl-center justify-content-between">
                        <div>
                            <div class="mb-2 d-flex align-items-center gap-2">
                                <span class="text-primary fas fa-chart-line"></span>
                                <h4 class="mb-0">{{ __('dashboard.expanded.title') }}</h4>
                            </div>
                            <div class="small text-600 d-flex flex-wrap gap-2">
                                <span><span class="far fa-building me-1"></span>{{ $context['company'] }}</span>
                                <span><span class="fas fa-code-branch me-1"></span>{{ $context['branch'] }}</span>
                                <span><span class="far fa-calendar-alt me-1"></span>{{ $context['financialPeriod'] }}</span>
                                <span>{{ $context['financialPeriodDates'] }}</span>
                            </div>
                        </div>
                        <form method="GET" action="{{ route('dashboard') }}" class="gap-2 d-flex flex-column flex-md-row align-items-md-end">
                            <div>
                                <label class="form-label small mb-1" for="dashboard-range">{{ __('dashboard.expanded.filters.range') }}</label>
                                <select id="dashboard-range" class="form-select form-select-sm" name="range">
                                    @foreach ($range['options'] as $key => $label)
                                        <option value="{{ $key }}" @selected($range['key'] === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label small mb-1" for="dashboard-date-from">{{ __('dashboard.expanded.filters.date_from') }}</label>
                                <input id="dashboard-date-from" class="form-control form-control-sm" type="date" name="date_from" value="{{ $range['fromValue'] }}">
                            </div>
                            <div>
                                <label class="form-label small mb-1" for="dashboard-date-to">{{ __('dashboard.expanded.filters.date_to') }}</label>
                                <input id="dashboard-date-to" class="form-control form-control-sm" type="date" name="date_to" value="{{ $range['toValue'] }}">
                            </div>
                            <button class="btn btn-sm btn-falcon-primary" type="submit">
                                <span class="fas fa-sync-alt me-1"></span>{{ __('dashboard.expanded.actions.refresh') }}
                            </button>
                        </form>
                    </div>
                    <div class="mt-2 small text-600 d-flex flex-wrap gap-3">
                        <span>{{ $range['label'] }}</span>
                        <span>{{ __('dashboard.expanded.last_updated', ['time' => $lastUpdatedAt]) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($limitations !== [])
        <div class="mb-3 alert alert-info">
            @foreach ($limitations as $message)
                <div>{{ $message }}</div>
            @endforeach
        </div>
    @endif

    @if ($kpis !== [])
        <div class="mb-3 row g-3">
            @foreach ($kpis as $kpi)
                <div class="col-sm-6 col-xl-3">
                    <div class="card erp-dashboard-kpi h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between gap-3">
                                <div>
                                    <p class="mb-1 text-600 small">{{ $kpi['title'] }}</p>
                                    <h3 class="mb-1">{{ $kpi['value'] }}</h3>
                                    <p class="mb-0 small text-600">{{ $kpi['meta'] }}</p>
                                </div>
                                <div class="text-{{ $kpi['color'] }}">
                                    <span class="fa-2x fas fa-{{ $kpi['icon'] }}"></span>
                                </div>
                            </div>
                            @if ($kpi['url'])
                                <a class="mt-3 btn btn-sm btn-falcon-default" href="{{ $kpi['url'] }}">
                                    <span class="fas fa-arrow-right me-1"></span>{{ __('dashboard.expanded.actions.open') }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mb-3 row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header border-bottom">
                    <h5 class="mb-0">{{ __('dashboard.expanded.sections.exceptions') }}</h5>
                </div>
                <div class="card-body">
                    @forelse ($alerts as $alert)
                        @php
                            $alertClass = match ($alert['severity']) {
                                'danger' => 'danger',
                                'warning' => 'warning',
                                'info' => 'info',
                                default => 'secondary',
                            };
                        @endphp
                        <div class="pb-3 mb-3 border-bottom">
                            <div class="gap-2 d-flex justify-content-between">
                                <div>
                                    <span class="badge rounded-pill badge-subtle-{{ $alertClass }}">{{ $alert['title'] }}</span>
                                    <p class="mt-2 mb-0">{{ $alert['body'] }}</p>
                                </div>
                                @if ($alert['url'])
                                    <a class="btn btn-sm btn-falcon-default align-self-start" href="{{ $alert['url'] }}">
                                        <span class="fas fa-arrow-right"></span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="mb-0 text-600">{{ __('dashboard.expanded.empty.no_alerts') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header border-bottom">
                    <h5 class="mb-0">{{ __('dashboard.expanded.sections.quick_actions') }}</h5>
                </div>
                <div class="card-body">
                    @forelse ($quickActions as $action)
                        <a class="mb-2 btn btn-falcon-default me-2" href="{{ $action['url'] }}">
                            <span class="fas fa-{{ $action['icon'] }} me-1"></span>{{ $action['label'] }}
                        </a>
                    @empty
                        <p class="mb-0 text-600">{{ __('dashboard.expanded.empty.no_quick_actions') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @if ($charts !== [])
        <div class="mb-3 row g-3">
            @foreach ($charts as $chart)
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header border-bottom">
                            <h5 class="mb-0">{{ $chart['title'] }}</h5>
                        </div>
                        <div class="card-body">
                            <div id="{{ $chart['id'] }}" class="erp-dashboard-chart" data-dashboard-chart data-chart-options='@json($chart['options'])'></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="row g-3">
        @forelse ($recentSections as $section)
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-header border-bottom">
                        <h5 class="mb-0">{{ $section['title'] }}</h5>
                    </div>
                    <div class="p-0 card-body erp-dashboard-list">
                        <div class="list-group list-group-flush">
                            @foreach ($section['items'] as $item)
                                <a class="list-group-item list-group-item-action {{ $item['url'] ? '' : 'disabled' }}" href="{{ $item['url'] ?: '#!' }}">
                                    <div class="gap-2 d-flex justify-content-between">
                                        <div>
                                            <div class="fw-semi-bold">{{ $item['title'] }}</div>
                                            <div class="small text-600">{{ $item['meta'] }}</div>
                                        </div>
                                        @if ($item['badge'])
                                            <span class="badge rounded-pill badge-subtle-primary align-self-start">{{ $item['badge'] }}</span>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <p class="mb-0 text-600">{{ __('dashboard.expanded.empty.no_recent_records') }}</p>
                    </div>
                </div>
            </div>
        @endforelse
    </div>
@endsection

@push('scripts')
    @php
        $erpAsset = app(\Modules\Core\Services\AssetVersionService::class);
    @endphp
    @if ($charts !== [])
        <script src="{{ $erpAsset->url('vendors/echarts/echarts.min.js') }}"></script>
        <script src="{{ $erpAsset->url('assets/js/modules/Core/expanded-dashboard.js') }}"></script>
    @endif
@endpush
