@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $sourceLabel = __('production_execution.source_types.'.$record->source_type);
@endphp

@section('title', $record->doc_num)

@section('content')
    <div class="production-mobile-workflow">
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="mb-1" dir="ltr">{{ $record->doc_num }}</h5>
                    <span class="badge rounded-pill badge-subtle-secondary">{{ __('production_execution.statuses.'.$record->status) }}</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @if($canManageProduction && $record->status === \Modules\Production\Models\ProductionOrder::StatusDraft && ! $record->runs()->exists())
                        @can('production.orders.edit')
                            <a class="btn btn-falcon-primary btn-sm" href="{{ route('admin.production.work-orders.edit', $record) }}">{{ __('common.actions.edit') }}</a>
                        @endcan
                    @endif
                    @can('production.orders.print')
                        <a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.production.work-orders.print', $record) }}">{{ __('common.actions.print') }}</a>
                    @endcan
                    @if($canManageProduction && in_array($record->status, [\Modules\Production\Models\ProductionOrder::StatusDraft, \Modules\Production\Models\ProductionOrder::StatusPlanned], true))
                        @can('production.orders.release')
                            <form method="POST" action="{{ route('admin.production.work-orders.release', $record) }}">
                                @csrf
                                <button class="btn btn-primary btn-sm" type="submit">{{ __('production_execution.actions.release') }}</button>
                            </form>
                        @endcan
                    @endif
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><strong>{{ __('production_execution.fields.source') }}:</strong> {{ $sourceLabel }} @if($sourceDocumentNumber)<span dir="ltr">— {{ $sourceDocumentNumber }}</span>@endif</div>
                    <div class="col-md-3"><strong>{{ __('production_execution.fields.branch') }}:</strong> {{ $record->branch?->name ?: __('common.empty_value') }}</div>
                    <div class="col-md-3"><strong>{{ __('production_execution.fields.order_date') }}:</strong> {{ $dates->formatDate($record->production_order_date, __('common.empty_value')) }}</div>
                    <div class="col-md-3"><strong>{{ __('production_execution.fields.delivery_date') }}:</strong> {{ $dates->formatDate($record->expected_delivery_date, __('common.empty_value')) }}</div>
                </div>
            </div>
        </div>

        <x-related-documents :documents="$relatedDocuments" />

        @forelse($record->lines as $line)
            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                    <strong>{{ $line->product?->doc_num }} — {{ $line->description ?: $line->product?->name }}</strong>
                    <span>{{ $numbers->format($line->quantity) }} {{ $line->unit?->name }}</span>
                </div>
                <div class="card-body">
                    @if(filled($line->specifications))
                        <p class="mb-3"><strong>{{ __('production_execution.fields.specifications') }}:</strong> {{ collect($line->specifications)->map(fn ($value, $key) => __(str($key)->replace('_', ' ')->title()->toString()).': '.$value)->join(' · ') }}</p>
                    @endif
                    <h6>{{ __('production_execution.fields.production_route') }}</h6>
                    <div class="d-flex flex-wrap gap-2">
                        @forelse($line->stageSnapshots as $stage)
                            <span class="badge badge-subtle-{{ $stage->status === 'completed' ? 'success' : 'secondary' }}">{{ $stage->sequence }}. {{ $stage->stage_name }}</span>
                        @empty
                            <span class="text-600">{{ __('production_execution.product_stages.no_route') }}</span>
                        @endforelse
                    </div>
                </div>
            </div>
        @empty
            <div class="alert alert-warning">{{ __('common.no_data') }}</div>
        @endforelse
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
