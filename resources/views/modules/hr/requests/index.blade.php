@extends('layouts.app')

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp

@section('title', __('hr_requests.admin.title'))

@section('content')
    <div class="container-fluid px-0 px-sm-3">
        @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="card mb-3"><div class="card-body"><form method="GET" class="row g-2 align-items-end"><div class="col-6 col-md-4"><label class="form-label" for="request_type_filter">{{ __('hr_requests.labels.type') }}</label><x-forms.select class="form-select" id="request_type_filter" name="type"><option value="">{{ __('common.trash.all') }}</option>@foreach ($requestTypes as $type)<option value="{{ $type }}" @selected(request('type') === $type)>{{ __('hr_requests.types.'.$type) }}</option>@endforeach</x-forms.select></div><div class="col-6 col-md-4"><label class="form-label" for="request_status_filter">{{ __('hr_requests.labels.status') }}</label><x-forms.select class="form-select" id="request_status_filter" name="status"><option value="">{{ __('common.trash.all') }}</option>@foreach (['submitted', 'approved', 'rejected', 'cancelled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ __('hr_requests.statuses.'.$status) }}</option>@endforeach</x-forms.select></div><div class="col-12 col-md-4"><button class="btn btn-primary w-100" type="submit">{{ __('common.actions.apply') }}</button></div></form></div></div>
        <div class="row g-3">
            @forelse ($employeeRequests as $employeeRequest)
                <div class="col-12 col-xl-6"><article class="card h-100"><div class="card-body"><div class="d-flex justify-content-between gap-2 mb-2"><div><h5 class="mb-1">{{ $employeeRequest->employee?->full_name }}</h5><span class="small text-600">{{ $employeeRequest->employee?->doc_num }} · {{ __('hr_requests.types.'.$employeeRequest->request_type) }}</span></div><span class="badge badge-subtle-{{ match ($employeeRequest->status) { 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary', default => 'warning' } }} align-self-start">{{ __('hr_requests.statuses.'.$employeeRequest->status) }}</span></div><p>{{ $employeeRequest->details }}</p><div class="small text-600 mb-3">{{ $dates->formatDateTime($employeeRequest->submitted_at, '—') }}</div>
                    @if ($employeeRequest->status === 'submitted')
                        @can('hr.hr_requests.manage')<form method="POST" action="{{ route('admin.hr.hr-requests.review', $employeeRequest) }}" class="row g-2">@csrf @method('PATCH')<div class="col-12"><x-forms.textarea class="form-control" name="resolution_notes" rows="2" placeholder="{{ __('hr_requests.placeholders.resolution_notes') }}"></x-forms.textarea></div><div class="col-6"><button class="btn btn-success w-100" name="decision" value="approved">{{ __('hr_requests.actions.approve') }}</button></div><div class="col-6"><button class="btn btn-danger w-100" name="decision" value="rejected">{{ __('hr_requests.actions.reject') }}</button></div></form>@endcan
                    @elseif ($employeeRequest->resolution_notes)
                        <div class="alert alert-light mb-0">{{ $employeeRequest->resolution_notes }}</div>
                    @endif
                </div></article></div>
            @empty
                <div class="col-12"><div class="card"><div class="card-body text-center text-600 py-5">{{ __('hr_requests.admin.empty') }}</div></div></div>
            @endforelse
        </div>
        <div class="mt-3">{{ $employeeRequests->links() }}</div>
    </div>
@endsection
