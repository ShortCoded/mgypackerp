@extends('layouts.app')
@section('title', $record->run_number)
@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $readOnlyReport = $readOnlyReport ?? false;
    $dailyShiftIds = ($shiftEntries ?? collect())->filter(fn ($entry) => ($entry->sheet_fields['entry_source'] ?? null) === 'daily_sheet')->pluck('id');
    $receiptOnlyRecovery = app(\Modules\Production\Services\ProductionReceiptCancellationService::class)->isReceiptOnlyRecovery($record);
    $runs = $batchRuns ?? collect([$record]);
    $handoverService = app(\Modules\Production\Services\ProductionHandoverService::class);
    $documents = collect([
        ['label' => __('Production Order'), 'number' => $record->order?->doc_num, 'url' => $record->order ? route('admin.production.work-orders.show', $record->order) : null, 'permission' => 'production.orders.view'],
        ...$record->materialRequests->map(fn ($document) => ['label' => __('production_execution.material_requests.title'), 'number' => $document->doc_num, 'url' => route('admin.production.material-requests.show', $document), 'permission' => 'production.material_requests.view'])->all(),
        ...$record->expenseRequests->map(fn ($document) => ['label' => __('production_execution.expenses.title'), 'number' => $document->doc_num, 'url' => route('admin.production.expenses.show', $document), 'permission' => 'production.expenses.view'])->all(),
        ...$record->inspections->map(fn ($document) => ['label' => __('QC Sample'), 'number' => $document->doc_num, 'url' => route('admin.production.quality.show', $document->id), 'permission' => 'production.quality.view'])->all(),
        ...$record->inventoryDocuments->reject(fn ($document) => $document->document_type === \Modules\Inventory\Models\InventoryDocument::TypeProductionHandover || $document->source_document_type === \Modules\Inventory\Models\InventoryDocument::class)->map(fn ($document) => ['label' => __('inventory.movements.types.'.$document->document_type), 'number' => $document->doc_num, 'url' => route('admin.inventory.documents.show', $document), 'permission' => 'inventory.documents.view'])->all(),
    ])->map(fn ($document) => $readOnlyReport ? [...$document, 'url' => null] : $document);
