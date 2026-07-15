@extends('layouts.app')

@section('title', $screen['title'])

@section('content')
    <div class="card">
        <div class="card-body p-4 p-lg-5">
            <div class="row align-items-center justify-content-center g-4">
                <div class="col-auto">
                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 4.5rem; height: 4.5rem;">
                        <span class="fas fa-{{ $screen['icon'] }} fs-5"></span>
                    </div>
                </div>
                <div class="col-lg">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                            {{ __('erp_expanded_screens.placeholder.status') }}
                        </span>
                        <span class="text-body-secondary small">{{ $screen['module_title'] }}</span>
                    </div>
                    <h4 class="mb-3">{{ $screen['title'] }}</h4>
                    <p class="mb-2 text-body-secondary">{{ __('erp_expanded_screens.placeholder.primary') }}</p>
                    <p class="mb-0 text-body-secondary">{{ __('erp_expanded_screens.placeholder.secondary') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
