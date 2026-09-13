@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', __('dashboard.plastics.title'))

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/modules/HR/employee-self-service.css') }}">
    <style>
        .plastics-dashboard {
            --plastics-dashboard-section-gap: 1.5rem;
        }

        .plastics-dashboard-chart {
            height: 15rem;
            min-height: 0;
        }

        .plastics-dashboard-header {
            border-bottom: 1px solid var(--falcon-border-color);
            padding: .75rem 0 1rem;
        }

        .plastics-dashboard-section {
            margin-bottom: var(--plastics-dashboard-section-gap);
            padding: 0;
        }

        .plastics-dashboard-section-heading {
            margin-bottom: .5rem;
        }

        .plastics-dashboard-section-heading h5 {
            font-size: .95rem;
        }

        .plastics-dashboard-metric .card-body {
            padding: .9rem 1rem;
        }

        .dashboard-kpi-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1rem;
        }

        .dashboard-kpi-card {
            min-height: 10.5rem;
        }

        .dashboard-kpi-category {
            font-size: .7rem;
            line-height: 1.2;
        }

        .plastics-dashboard-metric-value {
            font-size: 1.55rem;
            line-height: 1.15;
        }

        .plastics-dashboard-context span {
            max-width: 22rem;
        }

        .plastics-dashboard-icon {
            flex: 0 0 2rem;
            line-height: 1;
            text-align: center;
        }

        .plastics-dashboard-empty {
            padding: .85rem 1rem;
        }

        @media (min-width: 768px) {
            .dashboard-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 992px) {
            .dashboard-kpi-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (min-width: 1200px) {
            .dashboard-kpi-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 575.98px) {
            .plastics-dashboard-chart {
                height: 13rem;
            }
        }
    </style>
@endpush

@section('content')
    <div class="plastics-dashboard">
        <header class="mb-3 plastics-dashboard-header">
            <div class="gap-2 d-flex flex-column flex-xl-row align-items-xl-center justify-content-between">
                <div class="min-w-0">
                    <div class="mb-2 d-flex align-items-center gap-2">
                        <span class="text-primary fas fa-industry"></span>
                        <h4 class="mb-0">{{ __('dashboard.plastics.title') }}</h4>
                    </div>
                    <div class="small text-600 d-flex flex-wrap gap-3 plastics-dashboard-context">
                        <span class="text-truncate"><span class="far fa-building me-1"></span>{{ $context['company'] }}</span>
                        <span class="text-truncate"><span class="fas fa-code-branch me-1"></span>{{ $context['branch'] }}</span>
                        <span class="text-truncate"><span class="far fa-calendar-alt me-1"></span>{{ $context['financialPeriod'] }}</span>
                        <span>{{ $context['financialPeriodDates'] }}</span>
                    </div>
                </div>
                <div class="small text-600">
                    {{ __('dashboard.plastics.last_updated', ['time' => $lastUpdatedAt]) }}
                </div>
            </div>
        </header>

        @if ($employeeAttendance['linked'])
            <section class="card attendance-hero employee-self-service mb-3"
                data-status-url="{{ route('employee.hr.attendance.status') }}"
                data-punch-url="{{ route('employee.hr.attendance.punch') }}"
                data-login-url="{{ route('login') }}"
                data-messages='@json(__('hr_attendance.javascript'))'>
                <div class="card-body p-3">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                        <div>
                            <div class="small text-600">{{ __('hr_attendance.self_service.title') }} · {{ $employeeAttendance['employee']['branch'] ?: __('hr_attendance.labels.no_branch') }}</div>
                            <h5 class="mb-1 js-attendance-state-label">{{ __('hr_attendance.states.'.$employeeAttendance['state']) }}</h5>
                            <div class="small text-600 js-attendance-check-in">{{ $employeeAttendance['check_in_at'] ? __('hr_attendance.labels.checked_in_at', ['time' => $employeeAttendance['check_in_display']]) : __('hr_attendance.labels.not_checked_in') }}</div>
                            <span class="d-none js-attendance-clock" data-check-in-at="{{ $employeeAttendance['check_in_at'] }}" data-state="{{ $employeeAttendance['state'] }}"></span>
                            <span class="d-none js-worked-minutes">{{ $employeeAttendance['worked_minutes'] }}</span><span class="d-none js-break-minutes">{{ $employeeAttendance['break_minutes'] }}</span>
                        </div>
                        <div class="attendance-actions js-attendance-actions flex-grow-1">
                            @foreach (\Modules\HR\Models\HrAttendanceEvent::types() as $action)
                                <button type="button" class="btn attendance-action-btn js-attendance-punch {{ in_array($action, $employeeAttendance['allowed_actions'], true) ? '' : 'd-none' }}" data-event-type="{{ $action }}">{{ __('hr_attendance.actions.'.$action) }}</button>
                            @endforeach
                        </div>
                        <a class="btn btn-falcon-default" href="{{ route('employee.hr.self-service.index') }}">{{ __('hr_requests.self_service.my_requests') }}</a>
                    </div>
                    <div class="js-attendance-feedback mt-2" role="status" aria-live="polite"></div>
                </div>
            </section>
        @endif

        @if ($limitations !== [])
            <div class="py-2 mb-3 alert alert-info">
                @foreach ($limitations as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        @endif

        @if ($metrics !== [])
            <section class="plastics-dashboard-section">
                <div class="d-flex align-items-center justify-content-between plastics-dashboard-section-heading">
                    <h5 class="mb-0">{{ __('dashboard.plastics.sections.operational_overview') }}</h5>
                </div>
                <div class="dashboard-kpi-grid">
                    @foreach ($metrics as $metric)
                        <div class="card h-100 plastics-dashboard-metric dashboard-kpi-card">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between gap-3">
                                    <div class="min-w-0">
                                        <p class="mb-1 fw-semibold text-600 dashboard-kpi-category">{{ $metric['category'] }}</p>
                                        <p class="mb-1 small text-600">{{ $metric['title'] }}</p>
                                        <div class="mb-1 fw-semibold text-900 plastics-dashboard-metric-value dt-number-value" dir="ltr">{{ $metric['value'] }}</div>
                                        <p class="mb-0 small text-600">{{ $metric['meta'] }}</p>
                                    </div>
                                    <div class="text-{{ $metric['color'] }} plastics-dashboard-icon" aria-hidden="true">
                                        <span class="fa-2x fas fa-{{ $metric['icon'] }}"></span>
                                    </div>
                                </div>
                                @if ($metric['url'])
                                    <div class="pt-2 mt-auto">
                                        <a class="btn btn-sm btn-falcon-default" href="{{ $metric['url'] }}">
                                            <span class="fas fa-arrow-right me-1"></span>{{ __('dashboard.plastics.actions.open') }}
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="plastics-dashboard-section">
            <div class="row g-2 g-xl-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="py-2 card-header border-bottom">
                            <h5 class="mb-0 fs-9">{{ __('dashboard.plastics.sections.attention') }}</h5>
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
                                <div class="pb-2 mb-2 border-bottom">
                                    <div class="gap-2 d-flex justify-content-between">
                                        <div>
                                            <span class="badge rounded-pill badge-subtle-{{ $alertClass }}">{{ $alert['title'] }}</span>
                                            <p class="mt-2 mb-0">{{ $alert['body'] }}</p>
                                        </div>
                                        @if ($alert['url'])
                                            <a class="btn btn-sm btn-falcon-default align-self-start" href="{{ $alert['url'] }}" aria-label="{{ __('dashboard.plastics.actions.open') }}">
                                                <span class="fas fa-arrow-right"></span>
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="mb-0 text-600">{{ __('dashboard.plastics.empty.no_alerts') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card">
                        <div class="py-2 card-header border-bottom">
                            <h5 class="mb-0 fs-9">{{ __('dashboard.plastics.sections.quick_actions') }}</h5>
                        </div>
                        <div class="card-body">
                            @forelse ($quickActions as $action)
                                <a class="mb-2 btn btn-falcon-default me-2" href="{{ $action['url'] }}">
                                    <span class="fas fa-{{ $action['icon'] }} me-1"></span>{{ $action['label'] }}
                                </a>
                            @empty
                                <p class="mb-0 text-600">{{ __('dashboard.plastics.empty.no_quick_actions') }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="plastics-dashboard-section">
            <div class="d-flex align-items-center justify-content-between plastics-dashboard-section-heading">
                <h5 class="mb-0">{{ __('dashboard.plastics.sections.charts') }}</h5>
            </div>
            @if ($charts !== [])
                <div class="row g-2 g-xl-3">
                    @foreach ($charts as $chart)
                        <div class="col-12 col-xl-6">
                            <div class="card h-100">
                                <div class="py-2 card-header border-bottom">
                                    <h6 class="mb-0">{{ $chart['title'] }}</h6>
                                </div>
                                <div class="p-3 card-body">
                                    <div id="{{ $chart['id'] }}" class="plastics-dashboard-chart" data-dashboard-chart data-chart-options='@json($chart['options'])'></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="card">
                    <div class="card-body plastics-dashboard-empty">
                        <p class="mb-0 text-600">{{ __('dashboard.plastics.empty.no_chart_data') }}</p>
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/modules/HR/employee-self-service.js') }}"></script>
@endpush

@push('scripts')
    @php
        $erpAsset = app(\Modules\Core\Services\AssetVersionService::class);
    @endphp
    @if ($charts !== [])
        <script src="{{ $erpAsset->url('vendors/echarts/echarts.min.js') }}"></script>
        <script src="{{ $erpAsset->url('assets/js/modules/Core/expanded-dashboard.js') }}"></script>
    @endif
@endpush
