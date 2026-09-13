@extends('layouts.app')

@section('title', $record->doc_num)

@section('content')
    <div class="production-mobile-workflow">
    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><div><h5 class="mb-0">{{ $record->doc_num }}</h5><span class="badge badge-subtle-secondary">{{ __('production_execution.statuses.'.$record->status) }}</span></div><div class="d-flex gap-2"><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.work-orders.print', $record) }}">{{ __('common.actions.print') }}</a>@if(in_array($record->status, ['draft','planned'], true))<form method="POST" action="{{ route('admin.production.work-orders.release', $record) }}">@csrf<button class="btn btn-primary btn-sm">{{ __('production_execution.actions.release') }}</button></form>@endif</div></div><div class="card-body"><div class="row g-3"><div class="col-md-3"><strong>{{ __('production_execution.fields.sales_order') }}:</strong> {{ $record->salesOrder?->doc_num }}</div><div class="col-md-3"><strong>{{ __('production_execution.fields.branch') }}:</strong> {{ $record->salesOrder?->branch?->name }}</div><div class="col-md-3"><strong>{{ __('production_execution.fields.order_date') }}:</strong> {{ $record->production_order_date?->toDateString() }}</div><div class="col-md-3"><strong>{{ __('production_execution.fields.delivery_date') }}:</strong> {{ $record->expected_delivery_date?->toDateString() }}</div></div></div></div>
    <x-related-documents :documents="$relatedDocuments" />
    @foreach($record->lines as $line)
        <div class="card mb-3"><div class="card-header"><strong>{{ $line->product?->doc_num }} — {{ $line->description }}</strong><span class="ms-2">{{ $line->quantity }} {{ $line->unit?->name }}</span></div><div class="card-body">@if(filled($line->specifications))<p class="mb-3"><strong>{{ __('production_execution.fields.specifications') }}:</strong> {{ collect($line->specifications)->map(fn ($value, $key) => __(str($key)->replace('_', ' ')->title()->toString()).': '.$value)->join(' · ') }}</p>@endif<h6>{{ __('production_execution.fields.production_route') }}</h6><div class="d-flex flex-wrap gap-2">@forelse($line->stageSnapshots as $stage)<span class="badge badge-subtle-{{ $stage->status === 'completed' ? 'success' : 'secondary' }}">{{ $stage->sequence }}. {{ $stage->stage_name }}</span>@empty<span class="text-600">{{ __('production_execution.product_stages.no_route') }}</span>@endforelse</div></div></div>
    @endforeach
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
