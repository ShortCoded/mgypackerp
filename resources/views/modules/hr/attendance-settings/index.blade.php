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
                                <div class="col-12">
                                    <x-forms.label :for="'attendance_map_url_'.$branch->getKey()" :label="__('hr_attendance_settings.fields.map_url')" />
                                    <div class="input-group">
                                        <x-forms.input class="form-control js-map-url" :id="'attendance_map_url_'.$branch->getKey()" name="attendance_map_url" type="url" dir="ltr" :placeholder="__('hr_attendance_settings.placeholders.map_url')" />
                                        <button class="btn btn-outline-secondary js-resolve-map-url" type="button">{{ __('hr_attendance_settings.actions.resolve_url') }}</button>
                                    </div>
                                    <div class="alert alert-info py-2 mt-2 mb-0 js-location-status" role="status">{{ __('hr_attendance_settings.map_url_help') }}</div>
                                </div>
                                <div class="col-6">
                                    <x-forms.label :for="'attendance_latitude_'.$branch->getKey()" :label="__('branches.attributes.attendance_latitude')" />
                                    <x-forms.numeric-input class="js-latitude" :id="'attendance_latitude_'.$branch->getKey()" name="attendance_latitude" :scale="7" :allow-negative="true" step="0.0000001" :value="$branch->attendance_latitude" />
                                </div>
                                <div class="col-6">
                                    <x-forms.label :for="'attendance_longitude_'.$branch->getKey()" :label="__('branches.attributes.attendance_longitude')" />
                                    <x-forms.numeric-input class="js-longitude" :id="'attendance_longitude_'.$branch->getKey()" name="attendance_longitude" :scale="7" :allow-negative="true" step="0.0000001" :value="$branch->attendance_longitude" />
                                </div>
                                <div class="col-6">
                                    <x-forms.label :for="'attendance_radius_meters_'.$branch->getKey()" :label="__('branches.attributes.attendance_radius_meters')" :required="true" />
                                    <x-forms.numeric-input :id="'attendance_radius_meters_'.$branch->getKey()" name="attendance_radius_meters" :scale="0" min="10" max="10000" step="1" :value="$branch->attendance_radius_meters" required />
                                </div>
                                <div class="col-6">
                                    <x-forms.label :for="'attendance_max_accuracy_meters_'.$branch->getKey()" :label="__('branches.attributes.attendance_max_accuracy_meters')" :required="true" />
                                    <x-forms.numeric-input :id="'attendance_max_accuracy_meters_'.$branch->getKey()" name="attendance_max_accuracy_meters" :scale="0" min="5" max="5000" step="1" :value="$branch->attendance_max_accuracy_meters" required />
                                </div>
                                <div class="col-12">
                                    <x-forms.label :for="'attendance_location_policy_'.$branch->getKey()" :label="__('branches.attributes.attendance_location_policy')" :required="true" />
                                    <x-forms.select :id="'attendance_location_policy_'.$branch->getKey()" variant="local" name="attendance_location_policy" :allow-clear="false" required>@foreach (['allow', 'warn', 'reject'] as $policy)<option value="{{ $policy }}" @selected($branch->attendance_location_policy === $policy)>{{ __('branches.attendance_location.policies.'.$policy) }}</option>@endforeach</x-forms.select>
                                </div>
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
    (() => {
        const resolveUrl = @json(route('admin.hr.attendance-settings.resolve-map-url'));
        const csrf = @json(csrf_token());
        const messages = {
            resolving: @json(__('hr_attendance_settings.messages.resolving')),
            resolved: @json(__('hr_attendance_settings.messages.resolved')),
            resolveFailed: @json(__('hr_attendance_settings.messages.resolve_failed')),
            locating: @json(__('hr_attendance_settings.messages.locating')),
            locationUnavailable: @json(__('hr_attendance_settings.messages.location_unavailable')),
        };

        const setCoordinates = (form, latitude, longitude) => {
            form.querySelector('.js-latitude').value = Number(latitude).toFixed(7);
            form.querySelector('.js-longitude').value = Number(longitude).toFixed(7);
        };

        const setStatus = (status, message, type = 'info') => {
            status.textContent = message;
            status.className = `alert alert-${type} py-2 mt-2 mb-0 js-location-status`;
        };

        const resolveMapUrl = async form => {
            const input = form.querySelector('.js-map-url');
            const status = form.querySelector('.js-location-status');
            if (!input.value.trim()) return;
            setStatus(status, messages.resolving);
            try {
                const response = await fetch(resolveUrl, {
                    method: 'POST',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({map_url: input.value.trim()}),
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload?.errors?.map_url?.[0] || messages.resolveFailed);
                setCoordinates(form, payload.latitude, payload.longitude);
                setStatus(status, messages.resolved, 'success');
            } catch (error) {
                setStatus(status, error.message || messages.resolveFailed, 'danger');
            }
        };

        document.querySelectorAll('.js-attendance-settings-form').forEach(form => {
            form.querySelector('.js-resolve-map-url')?.addEventListener('click', () => resolveMapUrl(form));
            form.querySelector('.js-map-url')?.addEventListener('change', () => resolveMapUrl(form));
            form.querySelector('.js-capture-location')?.addEventListener('click', buttonEvent => {
                const status = form.querySelector('.js-location-status');
                if (!navigator.geolocation) {
                    setStatus(status, messages.locationUnavailable, 'danger');
                    return;
                }
                buttonEvent.currentTarget.disabled = true;
                setStatus(status, messages.locating);
                navigator.geolocation.getCurrentPosition(position => {
                    setCoordinates(form, position.coords.latitude, position.coords.longitude);
                    setStatus(status, messages.resolved, 'success');
                    buttonEvent.currentTarget.disabled = false;
                }, () => {
                    setStatus(status, messages.locationUnavailable, 'danger');
                    buttonEvent.currentTarget.disabled = false;
                }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
            });
        });
    })();
</script>
@endpush
