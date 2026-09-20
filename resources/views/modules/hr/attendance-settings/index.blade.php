@extends('layouts.app')

@section('title', __('hr_attendance_settings.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="alert alert-info">{{ __('hr_attendance_settings.authoritative_help') }}</div>
        <div class="row g-3">
            @forelse ($branches as $branch)
                <div class="col-12 col-xl-6">
                    <div class="card h-100">
                        <div class="card-header"><h5 class="mb-0">{{ $branch->name }} <small class="text-muted" dir="ltr">{{ $branch->doc_num }}</small></h5></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.hr.attendance-settings.update', $branch) }}" class="row g-3 js-attendance-settings-form">
                                @csrf @method('PATCH')
                                <div class="col-6"><label class="form-label">{{ __('branches.attributes.attendance_latitude') }}</label><input class="form-control js-latitude" name="attendance_latitude" type="number" step="0.0000001" value="{{ $branch->attendance_latitude }}"></div>
                                <div class="col-6"><label class="form-label">{{ __('branches.attributes.attendance_longitude') }}</label><input class="form-control js-longitude" name="attendance_longitude" type="number" step="0.0000001" value="{{ $branch->attendance_longitude }}"></div>
                                <div class="col-6"><label class="form-label">{{ __('branches.attributes.attendance_radius_meters') }}</label><input class="form-control" name="attendance_radius_meters" type="number" min="10" max="10000" value="{{ $branch->attendance_radius_meters }}" required></div>
                                <div class="col-6"><label class="form-label">{{ __('branches.attributes.attendance_max_accuracy_meters') }}</label><input class="form-control" name="attendance_max_accuracy_meters" type="number" min="5" max="5000" value="{{ $branch->attendance_max_accuracy_meters }}" required></div>
                                <div class="col-12"><label class="form-label">{{ __('branches.attributes.attendance_location_policy') }}</label><select class="form-select" name="attendance_location_policy" required>@foreach (['allow', 'warn', 'reject'] as $policy)<option value="{{ $policy }}" @selected($branch->attendance_location_policy === $policy)>{{ __('branches.attendance_location.policies.'.$policy) }}</option>@endforeach</select></div>
                                @can('hr.attendance_settings.manage')
                                    <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-outline-secondary js-capture-location" type="button">{{ __('hr_attendance_settings.actions.capture') }}</button><button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button></div>
                                @endcan
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12"><div class="alert alert-warning">{{ __('hr_attendance_settings.empty') }}</div></div>
            @endforelse
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('.js-capture-location').forEach(button => button.addEventListener('click', () => {
        const form = button.closest('form');
        if (!navigator.geolocation) return window.alert(@json(__('hr_attendance_settings.messages.location_unavailable')));
        navigator.geolocation.getCurrentPosition(position => {
            form.querySelector('.js-latitude').value = position.coords.latitude.toFixed(7);
            form.querySelector('.js-longitude').value = position.coords.longitude.toFixed(7);
        }, () => window.alert(@json(__('hr_attendance_settings.messages.location_unavailable'))), {enableHighAccuracy: true});
    }));
</script>
@endpush