@endphp
<div class="production-mobile-workflow">
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<div class="card mb-3" data-production-run-summary><div class="card-header d-flex justify-content-between align-items-center">
    <div><h5 class="mb-1">{{ $record->batch?->batch_number ?? $record->run_number }}</h5><span class="badge bg-secondary">{{ __('production_execution.statuses.'.$record->status) }}</span></div>
    @unless($readOnlyReport)
    <div class="dropdown"><button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">{{ __('production_daily_report.actions') }}</button><div class="dropdown-menu dropdown-menu-end" data-production-actions>
        @if($receiptOnlyRecovery)@can('production.runs.complete')<a class="dropdown-item" href="{{ route('admin.production.runs.operation', [$record, 'complete']) }}">{{ __('production_daily_report.operations.complete') }}</a>@endcan @endif
        @if(!$receiptOnlyRecovery && !in_array($record->status, ['completed', 'cancelled'], true))
        @can('production.runs.progress')<a class="dropdown-item" href="{{ route('admin.production.runs.daily-reports.create', $record) }}">{{ __('production_daily_report.title') }}</a>@endcan
        @can('production.material_requests.create')<a class="dropdown-item" href="{{ route('admin.production.material-requests.create', ['run' => $record->id]) }}">{{ __('production_execution.material_requests.title') }}</a><a class="dropdown-item" href="{{ route('admin.production.material-requests.create', ['run' => $record->id, 'additional' => 1]) }}">{{ __('Additional Material Issue Request') }}</a>@endcan
        @can('production.quality.create')<a class="dropdown-item" href="{{ route('admin.production.quality.create', ['run' => $record->id]) }}">{{ __('QC Sample') }}</a>@endcan
        @foreach(['setup' => 'production.runs.setup', 'crew' => 'production.runs.setup', 'reserve' => 'production.runs.reserve', 'return' => 'production.runs.issue', 'account' => 'production.runs.account_materials', 'labor' => 'production.runs.labor', 'checklist' => 'production.orders.release', 'complete' => 'production.runs.complete', 'cancel' => 'production.runs.cancel'] as $operation => $permission)
        @can($permission)<a class="dropdown-item" href="{{ route('admin.production.runs.operation', [$record, $operation]) }}">{{ __('production_daily_report.operations.'.$operation) }}</a>@endcan
        @endforeach
        @endif
        @if(!$receiptOnlyRecovery && in_array($record->status, [\Modules\Production\Models\ProductionRun::StatusRunning, \Modules\Production\Models\ProductionRun::StatusHeld], true) && auth()->user()->canAny(['production.runs.correct', 'production.runs.correct_approve']))<a class="dropdown-item" href="{{ route('admin.production.runs.material-substitutions.index', $record) }}">{{ __('production_material_substitution.title') }}</a>@endif
        @if($record->status === 'running')@can('production.handovers.create')<a class="dropdown-item" href="{{ route('admin.production.handovers.create', $record) }}">{{ __('production_handover.create') }}</a>@endcan @endif
        @can('production.handovers.view')<a class="dropdown-item" href="{{ route('admin.production.handovers.index') }}">{{ __('production_handover.title') }}</a>@endcan
        <x-document-owner-actions :record="$record" />
        @can('production.runs.view')@if($record->production_order_stage_snapshot_id !== null)<a class="dropdown-item" href="{{ route('admin.production.runs.stage-transfers.index', $record) }}">{{ __('production_stage_transfer.title') }}</a><a class="dropdown-item" href="{{ route('admin.production.runs.stage-output-costs.index', $record) }}">{{ __('production_stage_transfer.output_cost_title') }}</a>@endif @endcan
        @can('inventory.production_receipts.view')<a class="dropdown-item" href="{{ route('admin.inventory.production-receipts.index') }}">{{ __('production_handover.warehouse_title') }}</a>@endcan
        @if($record->status === 'completed' && auth()->user()->canAny(['production.runs.correct', 'production.runs.correct_approve']))<a class="dropdown-item" href="{{ route('admin.production.runs.corrections.index', $record) }}">{{ __('production_run_correction.title') }}</a>@endif
        @can('production.runs.print')<a class="dropdown-item" href="{{ route('admin.production.runs.print', $record) }}">{{ __('Print traveler') }}</a><a class="dropdown-item" href="{{ route('admin.production.runs.materials.print', $record) }}">{{ __('Material Requirement') }}</a><a class="dropdown-item" href="{{ route('admin.production.runs.quality.print', $record) }}">{{ __('In-Process QC') }}</a><a class="dropdown-item" href="{{ route('admin.production.runs.completion.print', $record) }}">{{ __('Completion Summary') }}</a><a class="dropdown-item" href="{{ route('admin.production.runs.shifts.print', $record) }}">{{ __('production_daily_report.history') }}</a>@endcan
    </div></div>
    @else
    <x-document-owner-actions :record="$record" :menu="false" />
    @can('production.reports.control.print')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.reports.control.runs.print', $record) }}">{{ __('Print traveler') }}</a>@endcan
    @endunless
</div><div class="card-body row g-2">
    <div class="col-md-4"><strong>{{ __('Order') }}:</strong> {{ $record->order?->doc_num }}</div>
    <div class="col-md-4"><strong>{{ __('production_execution.fields.fixed_asset') }}:</strong> {{ $record->productionMachine?->code ?? $record->fixedAsset?->doc_num }} — {{ $record->productionMachine?->name ?? $record->fixedAsset?->asset_name }}</div>
    <div class="col-md-4"><strong>{{ __('production_execution.fields.stage') }}:</strong> {{ $record->stageSnapshot?->stage_name ?? '—' }}</div>
    <div class="col-md-6"><strong>{{ __('production_execution.fields.actual_start_at') }}:</strong> {{ $dates->formatDateTime($record->actual_start_at, '—') }}</div>
    <div class="col-md-6"><strong>{{ __('production_execution.fields.work_description') }}:</strong> {{ $record->work_description ?: '—' }}</div>
</div></div>
@if($receiptOnlyRecovery)<div class="alert alert-info">{{ __('production_receipt_cancellation.receipt_only_recovery') }} {{ __('production_receipt_cancellation.recovery') }}</div>@endif
@if(!$readOnlyReport && !$receiptOnlyRecovery && in_array($record->status, ['running', 'held'], true) && app(\Modules\Production\Services\ProductionStageTransferService::class)->isManaged($record))
@can('production.runs.complete')
@can('production.runs.correct_approve')
<div class="card mb-3"><div class="card-body"><h6>{{ __('production_daily_report.correction.piece_approval') }}</h6><p>{{ __('production_daily_report.correction.piece_help') }}</p>
<form method="POST" action="{{ route('admin.production.runs.piece-output-approval', $record) }}">@csrf
<label class="form-label w-100">{{ __('production_run_correction.reason') }}<x-forms.textarea name="reason" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100">{{ __('production_daily_report.correction.evidence') }}<x-forms.textarea name="evidence" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100"><x-forms.input type="checkbox" name="confirmed" value="1" required /> {{ __('production_daily_report.correction.piece_confirmed') }}</label>
<button class="btn btn-warning" type="submit">{{ __('production_daily_report.correction.piece_approval') }}</button></form>
</div></div>
@endcan
@endcan
@can('production.runs.correct')
<div class="card mb-3"><div class="card-body"><h6>{{ __('production_daily_report.correction.piece_withdraw') }}</h6>
<form method="POST" action="{{ route('admin.production.runs.piece-output-withdrawal', $record) }}">@csrf
<label class="form-label w-100">{{ __('production_run_correction.reason') }}<x-forms.textarea name="reason" required minlength="5" maxlength="2000" /></label>
<label class="form-label w-100">{{ __('production_daily_report.correction.evidence') }}<x-forms.textarea name="evidence" required minlength="5" maxlength="2000" /></label>
<button class="btn btn-warning" type="submit">{{ __('production_daily_report.correction.piece_withdraw') }}</button></form>
@php
    $pieceWithdrawals = \Illuminate\Support\Facades\DB::table('production_run_corrections')
        ->where('company_id', $record->company_id)->where('production_run_id', $record->id)
        ->where('corrected_output->kind', \Modules\Production\Services\ProductionPieceOutputApprovalService::WithdrawalKind)
        ->orderByDesc('id')->get();
