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
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('production_execution.fields.order_line') }}</th>
                        <th>{{ __('production_execution.fields.stage') }}</th>
                        <th class="text-end">{{ __('production_execution.fields.planned_quantity') }}</th>
                        <th>{{ __('production_execution.fields.status') }}</th>
                        <th>{{ __('production_execution.runs.batch_materials') }}</th>
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

    @if($hasUnissuedMaterials && $batchMaterialsCanBeIssued && auth()->user()?->can('production.runs.issue'))
        <form class="card mb-3" method="POST" action="{{ route('admin.production.runs.batches.issue', $record) }}" novalidate>
            @csrf
            <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
            <div class="card-header"><h6 class="mb-0">{{ __('production_execution.runs.issue_batch') }}</h6></div>
            <div class="card-body">
                <p class="text-muted mb-3">{{ __('production_execution.runs.issue_batch_help') }}</p>
                @php($selectedStore = $stores->firstWhere('id', (int) old('branch_store_id')) ?? $stores->first())
                <x-forms.input type="hidden" id="batch-receipt-store-uuid" :value="$selectedStore?->public_uuid" data-layer-store-uuid />
                <x-forms.select variant="local" name="branch_store_id" data-layer-store-selector>
                    @foreach($stores as $store)<option value="{{ $store->id }}" data-public-uuid="{{ $store->public_uuid }}" @selected($store->id === $selectedStore?->id)>{{ $store->name }}</option>@endforeach
                </x-forms.select>
                @foreach($batchRequirements->values() as $index => $requirement)
                    @if(bccomp(bcsub((string) $requirement->planned_quantity, (string) $requirement->issued_quantity, 8), '0', 8) > 0)
                        <div class="border rounded p-3 mt-3">
                            <h6>{{ $requirement->run->run_number }} — {{ $requirement->product?->doc_num }} — {{ $requirement->product?->name }}</h6>
                            <p class="small text-muted">{{ __('production_execution.fields.planned_quantity') }}: {{ $numbers->format(bcsub((string) $requirement->planned_quantity, (string) $requirement->issued_quantity, 8)) }} {{ $requirement->unit?->name }}</p>
                            <x-forms.input type="hidden" :name="'lines['.$index.'][requirement_id]'" :value="$requirement->id" />
                            @include('modules.production.material-requests.receipt-layer-selection', [
                                'selectionForBatch' => true, 'selectionCompanyId' => $record->company_id, 'selectionStoreId' => $selectedStore?->id ?? 0,
                                'line' => $requirement, 'index' => $index, 'layerStoreDependency' => '#batch-receipt-store-uuid',
                                'layerSelectorUrl' => route('admin.inventory.documents.select2.receipt-layers', ['product_doc_num' => $requirement->product?->doc_num, 'document_date' => now()->toDateString()]),
                            ])
                        </div>
                    @endif
                @endforeach
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary">{{ __('production_execution.runs.issue_batch') }}</button></div>
        </form>
    @endif

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
