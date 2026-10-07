@extends('layouts.app')
@section('content')
@php
    $batchRequirements = $record->runs->flatMap(fn ($run) => $run->requirements);
    $hasUnissuedMaterials = true;
    $batchMaterialsCanBeIssued = true;
@endphp
<a class="btn btn-falcon-default btn-sm mb-3" href="{{ route('admin.production.runs.batches.show', $record) }}">{{ __('Back') }}</a>
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


@endsection