@endphp
@foreach ($pieceWithdrawals as $withdrawal)
    <p>#{{ $withdrawal->id }} — {{ __('production_run_correction.'.$withdrawal->status) }} · {{ $withdrawal->reason }}</p>
    @if ($withdrawal->status === 'prepared')
        @can('production.runs.correct_approve')
            @if ((int) $withdrawal->prepared_by !== (int) auth()->id())
                <form method="POST" action="{{ route('admin.production.runs.corrections.approve', [$record, $withdrawal->id]) }}">
                    @csrf
                    <button class="btn btn-danger" type="submit">{{ __('production_run_correction.approve') }}</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.production.runs.corrections.reject', [$record, $withdrawal->id]) }}" class="mt-2">
                @csrf
                <button class="btn btn-falcon-default" type="submit">{{ __('production_run_correction.reject') }}</button>
            </form>
        @endcan
    @endif
@endforeach
</div></div>
@endcan
@endif
<div class="card mb-3"><div class="card-header"><h6>{{ __('production_daily_report.summary') }}</h6></div><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Planned') }}</th><th>{{ __('production_handover.produced') }}</th><th>{{ __('production_handover.received') }}</th><th>{{ __('production_handover.remaining') }}</th><th>{{ __('production_handover.quality_available') }}</th></tr></thead><tbody>
@foreach($runs as $run)
@php
    $position = $handoverService->quantities($run);
@endphp
<tr><td>@if(!$readOnlyReport && $run->id !== $record->id)<a href="{{ route('admin.production.runs.show', $run) }}">{{ $run->product?->doc_num }} — {{ $run->product?->name }}</a>@else{{ $run->product?->doc_num }} — {{ $run->product?->name }}@endif</td><td>{{ $run->unit?->name }}</td>
@foreach([$run->planned_base_quantity, $position['produced'], $position['received'], $position['remaining'], $position['quality_available']] as $quantity)<td dir="ltr">{{ $quantity === null ? '—' : $numbers->format(bcdiv((string) $quantity, (string) $run->conversion_factor, 8)) }}</td>@endforeach
</tr>
@endforeach
</tbody></table></div></div>
@if($record->requirements->isNotEmpty())
@php
    $runFormulaBasis = is_array($record->orderLine?->bom_snapshot)
        ? bcmul((string) $record->planned_base_quantity, (string) ($record->orderLine->bom_snapshot['basis_base_quantity'] ?? '1'), 8)
        : null;
