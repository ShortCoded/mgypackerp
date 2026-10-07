@extends('layouts.app')
@section('title', __('production_run_correction.title'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between"><h5>{{ __('production_run_correction.title') }} — {{ $record->run_number }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.runs.show', $record) }}">{{ __('Back') }}</a></div>
    <div class="card-body">
        <p>{{ __('production_run_correction.help') }}</p>
        @if($record->material_accounting_mode === 'output_evidence')
        <p>{{ __('production_receipt_cancellation.help') }}</p>
        @foreach($record->inventoryDocuments->where('document_type', \Modules\Inventory\Models\InventoryDocument::TypeProductionReceipt) as $receiptDocument)
        <a class="btn btn-outline-danger btn-sm mb-2" href="{{ route('admin.production.runs.receipt-cancellations.index', [$record, $receiptDocument->doc_num]) }}">{{ $receiptDocument->doc_num }} — {{ __('production_receipt_cancellation.title') }}</a>
        @endforeach
        @endif
        @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @foreach($blockers ?? [] as $blocker)<div class="alert alert-warning">{{ $blocker }}</div>@endforeach
        @if($correction_steps !== [])
        <div class="alert alert-warning">
            <h6>{{ __('production_run_correction.dependencies_title') }}</h6>
            <p>{{ __('production_run_correction.dependencies_help') }}</p>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>
                <th>{{ __('Document') }}</th><th>{{ __('production_run_correction.dependency_action') }}</th><th>{{ __('production_run_correction.dependency_context') }}</th><th>{{ __('Actions') }}</th>
            </tr></thead><tbody>
            @foreach($correction_steps as $step)
                <tr><td><a href="{{ $step['source_url'] }}">{{ $step['doc_num'] }}</a></td>
                    <td>{{ __('production_run_correction.actions.'.$step['action']) }}</td><td>{{ $step['context_label'] }}</td>
                    <td>@if($step['permitted'] && $step['correction_url'] !== null)<a class="btn btn-sm btn-warning" href="{{ $step['correction_url'] }}">{{ __('production_run_correction.resolve_dependency') }}</a>@else<span class="text-muted">{{ __('production_run_correction.permission_required') }}</span>@endif</td>
                </tr>
            @endforeach
            </tbody></table></div>
        </div>
        @endif
        @can('production.runs.correct')
        @if($record->material_accounting_mode !== 'output_evidence' && $record->status === \Modules\Production\Models\ProductionRun::StatusCompleted && $correction_steps === [] && ($blockers ?? []) === [])
        <form method="post" action="{{ route('admin.production.runs.corrections.store', $record) }}" novalidate>@csrf
            <x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" />
            <div class="row g-3">
                <div class="col-md-6"><x-forms.label for="correction_mode" :required="true" :label="__('production_run_correction.mode')" /><x-forms.select id="correction_mode" name="correction_mode" variant="local" :allow-clear="false">
                    <option value="original_period" @selected(old('correction_mode', $default_mode) === 'original_period')>{{ __('production_run_correction.original_period') }}</option>
                    @can('production.runs.correct_later_period')<option value="later_period" @selected(old('correction_mode', $default_mode) === 'later_period')>{{ __('production_run_correction.later_period') }}</option>@endcan
                </x-forms.select></div>
                <div class="col-md-6"><x-forms.label for="posting_date" :required="true" :label="__('production_run_correction.posting_date')" /><x-forms.date-input id="posting_date" name="posting_date" :value="old('posting_date', $default_mode === 'later_period' ? now()->toDateString() : $record->actual_end_at?->toDateString())" /></div>
                <div class="col-12"><p>{{ __('production_run_correction.posting_period') }}: {{ $posting_period?->name }} / {{ $posting_period?->doc_num }}</p><p class="text-muted">{{ __('production_run_correction.later_period_help') }}</p></div>
                @php($receiptDocuments = $snapshot['documents']->where('document_type', \Modules\Inventory\Models\InventoryDocument::TypeProductionReceipt)->where('status', \Modules\Inventory\Models\InventoryDocument::StatusPosted)->keyBy('id'))
                @php($receiptLines = $snapshot['document_lines']->whereIn('inventory_document_id', $receiptDocuments->keys()))
                @if($receiptLines->isNotEmpty())
                <div class="col-12"><h6>{{ __('production_run_correction.receipt_dates') }}</h6><p class="text-muted">{{ __('production_run_correction.receipt_dates_help') }}</p></div>
                @foreach($receiptLines as $receiptLine)
                <div class="col-12 border rounded p-3"><div class="mb-2">{{ $receiptDocuments[$receiptLine->inventory_document_id]->doc_num }} — {{ $numbers->format($receiptLine->quantity) }} — {{ $receiptLine->batch_lot }}</div><div class="row g-3">
                    @foreach(['manufacture_date' => __('Manufacture Date'), 'expiry_date' => __('Expiry Date')] as $dateField => $dateLabel)
                    <div class="col-md-6"><x-forms.label :for="'receipt-'.$receiptLine->id.'-'.$dateField" :label="$dateLabel" />
                    @if($receiptLine->{$dateField} !== null)
                    <div class="form-control-plaintext">{{ $dates->formatDate($receiptLine->{$dateField}) }}</div>
                    @else
                    <x-forms.date-input :id="'receipt-'.$receiptLine->id.'-'.$dateField" :name="'receipt_dates['.$receiptLine->id.']['.$dateField.']'" :value="old('receipt_dates.'.$receiptLine->id.'.'.$dateField)" />
                    @endif</div>
                    @endforeach
                </div></div>
                @endforeach
                <div class="col-12"><x-forms.label for="receipt_date_evidence" :label="__('production_run_correction.receipt_date_evidence')" /><x-forms.textarea id="receipt_date_evidence" name="receipt_date_evidence">{{ old('receipt_date_evidence') }}</x-forms.textarea></div>
                @endif
                @foreach(['good_base_quantity' => 'Good','rejected_base_quantity' => 'Rejected','rework_base_quantity' => 'Rework','scrap_base_quantity' => 'Scrap'] as $field => $label)
                <div class="col-md-3"><x-forms.label :for="$field" :required="true" :label="__($label)" /><x-forms.numeric-input :id="$field" :name="'output['.$field.']'" :value="old('output.'.$field, $record->{$field})" /></div>
                @endforeach
                <div class="col-12"><x-forms.label for="reason" :required="true" :label="__('production_run_correction.reason')" /><x-forms.textarea id="reason" name="reason">{{ old('reason') }}</x-forms.textarea></div>
                <div class="col-12"><button class="btn btn-primary">{{ __('production_run_correction.prepare') }}</button></div>
            </div>
        </form>
        @endif
        @endcan
    </div>
</div>
<x-admin.report.table-card :title="__('production_run_correction.documents')" table-id="run-correction-documents">
<thead><tr><th>{{ __('Document') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
@foreach($snapshot['documents'] as $document)<tr><td>@can('inventory.documents.view')<a href="{{ route('admin.inventory.documents.show', $document->doc_num) }}">{{ $document->doc_num }}</a>@else
{{ $document->doc_num }}@endcan
</td><td>{{ __('inventory.movements.types.'.$document->document_type) }}</td><td>{{ __('inventory.movements.statuses.'.$document->status) }}</td></tr>@endforeach
</tbody></x-admin.report.table-card>
@include('modules.production.runs.correction-impact', ['impact' => $impact, 'impactKey' => 'current'])
<div class="card mt-3"><div class="card-header">{{ __('production_run_correction.history') }}</div><div class="card-body">
@foreach($corrections as $correction)
@php($original = json_decode($correction->source_snapshot, true, 512, JSON_THROW_ON_ERROR))
@php($corrected = json_decode($correction->corrected_output, true, 512, JSON_THROW_ON_ERROR))
<div class="border rounded p-3 mb-3"><h6>#{{ $correction->id }} — {{ __('production_run_correction.'.$correction->status) }}</h6><p>{{ $correction->reason }} — {{ $dates->formatDateTime($correction->created_at) }}</p>
@if(($corrected['kind'] ?? null) === 'receipt_cancellation')<p>{{ __('production_receipt_cancellation.title') }} — {{ __('production_receipt_cancellation.retained') }}</p>@endif
<p>{{ __('production_run_correction.posting_date') }}: {{ $dates->formatDate($correction->posting_date) }} — {{ __('production_run_correction.'.($correction->correction_mode ?? 'original_period')) }}</p>
@if($correction->receipt_date_basis !== null)
<h6>{{ __('production_run_correction.receipt_dates') }}</h6><p>{{ __('production_run_correction.receipt_dates_help') }}</p>
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Quantity') }}</th><th>{{ __('inventory.movements.fields.batch_lot') }}</th><th>{{ __('Manufacture Date') }}</th><th>{{ __('Expiry Date') }}</th></tr></thead><tbody>
@foreach(json_decode($correction->receipt_date_basis, true, 512, JSON_THROW_ON_ERROR) as $dateBasis)
<tr><td>{{ $numbers->format($dateBasis['quantity']) }}</td><td>{{ $dateBasis['batch_lot'] }}</td><td>{{ $dates->formatDate($dateBasis['manufacture_date']) }}</td><td>{{ $dates->formatDate($dateBasis['expiry_date']) }}</td></tr>
@endforeach</tbody></table></div><p>{{ $correction->receipt_date_evidence }}</p>
@endif
<div class="table-responsive"><table class="table table-sm"><thead><tr><th></th><th>{{ __('production_run_correction.source') }}</th><th>{{ __('production_run_correction.replacement') }}</th></tr></thead><tbody>
@foreach(['good_base_quantity'=>'Good','rejected_base_quantity'=>'Rejected','rework_base_quantity'=>'Rework','scrap_base_quantity'=>'Scrap'] as $field=>$label)<tr><td>{{ __($label) }}</td><td>{{ $numbers->format($original['run'][$field]) }}</td><td>{{ $numbers->format($corrected[$field]) }}</td></tr>@endforeach
</tbody></table></div>
@if(isset($original['impact']))
@include('modules.production.runs.correction-impact', ['impact' => $original['impact'], 'impactKey' => $correction->id])
@endif
@can('production.runs.correct_approve')
@if($correction->status === 'prepared')<div class="d-flex gap-2">
@if((int)$correction->prepared_by !== (int)auth()->id())<form method="post" action="{{ route('admin.production.runs.corrections.approve', [$record,$correction->id]) }}" novalidate>@csrf<button class="btn btn-success">{{ __('production_run_correction.approve') }}</button></form>@endif
<form method="post" action="{{ route('admin.production.runs.corrections.reject', [$record,$correction->id]) }}" novalidate>@csrf<button class="btn btn-falcon-default">{{ __('production_run_correction.reject') }}</button></form>
</div>@endif
@endcan
</div>
@endforeach
</div></div>
@endsection
