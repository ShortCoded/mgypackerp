@extends('layouts.app')

@section('title', __('inventory.movements.production_material_issue_action'))

@section('content')
    @php
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $issuableLines = $selectedRequest?->lines?->filter(fn ($line) => bccomp((string) $line->reserved_quantity, (string) $line->issued_quantity, 8) > 0) ?? collect();
    @endphp
    <div class="card mb-3">
        <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <h5 class="mb-0">{{ __('inventory.movements.production_material_issue_action') }}</h5>
                <div class="small text-700 mt-1">{{ __('inventory.movements.production_material_issue_help') }}</div>
            </div>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.documents.index') }}">{{ __('Back') }}</a>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form method="GET" action="{{ route('admin.inventory.documents.production-material-issue.create') }}">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-9">
                        <x-forms.label for="production-material-request" :label="__('inventory.movements.production_material_request')" required />
                        <x-forms.select id="production-material-request" name="material_request" variant="ajax" :url="route('admin.inventory.documents.select2.production-material-requests')" :placeholder="__('common.placeholders.select')" required>
                            @if($selectedRequest)
                                <option value="{{ $selectedRequest->doc_num }}" selected>{{ $selectedRequest->doc_num }} — {{ $selectedRequest->run?->run_number }} — {{ $selectedRequest->store?->name }}</option>
                            @endif
                        </x-forms.select>
                    </div>
                    <div class="col-lg-3"><button class="btn btn-falcon-primary w-100" type="submit">{{ __('inventory.movements.review_material_request') }}</button></div>
                </div>
            </form>
        </div>
    </div>

    @if($selectedRequest)
        <form method="POST" action="{{ route('admin.production.material-requests.issue', $selectedRequest) }}">
            @csrf
            <x-forms.input type="hidden" name="_submission_token" :value="old('_submission_token', (string) \Illuminate\Support\Str::uuid())" />
            <x-forms.input type="hidden" name="return_to" value="inventory_document" />
            <div class="card mb-3">
                <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h5 class="mb-0">{{ $selectedRequest->doc_num }}</h5>
                    <span class="badge rounded-pill badge-subtle-secondary">{{ __('production_execution.statuses.'.$selectedRequest->status) }}</span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6"><strong>{{ __('production_execution.fields.run') }}:</strong> {{ $selectedRequest->run?->run_number }}</div>
                        <div class="col-md-6"><strong>{{ __('production_execution.fields.store') }}:</strong> {{ $selectedRequest->store?->name }}</div>
                    </div>
                    <div class="row g-3">
                        @foreach($issuableLines->values() as $index => $line)
                            @php($remainingReserved = bcsub((string) $line->reserved_quantity, (string) $line->issued_quantity, 8))
                            <div class="col-12 col-lg-6">
                                <div class="border rounded-2 p-3 h-100">
                                    <x-forms.input type="hidden" name="lines[{{ $index }}][request_line_id]" :value="$line->id" />
                                    <div class="fw-semibold mb-2">{{ $line->product?->doc_num }} — {{ $line->product?->name }}</div>
                                    <div class="small text-700 mb-2">{{ __('production_execution.material_requests.remaining_reserved') }}: <span dir="ltr">{{ $numbers->format($remainingReserved) }} {{ $line->unit?->name }}</span></div>
                                    <x-forms.label :for="'warehouse-material-issue-'.$line->id" :label="__('production_execution.fields.quantity')" required />
                                    <x-forms.numeric-input :id="'warehouse-material-issue-'.$line->id" name="lines[{{ $index }}][quantity]" :value="old('lines.'.$index.'.quantity', $remainingReserved)" :scale="8" min="0" :max="$remainingReserved" step="0.00000001" arrow-step="1" required />
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                    @can('production.material_requests.view')
                        <a class="btn btn-falcon-default" href="{{ route('admin.production.material-requests.show', $selectedRequest) }}">{{ __('production_execution.material_requests.view_document', ['document' => $selectedRequest->doc_num]) }}</a>
                    @endcan
                    <button class="btn btn-primary" type="submit"><span class="fas fa-dolly me-1"></span>{{ __('production_execution.actions.issue') }}</button>
                </div>
            </div>
        </form>
    @else
        <div class="alert alert-info">{{ __('inventory.movements.no_material_request_selected') }}</div>
    @endif
@endsection
