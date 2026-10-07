@extends('layouts.app')

@section('title', __('production_execution.runs.batch_document', ['number' => $record->batch_number]))

@section('content')
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-1">{{ __('production_execution.runs.batch_document', ['number' => $record->batch_number]) }}</h5>
                <a href="{{ route('admin.production.work-orders.show', $record->order) }}">{{ $record->order->doc_num }}</a>
            </div>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.runs.index') }}">{{ __('common.actions.back') }}</a>
        </div>
        <div class="card-body p-0">
            <p class="alert alert-info m-3">{{ __('cancellation_review.batch_help') }}</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('production_execution.fields.order_line') }}</th>
                        <th>{{ __('production_execution.fields.stage') }}</th>
                        <th class="text-end">{{ __('production_execution.fields.planned_quantity') }}</th>
                        <th>{{ __('production_execution.fields.status') }}</th>
                        <th>{{ __('production_execution.runs.batch_materials') }}</th>
                        <th>{{ __('common.fields.actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($record->runs as $run)
                            <tr>
                                <td><a href="{{ route('admin.production.runs.show', $run) }}">{{ $run->order->doc_num }} / {{ __('production_execution.orders.line') }} {{ $run->orderLine->line_number }}</a><div class="small text-muted">{{ $run->orderLine->product?->doc_num }} — {{ $run->orderLine->product?->name }}</div></td>
                                <td>{{ $run->stageSnapshot ? $run->stageSnapshot->sequence.'. '.$run->stageSnapshot->stage_name : '—' }}</td>
                                <td class="text-end" dir="ltr">{{ $numbers->format($run->planned_quantity) }} {{ $run->unit?->name }}</td>
                                <td>{{ __('production_execution.statuses.'.$run->status) }}</td>
                                <td>
                                    @foreach($run->requirements as $requirement)
                                        <div>{{ $requirement->product?->name }} — {{ $numbers->format($requirement->planned_quantity) }} {{ $requirement->unit?->name }}</div>
                                    @endforeach
                                </td>
                                <td><x-document-owner-actions :record="$run" :menu="false" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @php
        $batchRequirements = $record->runs->flatMap(fn ($run) => $run->requirements);
        $hasUnissuedMaterials = $batchRequirements->contains(fn ($requirement) => bccomp(
            bcsub((string) $requirement->planned_quantity, (string) $requirement->issued_quantity, 8), '0', 8,
        ) > 0);
        $batchMaterialsCanBeIssued = $record->runs->every(fn ($run) => in_array($run->status, ['planned', 'setup', 'ready'], true));
    @endphp

    <div class="card mb-3"><div class="card-body d-flex gap-2">
    @if($record->runs->isNotEmpty())
    @can('production.runs.progress')<a class="btn btn-primary" href="{{ route('admin.production.runs.daily-reports.create', $record->runs->first()) }}">{{ __('production_daily_report.title') }}</a>@endcan
    @can('production.handovers.create')<a class="btn btn-outline-primary" href="{{ route('admin.production.handovers.create', $record->runs->first()) }}">{{ __('production_handover.create') }}</a>@endcan
    @endif
    @if($hasUnissuedMaterials && $batchMaterialsCanBeIssued)@can('production.runs.issue')<a class="btn btn-outline-primary" href="{{ route('admin.production.runs.batches.issue-create', $record) }}">{{ __('production_execution.runs.issue_batch') }}</a>@endcan @endif
    </div></div>

    <div class="card">
        <div class="card-header"><h6 class="mb-0">{{ __('production_execution.runs.batch_materials') }}</h6></div>
        <div class="card-body">
            @forelse($materialDocuments as $document)
                <a class="d-inline-flex me-2" href="{{ route('admin.inventory.documents.show', $document) }}">{{ $document->doc_num }}</a>
            @empty
                <span class="text-muted">{{ __('production_execution.runs.no_batch_material_documents') }}</span>
            @endforelse
        </div>
    </div>
@endsection
