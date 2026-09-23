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

        @if($record->orderStageSnapshots->isNotEmpty())
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0">{{ __('production_execution.orders.order_route') }}</h6></div>
                <div class="card-body">
                    <div class="row g-3">
                        @foreach($record->orderStageSnapshots as $stage)
                            <div class="col-md-6 col-xl-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex justify-content-between gap-2">
                                        <strong>{{ $stage->sequence }}. {{ $stage->stage_name }}</strong>
                                        <span class="badge badge-subtle-{{ $stage->status === 'completed' ? 'success' : ($stage->status === 'in_progress' ? 'primary' : 'secondary') }}">{{ __('production_execution.statuses.'.$stage->status) }}</span>
                                    </div>
                                    <ul class="list-unstyled small text-600 mb-0 mt-2">
                                        @foreach($stage->events as $event)
                                            <li class="mb-1">
                                                {{ $dates->formatDateTime($event->occurred_at) }} —
                                                {{ __('production_execution.orders.event_types.'.$event->event_type) }}
                                                @if($event->changedBy) · {{ $event->changedBy->name }} @endif
                                                @if($event->run) · <a href="{{ route('admin.production.runs.show', $event->run) }}">{{ $event->run->run_number }}</a> @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @forelse($record->lines as $line)
            @php
                $equivalentQuantity = bcmul((string) $line->base_quantity, (string) ($line->bom_snapshot['basis_base_quantity'] ?? '1.00000000'), 8);
            @endphp
            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                    <strong>{{ $line->product?->doc_num }} — {{ $line->description ?: $line->product?->name }}</strong>
                    <span>{{ $numbers->format($line->quantity) }} {{ $line->unit?->name }}
                        @if($line->product?->equivalentUnit && filled($line->product?->equivalent_value))
                            <span class="text-600">→ {{ $numbers->format(bcmul((string) $line->base_quantity, (string) $line->product->equivalent_value, 8)) }} {{ $line->product->equivalentUnit->name }}</span>
                        @endif
                    </span>
                </div>
                <div class="card-body">
                    @if(filled($line->specifications))
                        <p class="mb-3"><strong>{{ __('production_execution.fields.specifications') }}:</strong> {{ collect($line->specifications)->map(fn ($value, $key) => __(str($key)->replace('_', ' ')->title()->toString()).': '.$value)->join(' · ') }}</p>
                    @endif
                    <h6>{{ __('production_execution.orders.line_route') }}</h6>
                    <div class="d-flex flex-wrap gap-2">
                        @forelse($line->stageSnapshots as $stage)
                            <span class="badge badge-subtle-{{ $stage->status === 'completed' ? 'success' : 'secondary' }}">{{ $stage->sequence }}. {{ $stage->stage_name }}</span>
                        @empty
                            <span class="text-600">{{ __('production_execution.product_stages.no_route') }}</span>
                        @endforelse
                    </div>
                    @foreach($line->stageSnapshots as $stage)
                        @if($stage->events->isNotEmpty())
                            <ul class="list-unstyled small text-600 mt-2 mb-0">
                                @foreach($stage->events as $event)
                                    <li>{{ $dates->formatDateTime($event->occurred_at) }} — {{ __('production_execution.orders.event_types.'.$event->event_type) }} @if($event->changedBy) · {{ $event->changedBy->name }} @endif @if($event->run) · <a href="{{ route('admin.production.runs.show', $event->run) }}">{{ $event->run->run_number }}</a> @endif</li>
                                @endforeach
                            </ul>
                        @endif
                    @endforeach
                    @if(is_array($line->bom_snapshot) && filled($line->bom_snapshot['components'] ?? null))
                        <div class="border-top mt-3 pt-3">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                <h6 class="mb-0">{{ __('production_execution.orders.material_requirements') }}</h6>
                                <span class="text-600">{{ __('production_execution.orders.bom_basis', ['quantity' => $numbers->format($equivalentQuantity), 'unit' => $line->bom_snapshot['basis_unit_name'] ?? '']) }}</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>{{ __('production_execution.fields.product') }}</th><th>{{ __('production_execution.orders.per_equivalent_unit') }}</th><th>{{ __('production_execution.orders.required_quantity') }}</th></tr></thead>
                                    <tbody>
                                        @foreach($line->bom_snapshot['components'] as $component)
                                            @php $requiredComponentQuantity = bcmul((string) ($component['base_quantity_per_output'] ?? '0'), (string) $equivalentQuantity, 8); @endphp
                                            <tr>
                                                <td>{{ $component['product_doc_num'] ?? '' }} — {{ $component['product_name'] ?? '' }}</td>
                                                <td dir="ltr">{{ $numbers->format($component['base_quantity_per_output'] ?? '0') }} {{ $component['base_unit_name'] ?? '' }}</td>
                                                <td dir="ltr" class="fw-semibold">{{ $numbers->format($requiredComponentQuantity) }} {{ $component['base_unit_name'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
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
