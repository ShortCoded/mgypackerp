@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp

@section('title', __('hr_attendance.self_service.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3 employee-self-service"
        data-status-url="{{ route('employee.hr.attendance.status') }}"
        data-punch-url="{{ route('employee.hr.attendance.punch') }}"
        data-login-url="{{ route('login') }}"
        data-messages='@json(__('hr_attendance.javascript'))'>
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @unless ($attendance['linked'])
            <div class="alert alert-warning mb-3">
                <h5 class="alert-heading">{{ __('hr_attendance.self_service.not_linked_title') }}</h5>
                <p class="mb-0">{{ __('hr_attendance.self_service.not_linked_help') }}</p>
            </div>
        @else
            <section class="card attendance-hero mb-3" aria-labelledby="attendance-status-title">
                <div class="card-body p-3 p-sm-4">
                    <div class="d-flex flex-column flex-sm-row justify-content-between gap-3">
                        <div>
                            <div class="text-600 small">{{ $attendance['employee']['name'] }} · {{ $attendance['employee']['branch'] ?: __('hr_attendance.labels.no_branch') }}</div>
                            <h3 id="attendance-status-title" class="mt-2 mb-1 js-attendance-state-label">{{ __('hr_attendance.states.'.$attendance['state']) }}</h3>
                            <div class="attendance-live-time js-attendance-clock" data-check-in-at="{{ $attendance['check_in_at'] }}" data-state="{{ $attendance['state'] }}">
                                {{ intdiv((int) $attendance['worked_minutes'], 60) }}:{{ str_pad((string) ((int) $attendance['worked_minutes'] % 60), 2, '0', STR_PAD_LEFT) }}
                            </div>
                            <div class="text-600 small js-attendance-check-in">
                                @if ($attendance['check_in_at'])
                                    {{ __('hr_attendance.labels.checked_in_at', ['time' => $dates->formatDateTime($attendance['check_in_at'], '')]) }}
                                @else
                                    {{ __('hr_attendance.labels.not_checked_in') }}
                                @endif
                            </div>
                        </div>
                        <div class="attendance-summary-grid">
                            <div><span>{{ __('hr_attendance.labels.worked') }}</span><strong class="js-worked-minutes">{{ $attendance['worked_minutes'] }}</strong><small>{{ __('hr_attendance.labels.minutes') }}</small></div>
                            <div><span>{{ __('hr_attendance.labels.breaks') }}</span><strong class="js-break-minutes">{{ $attendance['break_minutes'] }}</strong><small>{{ __('hr_attendance.labels.minutes') }}</small></div>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-3 py-2 js-location-notice">
                        <span class="fas fa-location-arrow me-1"></span>{{ __('hr_attendance.self_service.location_help') }}
                    </div>
                    <div class="js-attendance-feedback" role="status" aria-live="polite"></div>
                    <div class="attendance-actions js-attendance-actions">
                        @foreach (\Modules\HR\Models\HrAttendanceEvent::types() as $action)
                            <button type="button" class="btn attendance-action-btn js-attendance-punch {{ in_array($action, $attendance['allowed_actions'], true) ? '' : 'd-none' }}" data-event-type="{{ $action }}">
                                <span class="fas {{ match ($action) { 'check_in' => 'fa-sign-in-alt', 'break_start' => 'fa-coffee', 'break_end' => 'fa-play', default => 'fa-sign-out-alt' } }}"></span>
                                <span>{{ __('hr_attendance.actions.'.$action) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="card mb-3">
                <div class="card-header"><h5 class="mb-0">{{ __('hr_attendance.self_service.recent_events') }}</h5></div>
                <div class="list-group list-group-flush js-recent-events">
                    @forelse ($attendance['recent_events'] as $event)
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div><strong>{{ __('hr_attendance.actions.'.$event['type']) }}</strong><div class="small text-600">{{ $dates->formatDateTime($event['occurred_at'], '') }}</div></div>
                            <span class="badge badge-subtle-{{ $event['geofence_status'] === 'inside' ? 'success' : 'warning' }}">{{ __('hr_attendance.geofence.'.$event['geofence_status']) }}</span>
                        </div>
                    @empty
                        <div class="list-group-item text-600">{{ __('hr_attendance.labels.no_events') }}</div>
                    @endforelse
                </div>
            </section>
        @endunless

        @if ($attendance['linked'])
            <section class="card mb-3" id="employee-payslips">
                <div class="card-header"><h5 class="mb-0">{{ __('hr_payroll_reports.payslip.my_payslips') }}</h5></div>
                <div class="list-group list-group-flush">
                    @forelse ($payslips as $payslip)
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="{{ route('employee.hr.payslips.show', $payslip->id) }}">
                            <span dir="ltr">{{ $payslip->period_start }} — {{ $payslip->period_end }}</span>
                            <strong dir="ltr">{{ $numbers->format($payslip->net_amount) }}</strong>
                        </a>
                    @empty
                        <div class="list-group-item text-muted">{{ __('hr_payroll_reports.payslip.no_payslips') }}</div>
                    @endforelse
                </div>
            </section>

            <section class="card mb-3" id="employee-requests">
                <div class="card-header"><h5 class="mb-0">{{ __('hr_requests.self_service.new_request') }}</h5></div>
                <div class="card-body">
                    @if ($attendance['can_submit_requests'])
                    <form action="{{ route('employee.hr.requests.store') }}" method="POST" class="row g-3 js-employee-request-form">
                        @csrf
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="request_type">{{ __('hr_requests.labels.type') }}</label>
                            <x-forms.select class="form-select form-select-lg" id="request_type" name="request_type" required>
                                <option value="">{{ __('hr_requests.placeholders.type') }}</option>
                                @foreach ($requestTypes as $requestType)<option value="{{ $requestType }}" @selected(old('request_type') === $requestType)>{{ __('hr_requests.types.'.$requestType) }}</option>@endforeach
                            </x-forms.select>
                        </div>
                        <div class="col-12 col-md-6"><label class="form-label" for="subject">{{ __('hr_requests.labels.subject') }}</label><x-forms.input class="form-control form-control-lg" id="subject" name="subject" value="{{ old('subject') }}" /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="leave,attendance_adjustment,overtime,remote_work"><label class="form-label" for="requested_from">{{ __('hr_requests.labels.from') }}</label><x-forms.date-input class="form-control-lg" id="requested_from" name="requested_from" :value="old('requested_from')" /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="leave,remote_work"><label class="form-label" for="requested_to">{{ __('hr_requests.labels.to') }}</label><x-forms.date-input class="form-control-lg" id="requested_to" name="requested_to" :value="old('requested_to')" /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="overtime"><label class="form-label" for="requested_minutes">{{ __('hr_requests.labels.minutes') }}</label><x-forms.input class="form-control form-control-lg" id="requested_minutes" name="requested_minutes" type="number" min="1" max="1440" value="{{ old('requested_minutes') }}" /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="salary_advance"><x-forms.label for="amount" :label="__('hr_requests.labels.amount')" required /><x-forms.numeric-input class="form-control form-control-lg" id="amount" name="amount" :scale="2" min="0.01" step="0.01" :value="old('amount')" /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="salary_advance"><label class="form-label" for="currency_doc_num">{{ __('hr_requests.labels.currency') }}</label><x-forms.select class="form-select form-select-lg" id="currency_doc_num" name="currency_doc_num"><option value="">{{ __('hr_requests.placeholders.currency') }}</option>@foreach ($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected(old('currency_doc_num') === $currency->doc_num)>{{ $currency->code }} / {{ $currency->name }}</option>@endforeach</x-forms.select></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="leave">
                            <label class="form-label" for="leave_type">{{ __('hr_requests.labels.leave_type') }}</label>
                            <x-forms.select class="form-select form-select-lg" id="leave_type" name="payload[leave_type]">
                                <option value="">{{ __('hr_requests.placeholders.leave_type') }}</option>
                                @foreach ($leaveTypes as $leaveType)
                                    <option value="{{ $leaveType['code'] }}" @selected(old('payload.leave_type') === $leaveType['code'])>
                                        {{ $leaveType['name'] }}
                                    </option>
                                @endforeach
                            </x-forms.select>
                            @if ($leaveTypes !== [])
                                <div class="small text-muted mt-1">
                                    @foreach ($leaveTypes as $leaveType)
                                        @if ($leaveType['requires_balance'])
                                            @foreach ($leaveType['balances'] as $balance)
                                                <span class="d-block">{{ $leaveType['name'] }} — {{ __('hr_requests.balance.year') }} {{ $balance['year'] }}: {{ __('hr_requests.balance.current') }} {{ $numbers->format($balance['current']) }}, {{ __('hr_requests.balance.pending') }} {{ $numbers->format($balance['pending']) }}, {{ __('hr_requests.balance.available') }} {{ $numbers->format($balance['available']) }}</span>
                                            @endforeach
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="col-12 col-md-6 js-request-field" data-types="attendance_adjustment"><label class="form-label" for="requested_check_in">{{ __('hr_requests.labels.requested_check_in') }}</label><x-forms.date-input class="form-control-lg" id="requested_check_in" name="payload[requested_check_in]" :value="old('payload.requested_check_in')" enable-time /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="attendance_adjustment"><label class="form-label" for="requested_check_out">{{ __('hr_requests.labels.requested_check_out') }}</label><x-forms.date-input class="form-control-lg" id="requested_check_out" name="payload[requested_check_out]" :value="old('payload.requested_check_out')" enable-time /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="device_asset"><label class="form-label" for="asset_type">{{ __('hr_requests.labels.asset_type') }}</label><x-forms.input class="form-control form-control-lg" id="asset_type" name="payload[asset_type]" value="{{ old('payload.asset_type') }}" /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="employment_letter"><label class="form-label" for="letter_language">{{ __('hr_requests.labels.letter_language') }}</label><x-forms.select class="form-select form-select-lg" id="letter_language" name="payload[letter_language]"><option value="ar">{{ __('hr_requests.letter_languages.ar') }}</option><option value="en">{{ __('hr_requests.letter_languages.en') }}</option></x-forms.select></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="profile_update"><label class="form-label" for="profile_field">{{ __('hr_requests.labels.profile_field') }}</label><x-forms.input class="form-control form-control-lg" id="profile_field" name="payload[profile_field]" value="{{ old('payload.profile_field') }}" /></div>
                        <div class="col-12 col-md-6 js-request-field" data-types="profile_update"><label class="form-label" for="profile_value">{{ __('hr_requests.labels.profile_value') }}</label><x-forms.input class="form-control form-control-lg" id="profile_value" name="payload[profile_value]" value="{{ old('payload.profile_value') }}" /></div>
                        <div class="col-12"><label class="form-label" for="request_details">{{ __('hr_requests.labels.details') }}</label><x-forms.textarea class="form-control" id="request_details" name="details" rows="4" required>{{ old('details') }}</x-forms.textarea></div>
                        <div class="col-12"><button class="btn btn-primary btn-lg w-100 w-md-auto" type="submit"><span class="fas fa-paper-plane me-1"></span>{{ __('hr_requests.actions.submit') }}</button></div>
                    </form>
                    @else
                        <div class="alert alert-warning mb-0">{{ __('hr_attendance.states.'.$attendance['state']) }}</div>
                    @endif
                </div>
            </section>

            <section class="card">
                <div class="card-header"><h5 class="mb-0">{{ __('hr_requests.self_service.my_requests') }}</h5></div>
                <div class="card-body p-2 p-sm-3">
                    <div class="row g-2">
                        @forelse ($employeeRequests as $employeeRequest)
                            <div class="col-12 col-lg-6">
                                <article class="border rounded-3 p-3 h-100">
                                    <div class="d-flex justify-content-between gap-2 mb-2"><strong>{{ __('hr_requests.types.'.$employeeRequest->request_type) }}</strong><span class="badge badge-subtle-{{ match ($employeeRequest->status) { 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary', default => 'warning' } }}">{{ __('hr_requests.statuses.'.$employeeRequest->status) }}</span></div>
                                    <p class="mb-2">{{ $employeeRequest->details }}</p>
                                    <div class="small text-600">{{ $dates->formatDateTime($employeeRequest->submitted_at, '') }}</div>
                                    @if ($employeeRequest->status === 'submitted')
                                        <form action="{{ route('employee.hr.requests.cancel', $employeeRequest) }}" method="POST" class="mt-2">@csrf @method('PATCH')<button class="btn btn-outline-danger w-100" type="submit">{{ __('hr_requests.actions.cancel') }}</button></form>
                                    @endif
                                </article>
                            </div>
                        @empty
                            <div class="col-12 text-center text-600 py-4">{{ __('hr_requests.self_service.empty') }}</div>
                        @endforelse
                    </div>
                </div>
            </section>
        @endif
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/modules/HR/employee-self-service.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('assets/js/modules/HR/employee-self-service.js') }}"></script>
@endpush
