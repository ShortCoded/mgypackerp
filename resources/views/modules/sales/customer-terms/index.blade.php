@extends('layouts.app')

@section('title', __('customer_terms.title'))

@push('styles')
    <style>
        .customer-terms-stat { border: 0; box-shadow: 0 .125rem .5rem rgba(15, 23, 42, .06); }
        .customer-terms-stat .stat-icon { align-items: center; border-radius: 50%; display: inline-flex; height: 2.5rem; justify-content: center; width: 2.5rem; }
        .customer-terms-progress { height: .4rem; min-width: 8rem; }
        .customer-terms-avatar { align-items: center; background: var(--falcon-primary-bg-subtle, #e6effc); border-radius: 50%; color: var(--falcon-primary, #2c7be5); display: inline-flex; flex: 0 0 auto; font-weight: 700; height: 2.25rem; justify-content: center; width: 2.25rem; }
    </style>
@endpush

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1"><span class="text-primary fs-5"><span class="fas fa-file-contract"></span></span><h4 class="mb-0">{{ __('customer_terms.title') }}</h4></div>
            <p class="text-600 mb-0">{{ __('customer_terms.help') }}</p>
        </div>
    </div>

    <div class="row g-3 mb-3">
        @foreach([
            ['key' => 'total', 'label' => 'total_customers', 'icon' => 'fa-users', 'color' => 'primary'],
            ['key' => 'complete', 'label' => 'ready_customers', 'icon' => 'fa-check', 'color' => 'success'],
            ['key' => 'partial', 'label' => 'partial_customers', 'icon' => 'fa-hourglass-half', 'color' => 'warning'],
            ['key' => 'empty', 'label' => 'empty_customers', 'icon' => 'fa-clipboard', 'color' => 'secondary'],
        ] as $stat)
            <div class="col-6 col-xl-3"><div class="card customer-terms-stat h-100"><div class="card-body d-flex align-items-center gap-3 py-3"><span class="stat-icon bg-{{ $stat['color'] }}-subtle text-{{ $stat['color'] }}"><span class="fas {{ $stat['icon'] }}"></span></span><div><div class="fs-5 fw-bold text-900">{{ number_format($statistics[$stat['key']]) }}</div><div class="small text-600">{{ __('customer_terms.'.$stat['label']) }}</div></div></div></div></div>
        @endforeach
    </div>

    <div class="card shadow-none border">
        <div class="card-header bg-body-tertiary border-bottom">
            <form method="GET" action="{{ route('admin.sales.customer-terms.index') }}" class="row g-2 align-items-end" role="search">
                <div class="col-12 col-lg-7"><label class="form-label small fw-semibold" for="customer_terms_search">{{ __('customer_terms.search') }}</label><div class="input-group"><span class="input-group-text bg-white"><span class="fas fa-search text-500"></span></span><x-forms.input class="form-control" id="customer_terms_search" name="search" value="{{ $search }}" placeholder="{{ __('customer_terms.search_hint') }}" /></div></div>
                <div class="col-8 col-lg-3"><label class="form-label small fw-semibold" for="customer_terms_status">{{ __('customer_terms.filter_status') }}</label><x-forms.select class="form-select" id="customer_terms_status" name="status"><option value="all">{{ __('customer_terms.all_statuses') }}</option>@foreach(['complete', 'partial', 'empty'] as $option)<option value="{{ $option }}" @selected($status === $option)>{{ __('customer_terms.status.'.$option) }}</option>@endforeach</x-forms.select></div>
                <div class="col-4 col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><span class="fas fa-filter me-1"></span>{{ __('common.actions.apply') }}</button>@if($search !== '' || $status !== 'all')<a class="btn btn-falcon-default" href="{{ route('admin.sales.customer-terms.index') }}" title="{{ __('common.actions.clear') }}"><span class="fas fa-times"></span></a>@endif</div>
            </form>
        </div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="bg-100 text-700"><tr><th class="ps-3">{{ __('customer_terms.customer') }}</th><th>{{ __('customer_terms.document_number') }}</th><th style="min-width:13rem">{{ __('customer_terms.setup_progress') }}</th><th>{{ __('common.fields.status') }}</th><th class="text-end pe-3">{{ __('common.fields.actions') }}</th></tr></thead>
            <tbody>
                @forelse($customers as $customer)
                    @php
                        $configured = collect(\Modules\Sales\Services\CustomerTermsService::Fields)->filter(fn ($field) => filled($customer->{$field}))->count();
                        $setupStatus = $configured === 6 ? 'complete' : ($configured > 0 ? 'partial' : 'empty');
                        $statusColor = ['complete' => 'success', 'partial' => 'warning', 'empty' => 'secondary'][$setupStatus];
                    @endphp
                    <tr>
                        <td class="ps-3"><div class="d-flex align-items-center gap-2"><span class="customer-terms-avatar">{{ mb_substr($customer->name, 0, 1) }}</span><span class="fw-semibold text-900">{{ $customer->name }}</span></div></td>
                        <td class="white-space-nowrap" dir="ltr">{{ $customer->doc_num }}</td>
                        <td><div class="d-flex align-items-center justify-content-between gap-2 mb-1"><span class="small text-600">{{ __('customer_terms.configured_of_total', ['configured' => $configured, 'total' => 6]) }}</span><span class="small fw-semibold">{{ round(($configured / 6) * 100) }}%</span></div><div class="progress customer-terms-progress" role="progressbar" aria-valuenow="{{ $configured }}" aria-valuemin="0" aria-valuemax="6"><div class="progress-bar bg-{{ $statusColor }}" style="width:{{ ($configured / 6) * 100 }}%"></div></div></td>
                        <td><span class="badge badge-subtle-{{ $statusColor }}"><span class="fas fa-circle me-1" style="font-size:.45rem"></span>{{ __('customer_terms.status.'.$setupStatus) }}</span></td>
                        <td class="text-end pe-3">@can('customer_terms.edit')<a class="btn btn-falcon-primary btn-sm white-space-nowrap" href="{{ route('admin.sales.customer-terms.edit', $customer) }}"><span class="fas fa-sliders-h me-1"></span>{{ __('customer_terms.edit_action') }}</a>@endcan</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-5"><div class="text-400 fs-4 mb-2"><span class="fas fa-search"></span></div><h6>{{ __('customer_terms.no_customers') }}</h6><p class="small text-600 mb-0">{{ __('customer_terms.no_customers_help') }}</p></td></tr>
                @endforelse
            </tbody>
        </table></div></div>
        @if($customers->total() > 0)
            <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2 bg-white"><span class="small text-600">{{ __('customer_terms.showing', ['from' => $customers->firstItem(), 'to' => $customers->lastItem(), 'total' => $customers->total()]) }}</span>@if($customers->hasPages()){{ $customers->onEachSide(1)->links('modules.sales.customer-terms.pagination') }}@endif</div>
        @endif
    </div>
@endsection