@endphp
<details class="mb-3"><summary>{{ __('Material Requirements') }}</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Material') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Planned') }}</th><th>{{ __('Issued') }}</th><th>{{ __('Consumed') }}</th><th>{{ __('Waste') }}</th><th>{{ __('Returned') }}</th></tr></thead><tbody>
@foreach($record->requirements as $line)
<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $line->unit?->name }}</td><td>{{ $numbers->format($line->planned_quantity) }}@if($runFormulaBasis !== null)<div class="small text-muted" dir="ltr">{{ $numbers->format($runFormulaBasis) }} × {{ $numbers->format($line->component_quantity_snapshot) }} = {{ $numbers->format($line->planned_quantity) }}</div>@endif</td><td>{{ $numbers->format(bcadd((string) $line->issued_quantity, (string) $line->additional_issued_quantity, 8)) }}</td><td>{{ $numbers->format($line->consumed_quantity) }}</td><td>{{ $numbers->format($line->waste_quantity) }}</td><td>{{ $numbers->format($line->returned_quantity) }}</td></tr>
@endforeach
</tbody></table></div></details>
@endif
@if(collect($record->labor_details ?? [])->isNotEmpty())
<details class="mb-3"><summary>{{ __('production_daily_report.operations.labor') }} — {{ $numbers->format($record->totalLaborHours()) }} {{ __('production_execution.fields.actual_hours') }}</summary><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('production_execution.fields.worker_name') }}</th><th>{{ __('production_execution.fields.worker_role') }}</th><th>{{ __('production_execution.fields.actual_hours') }}</th></tr></thead><tbody>@foreach($record->labor_details as $labor)<tr><td>{{ $labor['name'] ?? $labor['employee_doc_num'] ?? '—' }}</td><td>{{ $labor['role'] ?? '—' }}</td><td>{{ $numbers->format($labor['actual_hours'] ?? '0') }}</td></tr>@endforeach</tbody></table></div></details>
@endif
<x-related-documents :documents="$documents" />
@if(($shiftEntries ?? collect())->isNotEmpty())
<div class="card mb-3"><div class="card-header">{{ __('production_execution.shift_evidence.report_title') }}</div><div class="card-body">
@foreach($shiftEntries->take(-5) as $entry)<div>{{ $dates->formatDate($entry->work_date) }} — {{ $entry->sheet_fields['shift_name'] }} — {{ $entry->sheet_fields['technician_names'] ?? collect($entry->crew_snapshot)->pluck('name')->join('، ') }}</div>@endforeach
</div></div>
@endif

@if(isset($linkedWarehouseDocuments) && $linkedWarehouseDocuments->isNotEmpty())
<div class="card mb-3"><div class="card-header">{{ __('production_handover.title') }} / {{ __('production_handover.warehouse_title') }}</div><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
@foreach($linkedWarehouseDocuments as $document)
@php
    $isHandover = $document->document_type === \Modules\Inventory\Models\InventoryDocument::TypeProductionHandover;
    $newReceipt = $document->source_document_type === \Modules\Inventory\Models\InventoryDocument::class;
    $permission = $isHandover ? 'production.handovers.view' : ($newReceipt ? 'inventory.production_receipts.view' : 'inventory.documents.view');
    $route = $isHandover ? 'admin.production.handovers.show' : ($newReceipt ? 'admin.inventory.production-receipts.show' : 'admin.inventory.documents.show');
@endphp
<tr><td>@if(!$readOnlyReport && auth()->user()->can($permission))<a href="{{ route($route, $document) }}">{{ $document->doc_num }}</a>@else{{ $document->doc_num }}@endif</td><td>{{ $dates->formatDate($document->document_date) }}</td><td>{{ __('production_handover.statuses.'.$document->status) }}</td></tr>
@endforeach
</tbody></table></div><div class="card-footer">{{ $linkedWarehouseDocuments->links() }}</div></div>
@endif
@if($record->progressEntries->isNotEmpty())
<div class="card mb-3"><div class="card-header">{{ __('Production Progress') }} — {{ $record->product?->name }} ({{ $record->product?->unit?->name }})</div><div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('production_daily_report.quantity') }}</th><th>{{ __('production_daily_report.operations.account') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>
@foreach($record->progressEntries->sortByDesc('recorded_at')->take(10) as $entry)<tr><td>{{ $dates->formatDateTime($entry->recorded_at) }}</td><td>{{ $numbers->format($entry->good_base_quantity) }}@foreach(['rejected', 'rework', 'scrap'] as $outcome)@if(bccomp((string) $entry->{$outcome.'_base_quantity'}, '0', 8) !== 0)<div class="small">{{ __('production_stage_transfer.output_'.$outcome) }}: {{ $numbers->format($entry->{$outcome.'_base_quantity'}) }}</div>@endif @endforeach</td><td>{{ __($dailyShiftIds->contains($entry->production_shift_entry_id) && $entry->material_documents === null ? 'production_daily_report.pending' : 'production_daily_report.settled') }}</td><td>{{ $entry->notes ?: '—' }}
@if($entry->stage_output_cost_owner_id !== null)<span class="badge bg-info">{{ __('production_stage_transfer.owner_recovery') }} #{{ $entry->stage_output_cost_owner_id }}</span>@endif
@if(!$readOnlyReport && $dailyShiftIds->contains($entry->production_shift_entry_id) && $entry->production_run_correction_id === null && auth()->user()->canAny(['production.runs.correct', 'production.runs.correct_approve']))
<a href="{{ route('admin.production.runs.daily-reports.correction', [$record, $entry->public_id]) }}">{{ __('production_daily_report.correction.title') }}</a>
@endif</td></tr>@endforeach
</tbody></table></div></div>
@endif
</div>
@endsection
