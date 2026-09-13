@extends('layouts.app')

@section('title', $record->run_number)

@section('content')
    <div class="production-mobile-workflow">
    @php
        $laborRows = collect($record->labor_details ?? []);
        $relatedDocuments = collect([
            ['label' => __('Production Order'), 'number' => $record->order?->doc_num, 'url' => $record->order ? route('admin.production.work-orders.show', $record->order) : null, 'permission' => 'production.orders.view'],
            ['label' => __('Sales Requirement / Order'), 'number' => $record->order?->salesOrder?->doc_num, 'url' => $record->order?->salesOrder ? route('admin.sales.sales-orders.show', $record->order->salesOrder) : null, 'permission' => 'sales_orders.view'],
            ...$record->inventoryDocuments->map(fn ($document) => ['label' => __(str($document->document_type)->replace('_', ' ')->title()->toString()), 'number' => $document->doc_num, 'url' => route('admin.inventory.documents.show', $document), 'permission' => 'inventory.documents.view', 'meta' => __(str($document->status)->replace('_', ' ')->title()->toString())])->all(),
            ...$record->inspections->map(fn ($inspection) => ['label' => __('QC Sample'), 'number' => $inspection->doc_num, 'url' => route('admin.production.quality.show', $inspection->getKey()), 'permission' => 'production.quality.view', 'meta' => __('production_execution.quality_results.'.$inspection->result)])->all(),
            ...$record->materialRequests->map(fn ($materialRequest) => ['label' => __('production_execution.material_requests.title'), 'number' => $materialRequest->doc_num, 'url' => route('admin.production.material-requests.index'), 'permission' => 'production.material_requests.view', 'meta' => __('production_execution.statuses.'.$materialRequest->status)])->all(),
            ...$record->expenseRequests->map(fn ($expenseRequest) => ['label' => __('production_execution.expenses.title'), 'number' => $expenseRequest->doc_num, 'url' => route('admin.production.expenses.index'), 'permission' => 'production.expenses.view', 'meta' => __('production_execution.statuses.'.$expenseRequest->status)])->all(),
        ]);
    @endphp
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">
            <div><h5 class="mb-1">{{ $record->run_number }}</h5><span class="badge bg-secondary">{{ __(str($record->status)->replace('_', ' ')->title()->toString()) }}</span></div>
            @can('production.runs.print')<div class="btn-group"><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.runs.print', $record) }}">{{ __('Print traveler') }}</a><button class="btn btn-falcon-default btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button><div class="dropdown-menu"><a class="dropdown-item" href="{{ route('admin.production.runs.materials.print', $record) }}">{{ __('Material Requirement') }}</a><a class="dropdown-item" href="{{ route('admin.production.runs.quality.print', $record) }}">{{ __('In-Process QC') }}</a><a class="dropdown-item" href="{{ route('admin.production.runs.completion.print', $record) }}">{{ __('Completion Summary') }}</a></div></div>@endcan
        </div>
        <div class="card-body"><div class="row g-2">
            <div class="col-md-3"><strong>{{ __('Order') }}:</strong> {{ $record->order?->doc_num }}</div>
            <div class="col-md-3"><strong>{{ __('Product') }}:</strong> {{ $record->product?->name }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.stage') }}:</strong> {{ $record->stageSnapshot?->sequence }} — {{ $record->stageSnapshot?->stage_name ?? '—' }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.fixed_asset') }}:</strong> {{ $record->fixedAsset?->asset_name ?? '—' }}</div>
            <div class="col-md-3"><strong>{{ __('Planned') }}:</strong> {{ $record->planned_base_quantity }}</div>
            <div class="col-md-3"><strong>{{ __('Good / Received') }}:</strong> {{ $record->good_base_quantity }} / {{ $record->received_base_quantity }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.planned_labor_count') }}:</strong> {{ $record->planned_labor_count ?? '—' }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.actual_labor_count') }}:</strong> {{ $record->actual_labor_count ?? '—' }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.planned_start_at') }}:</strong> {{ $record->planned_start_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.planned_end_at') }}:</strong> {{ $record->planned_end_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.actual_start_at') }}:</strong> {{ $record->actual_start_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.actual_end_at') }}:</strong> {{ $record->actual_end_at?->format('Y-m-d H:i') ?? '—' }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.actual_duration') }}:</strong> {{ $record->actualDurationHours() !== null ? __('production_execution.labor.hours_value', ['hours' => $record->actualDurationHours()]) : '—' }}</div>
            <div class="col-md-3"><strong>{{ __('production_execution.fields.total_labor_hours') }}:</strong> {{ $record->totalLaborHours() }}</div>
            <div class="col-12"><strong>{{ __('production_execution.fields.work_description') }}:</strong> {{ $record->work_description ?: '—' }}</div>
        </div></div>
    </div>

    <x-related-documents :documents="$relatedDocuments" />

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('Material Reconciliation') }}</h5></div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>{{ __('Material') }}</th><th>{{ __('Planned') }}</th><th>{{ __('Reserved') }}</th><th>{{ __('Issued') }}</th><th>{{ __('Additional') }}</th><th>{{ __('Returned') }}</th><th>{{ __('Consumed') }}</th><th>{{ __('Waste') }}</th></tr></thead>
            <tbody>@foreach ($record->requirements as $line)<tr>
                <td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td>
                <td>{{ $line->planned_quantity }}</td><td>{{ $line->reserved_quantity }}</td><td>{{ $line->issued_quantity }}</td>
                <td>{{ $line->additional_issued_quantity }}</td><td>{{ $line->returned_quantity }}</td><td>{{ $line->consumed_quantity }}</td><td>{{ $line->waste_quantity }}</td>
            </tr>@endforeach</tbody>
        </table></div>
    </div>

    @php($hasControlledAction = ($record->status === 'planned' && auth()->user()?->can('production.runs.setup')) || ($record->status === 'setup' && auth()->user()?->can('production.runs.setup')) || ($record->status === 'ready' && auth()->user()?->can('production.runs.setup')) || ($record->status === 'held' && auth()->user()?->can('production.runs.qc')) || (in_array($record->status, ['running', 'held'], true) && auth()->user()?->can('production.runs.complete')) || (!in_array($record->status, ['completed', 'cancelled'], true) && auth()->user()?->can('production.runs.cancel')))
    @if($hasControlledAction)
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('Controlled Actions') }}</h5></div>
        <div class="card-body d-flex flex-wrap gap-2 mobile-action-row">
            @if($record->status === 'planned')@can('production.runs.setup')<form method="POST" action="{{ route('admin.production.runs.setup.start', $record) }}">@csrf<button class="btn btn-falcon-primary btn-sm">{{ __('Start setup') }}</button></form>@endcan @endif
            @if($record->status === 'setup')@can('production.runs.setup')<form method="POST" action="{{ route('admin.production.runs.setup.complete', $record) }}">@csrf<button class="btn btn-falcon-primary btn-sm">{{ __('Complete setup') }}</button></form>@endcan @endif
            @if($record->status === 'ready')@can('production.runs.setup')<form method="POST" action="{{ route('admin.production.runs.start', $record) }}">@csrf<button class="btn btn-primary btn-sm">{{ __('Start run') }}</button></form>@endcan @endif
            @if($record->status === 'held')@can('production.runs.qc')<form method="POST" action="{{ route('admin.production.runs.resume', $record) }}">@csrf<button class="btn btn-warning btn-sm">{{ __('Resume after QC pass') }}</button></form>@endcan @endif
            @if(in_array($record->status, ['running', 'held'], true))@can('production.runs.complete')<form method="POST" action="{{ route('admin.production.runs.complete', $record) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Complete run') }}</button></form>@endcan @endif
            @if(!in_array($record->status, ['completed', 'cancelled'], true))@can('production.runs.cancel')<form class="d-flex gap-2" method="POST" action="{{ route('admin.production.runs.cancel', $record) }}">@csrf<x-forms.input class="form-control form-control-sm" name="reason" placeholder="{{ __('Cancellation reason') }}" required /><button class="btn btn-outline-danger btn-sm">{{ __('Cancel') }}</button></form>@endcan @endif
        </div>
    </div>
    @endif

    @if($laborRows->isNotEmpty() || (in_array($record->status, ['running', 'held'], true) && auth()->user()?->can('production.runs.labor')))
    <div class="card mb-3" data-production-labor-planning>
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0">{{ __('production_execution.labor.actual_details') }}</h5>
            @if(in_array($record->status, ['running', 'held'], true))
                @can('production.runs.labor')<button class="btn btn-falcon-primary btn-sm" type="button" data-add-labor-row><span class="fas fa-plus me-1"></span>{{ __('production_execution.actions.add_worker') }}</button>@endcan
            @endif
        </div>
        @if(in_array($record->status, ['running', 'held'], true) && auth()->user()?->can('production.runs.labor'))
            <form method="POST" action="{{ route('admin.production.runs.labor', $record) }}">@csrf
                <div class="card-body">
                    <div class="row g-3 mb-3"><div class="col-12 col-md-4"><label class="form-label">{{ __('production_execution.fields.actual_labor_count') }}</label><x-forms.input class="form-control" name="actual_labor_count" type="number" min="1" :value="$record->actual_labor_count ?? max($record->planned_labor_count ?? 0, $laborRows->count(), 1)" required /></div></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>{{ __('production_execution.fields.worker_name') }}</th><th>{{ __('production_execution.fields.worker_role') }}</th><th>{{ __('production_execution.fields.planned_hours') }}</th><th>{{ __('production_execution.fields.actual_hours') }}</th><th>{{ __('production_execution.fields.notes') }}</th><th></th></tr></thead>
                            <tbody data-labor-rows>
                                @forelse($laborRows as $index => $labor)
                                    <tr data-labor-row>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[{{ $index }}][name]" :value="$labor['name'] ?? ''" required /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[{{ $index }}][role]" :value="$labor['role'] ?? ''" /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[{{ $index }}][planned_hours]" type="number" min="0" step="0.25" inputmode="decimal" :value="$labor['planned_hours'] ?? ''" /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[{{ $index }}][actual_hours]" type="number" min="0.01" step="0.25" inputmode="decimal" :value="$labor['actual_hours'] ?? ''" required /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[{{ $index }}][notes]" :value="$labor['notes'] ?? ''" /></td>
                                        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-row aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button></td>
                                    </tr>
                                @empty
                                    <tr data-labor-row>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][name]" required /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][role]" /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][planned_hours]" type="number" min="0" step="0.25" inputmode="decimal" /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][actual_hours]" type="number" min="0.01" step="0.25" inputmode="decimal" required /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][notes]" /></td>
                                        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-row aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button></td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <template data-labor-row-template>
                        <tr data-labor-row>
                            <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][name]" type="text" required></td>
                            <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][role]" type="text"></td>
                            <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][planned_hours]" type="number" min="0" step="0.25" inputmode="decimal"></td>
                            <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][actual_hours]" type="number" min="0.01" step="0.25" inputmode="decimal" required></td>
                            <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][notes]" type="text"></td>
                            <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-row aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button></td>
                        </tr>
                    </template>
                </div>
                <div class="card-footer text-end"><button class="btn btn-primary">{{ __('production_execution.actions.save_labor') }}</button></div>
            </form>
        @else
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>{{ __('production_execution.fields.worker_name') }}</th><th>{{ __('production_execution.fields.worker_role') }}</th><th>{{ __('production_execution.fields.planned_hours') }}</th><th>{{ __('production_execution.fields.actual_hours') }}</th><th>{{ __('production_execution.fields.notes') }}</th></tr></thead><tbody>@foreach($laborRows as $labor)<tr><td>{{ $labor['name'] ?? '—' }}</td><td>{{ $labor['role'] ?? '—' }}</td><td>{{ $labor['planned_hours'] ?? '—' }}</td><td>{{ $labor['actual_hours'] ?? '—' }}</td><td>{{ $labor['notes'] ?? '—' }}</td></tr>@endforeach</tbody></table></div>
        @endif
    </div>
    @endif

    <div class="row g-3">
        @can('production.material_requests.create')
        <div class="col-lg-4"><div class="card h-100"><div class="card-header"><h6 class="mb-0">{{ __('production_execution.material_requests.title') }}</h6></div><div class="card-body">{{ __('production_execution.material_requests.bom_help') }}</div><div class="card-footer d-flex gap-2"><a class="btn btn-primary btn-sm" href="{{ route('admin.production.material-requests.index', ['run' => $record->id]) }}">{{ __('production_execution.actions.request_bom') }}</a><a class="btn btn-outline-primary btn-sm" href="{{ route('admin.production.material-requests.index', ['run' => $record->id, 'additional' => 1]) }}">{{ __('Additional Material Issue Request') }}</a></div></div></div>
        @endcan

        @if(!in_array($record->status, ['completed', 'cancelled'], true))
        @can('production.runs.issue')
        @foreach ([['route' => 'admin.production.runs.return', 'title' => __('Unused Material Return'), 'button' => __('Return unused'), 'additional' => false]] as $materialAction)
            <div class="col-lg-4"><form class="card h-100" method="POST" action="{{ route($materialAction['route'], $record) }}">
                @csrf
                @if ($materialAction['additional'])<x-forms.input type="hidden" name="additional" value="1" />@endif
                <div class="card-header"><h6 class="mb-0">{{ $materialAction['title'] }}</h6></div>
                <div class="card-body">
                    <x-forms.select class="form-select mb-2" name="branch_store_id" required>@foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</x-forms.select>
                    @foreach ($record->requirements as $index => $line)
                        <div class="input-group input-group-sm mb-2"><span class="input-group-text flex-grow-1">{{ $line->product?->name }}</span><x-forms.input type="hidden" name="lines[{{ $index }}][requirement_id]" value="{{ $line->id }}" /><x-forms.input class="form-control" type="number" step="0.00000001" min="0.00000001" name="lines[{{ $index }}][quantity]" placeholder="{{ __('Qty') }}" /></div>
                    @endforeach
                </div>
                <div class="card-footer text-end"><button class="btn btn-primary btn-sm">{{ $materialAction['button'] }}</button></div>
            </form></div>
        @endforeach
        @endcan
        @endif

        @if($record->status === 'running')
        @can('production.runs.progress')
        <div class="col-lg-4"><form class="card h-100" method="POST" action="{{ route('admin.production.runs.progress', $record) }}">
            @csrf
            <div class="card-header"><h6 class="mb-0">{{ __('Production Progress') }}</h6></div>
            <div class="card-body row g-2">
                <div class="col-6"><x-forms.input class="form-control" type="number" step="0.00000001" min="0" name="good_base_quantity" placeholder="{{ __('Good') }}" /></div>
                <div class="col-6"><x-forms.input class="form-control" type="number" step="0.00000001" min="0" name="rejected_base_quantity" placeholder="{{ __('Rejected') }}" /></div>
                <div class="col-6"><x-forms.input class="form-control" type="number" step="0.00000001" min="0" name="rework_base_quantity" placeholder="{{ __('Rework') }}" /></div>
                <div class="col-6"><x-forms.input class="form-control" type="number" step="0.00000001" min="0" name="scrap_base_quantity" placeholder="{{ __('Scrap') }}" /></div>
                <div class="col-12"><x-forms.input class="form-control" name="notes" placeholder="{{ __('production_execution.fields.notes') }}" /></div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary btn-sm">{{ __('Record progress') }}</button></div>
        </form></div>
        @endcan
        @endif

        @if(in_array($record->status, ['running', 'held'], true))
        @can('production.quality.create')
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0">{{ __('production_execution.quality.title') }}</h6></div>
                <div class="card-body"><p class="mb-0 text-600">{{ __('production_execution.quality.create_from_run_help') }}</p></div>
                <div class="card-footer"><a class="btn btn-primary btn-sm w-100" href="{{ route('admin.production.quality.create', ['run' => $record->getKey()]) }}"><span class="fas fa-clipboard-check me-1"></span>{{ __('production_execution.actions.create_inspection') }}</a></div>
            </div>
        </div>
        @endcan
        @endif

        @if(in_array($record->status, ['running', 'held'], true))
        @can('production.runs.account_materials')
        <div class="col-lg-6"><form class="card h-100" method="POST" action="{{ route('admin.production.runs.account', $record) }}">
            @csrf
            <div class="card-header"><h6 class="mb-0">{{ __('Material Accountability') }}</h6></div>
            <div class="card-body">
                <x-forms.select class="form-select mb-2" name="branch_store_id" required>@foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</x-forms.select>
                @foreach ($record->requirements as $index => $line)
                    @php($unaccounted = bcsub(bcsub(bcadd($line->issued_quantity, $line->additional_issued_quantity, 8), $line->returned_quantity, 8), bcadd($line->consumed_quantity, $line->waste_quantity, 8), 8))
                    <div class="row g-2 mb-2"><x-forms.input type="hidden" name="lines[{{ $index }}][requirement_id]" value="{{ $line->id }}" /><div class="col-4">{{ $line->product?->name }}</div><div class="col-4"><x-forms.input class="form-control form-control-sm" type="number" step="0.00000001" min="0" name="lines[{{ $index }}][consumed_quantity]" value="{{ $unaccounted }}" aria-label="{{ __('Consumed') }}" /></div><div class="col-4"><x-forms.input class="form-control form-control-sm" type="number" step="0.00000001" min="0" name="lines[{{ $index }}][waste_quantity]" value="0" aria-label="{{ __('Waste') }}" /></div></div>
                @endforeach
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary btn-sm">{{ __('Post consumption / waste') }}</button></div>
        </form></div>
        @endcan
        @endif

        @if(in_array($record->status, ['running', 'held'], true))
        @can('production.runs.receive')
        <div class="col-lg-6"><form class="card h-100" method="POST" action="{{ route('admin.production.runs.receive', $record) }}">
            @csrf
            <div class="card-header"><h6 class="mb-0">{{ __('Finished Goods Receipt') }}</h6></div>
            <div class="card-body row g-2"><div class="col-7"><x-forms.select class="form-select" name="branch_store_id" required>@foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</x-forms.select></div><div class="col-5"><x-forms.input class="form-control" type="number" step="0.00000001" min="0.00000001" name="base_quantity" placeholder="{{ __('Accepted base qty') }}" required /></div></div>
            <div class="card-footer text-end"><button class="btn btn-success btn-sm">{{ __('Receive finished goods') }}</button></div>
        </form></div>
        @endcan
        @endif
    </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
