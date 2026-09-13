@extends('layouts.app')

@section('title', __('maintenance.orders.details').' — '.$order->doc_num)

@section('content')
    <div class="production-mobile-workflow">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3">
            <div>
                <a class="small" href="{{ route('admin.maintenance.orders.index') }}">{{ __('maintenance.orders.title') }}</a>
                <h4 class="mb-1">{{ $order->doc_num }}</h4>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge rounded-pill badge-subtle-secondary">{{ __('maintenance.statuses.'.$order->status) }}</span>
                    <span class="badge rounded-pill badge-subtle-info">{{ __('maintenance.maintenance_types.'.$order->maintenance_type) }}</span>
                    <span class="badge rounded-pill badge-subtle-warning">{{ __('maintenance.priorities.'.$order->priority) }}</span>
                </div>
            </div>

            <div class="d-flex gap-2 mobile-action-row">
                @can('maintenance.orders.print')<a class="btn btn-falcon-default" href="{{ route('admin.maintenance.orders.print', $order) }}" target="_blank">{{ __('maintenance.actions.print_pdf') }}</a>@endcan
                @if($order->status === 'draft')@can('maintenance.orders.approve')<button class="btn btn-primary" data-action="post" data-url="{{ route('admin.maintenance.orders.approve', $order) }}">{{ __('maintenance.actions.approve') }}</button>@endcan @endif
                @if($order->status === 'approved')@can('maintenance.orders.start')<button class="btn btn-primary" data-action="post" data-url="{{ route('admin.maintenance.orders.start', $order) }}">{{ __('maintenance.actions.start') }}</button>@endcan @endif
                @if($order->status === 'in_progress')@can('maintenance.orders.complete')<a class="btn btn-success" href="{{ route('admin.maintenance.orders.complete-form', $order) }}">{{ __('maintenance.actions.complete') }}</a>@endcan @endif
                @if($order->status === 'completed')@can('maintenance.orders.close')<button class="btn btn-success" data-action="post" data-url="{{ route('admin.maintenance.orders.close', $order) }}">{{ __('maintenance.actions.close') }}</button>@endcan @endif
            </div>
        </div>

        <div class="quality-summary-grid mb-3">
            <div class="quality-summary-item"><span>{{ __('maintenance.fields.asset') }}</span><strong>{{ $order->asset?->doc_num }} — {{ $order->asset?->asset_name }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('maintenance.fields.mold') }}</span><strong>{{ $order->mold ? $order->mold->code.' — '.$order->mold->name : '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('maintenance.fields.service_mode') }}</span><strong>{{ __('maintenance.service_modes.'.$order->service_mode) }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('maintenance.fields.provider') }}</span><strong>{{ $order->supplier?->name ?: $order->external_provider_name ?: __('maintenance.internal') }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('maintenance.fields.planned_start') }}</span><strong>{{ $order->planned_start_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('maintenance.fields.actual_start') }}</span><strong>{{ $order->actual_start_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('maintenance.fields.next_due_date') }}</span><strong>{{ $order->next_due_date?->toDateString() ?? '—' }}</strong></div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('maintenance.fields.work_description') }}</h5></div><div class="card-body"><p class="mb-0 text-break">{{ $order->work_description }}</p>@if($order->request)<hr><div class="small text-600 mb-1">{{ __('maintenance.fields.source_report') }} — {{ $order->request->doc_num }}</div><p class="mb-0 text-break">{{ $order->request->symptoms }}</p>@endif</div></div></div>
            <div class="col-12 col-lg-6"><div class="card h-100"><div class="card-header"><h5 class="mb-0">{{ __('maintenance.orders.complete') }}</h5></div><div class="card-body"><dl class="row mb-0 quality-detail-list"><dt class="col-sm-4">{{ __('maintenance.fields.diagnosis') }}</dt><dd class="col-sm-8">{{ $order->diagnosis ?: '—' }}</dd><dt class="col-sm-4">{{ __('maintenance.fields.root_cause') }}</dt><dd class="col-sm-8">{{ $order->root_cause ?: '—' }}</dd><dt class="col-sm-4">{{ __('maintenance.fields.work_performed') }}</dt><dd class="col-sm-8">{{ $order->work_performed ?: '—' }}</dd><dt class="col-sm-4">{{ __('maintenance.fields.completion_notes') }}</dt><dd class="col-sm-8">{{ $order->completion_notes ?: '—' }}</dd><dt class="col-sm-4">{{ __('maintenance.fields.actual_end') }}</dt><dd class="col-sm-8">{{ $order->actual_end_at?->format('Y-m-d H:i') ?? '—' }}</dd></dl></div></div></div>
        </div>

        <div class="row g-3 mt-0">
            <div class="col-12"><div class="card"><div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2"><h5 class="mb-0">{{ __('maintenance.material_requests.title') }}</h5>@can('maintenance.material_requests.create')<a class="btn btn-sm btn-primary" href="{{ route('admin.maintenance.material-requests.index', ['order' => $order->id]) }}">{{ __('maintenance.material_requests.create') }}</a>@endcan</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.store') }}</th><th>{{ __('maintenance.fields.items') }}</th><th>{{ __('maintenance.fields.issue_document') }}</th><th>{{ __('maintenance.fields.return_document') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead><tbody>@forelse($order->materialRequests as $materialRequest)<tr><td>{{ $materialRequest->doc_num }}</td><td>{{ $materialRequest->store?->name }}</td><td>@foreach($materialRequest->lines as $line)<div>{{ $line->product?->name }} — {{ $line->issued_quantity }}/{{ $line->requested_quantity }} {{ __('maintenance.item_types.'.$line->item_type) }}</div>@endforeach</td><td>{{ $materialRequest->issueDocument?->doc_num ?? '—' }}</td><td>{{ $materialRequest->returnDocument?->doc_num ?? '—' }}</td><td>{{ __('maintenance.statuses.'.$materialRequest->status) }}</td></tr>@empty<tr><td colspan="6" class="text-center text-600">{{ __('maintenance.material_requests.none') }}</td></tr>@endforelse</tbody></table></div></div></div>
            <div class="col-12"><div class="card"><div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2"><h5 class="mb-0">{{ __('maintenance.expenses.title') }}</h5>@can('maintenance.expenses.create')<a class="btn btn-sm btn-primary" href="{{ route('admin.maintenance.expenses.index', ['order' => $order->id]) }}">{{ __('maintenance.expenses.create') }}</a>@endcan</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.amount') }}</th><th>{{ __('maintenance.fields.reason') }}</th><th>{{ __('maintenance.fields.payment_voucher') }}</th><th>{{ __('maintenance.fields.status') }}</th></tr></thead><tbody>@forelse($order->expenses as $expense)<tr><td>{{ $expense->doc_num }}</td><td>{{ $expense->amount }} {{ $expense->currency?->code }}</td><td>{{ $expense->reason }}</td><td>{{ $expense->cashVoucher?->doc_num ?? '—' }}</td><td>{{ __('production_execution.statuses.'.$expense->status) }}</td></tr>@empty<tr><td colspan="5" class="text-center text-600">{{ __('maintenance.expenses.none') }}</td></tr>@endforelse</tbody></table></div></div></div>
        </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
