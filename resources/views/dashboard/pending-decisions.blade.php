@extends('layouts.app')

@section('title', __('dashboard.personal.decisions.title'))

@section('content')
    <div class="container-xl">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <div>
                <h4 class="mb-1">{{ __('dashboard.personal.decisions.title') }}</h4>
                <p class="mb-0 text-600">{{ __('dashboard.personal.decisions.subtitle') }}</p>
            </div>
            <a class="btn btn-sm btn-falcon-default" href="{{ route('dashboard') }}">
                <span class="fas fa-arrow-left me-1" aria-hidden="true"></span>{{ __('dashboard.title') }}
            </a>
        </div>

        <div class="card">
            <div class="table-responsive scrollbar">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-200 text-900">
                        <tr>
                            <th>{{ __('dashboard.personal.decisions.type') }}</th>
                            <th>{{ __('dashboard.personal.decisions.number') }}</th>
                            <th>{{ __('dashboard.personal.decisions.requester') }}</th>
                            <th>{{ __('dashboard.personal.decisions.status') }}</th>
                            <th>{{ __('dashboard.personal.decisions.required_action') }}</th>
                            <th>{{ __('dashboard.personal.decisions.waiting_since') }}</th>
                            <th class="text-end"><span class="visually-hidden">{{ __('dashboard.personal.decisions.open') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($decisions as $decision)
                            <tr>
                                <td class="fw-semibold">{{ $decision['type'] }}</td>
                                <td><span dir="ltr">{{ $decision['document_number'] }}</span></td>
                                <td>{{ $decision['requester'] }}</td>
                                <td><span class="badge badge-subtle-warning">{{ $decision['status_label'] }}</span></td>
                                <td>{{ $decision['action'] }}</td>
                                <td>{{ $decision['created_at_label'] }}</td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-primary" href="{{ $decision['url'] }}">
                                        {{ __('dashboard.personal.decisions.open') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="text-center text-600 py-5" colspan="7">
                                    <span class="far fa-clipboard fa-2x mb-3" aria-hidden="true"></span>
                                    <p class="mb-0">{{ __('dashboard.personal.decisions.empty') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($decisions->hasPages())
                <div class="card-footer">{{ $decisions->links() }}</div>
            @endif
        </div>
    </div>
@endsection
