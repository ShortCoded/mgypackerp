@extends('layouts.app')

@section('title', $screenLabel)

@section('content')
    @php
        $backUrl = url()->previous() !== url()->current() ? url()->previous() : route('dashboard');
    @endphp

    <div class="card mb-3">
        <div class="card-header bg-body-tertiary">
            <div class="row align-items-center g-2">
                <div class="col">
                    <div class="d-flex align-items-center">
                        <span class="fas fa-{{ $icon }} text-primary fs-6 me-3"></span>
                        <div>
                            <p class="mb-1 text-600 fs-10">{{ $moduleLabel }}</p>
                            <h5 class="mb-0 text-900">{{ $screenLabel }}</h5>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-body">
            <div class="row g-4 align-items-center py-3">
                <div class="col-lg-7">
                    <div class="d-flex align-items-start">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-info-subtle text-info flex-shrink-0 me-3" style="width: 3rem; height: 3rem;">
                            <span class="fas fa-info-circle fs-5"></span>
                        </span>
                        <div>
                            <p class="mb-1 text-600 fs-10">{{ __('mvp.empty.subtitle') }}</p>
                            <h5 class="mb-2 text-900">{{ __('mvp.empty.title') }}</h5>
                            <p class="mb-2 text-700">{{ __('mvp.empty.message') }}</p>
                            <p class="mb-0 text-600 fs-10">{{ __('mvp.empty.secondary') }}</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="border rounded-2 p-3 bg-body-tertiary">
                        <h6 class="mb-3 text-800">{{ __('mvp.sections.setup_requirements') }}</h6>
                        <ul class="list-unstyled mb-0">
                            @foreach (__('mvp.requirements') as $requirement)
                                <li class="d-flex align-items-start mb-2">
                                    <span class="fas fa-check text-success mt-1 me-2"></span>
                                    <span>{{ $requirement }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-body-tertiary">
            <a class="btn btn-falcon-default" href="{{ $backUrl }}">
                <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
            </a>
        </div>
    </div>
@endsection
