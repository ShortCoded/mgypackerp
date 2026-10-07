@extends('layouts.app')
@section('title', __('production_daily_report.operations.'.$operation))
@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $laborRows = collect(old('labor_details', $record->labor_details ?? []));
    $receiptOnlyRecovery = app(\Modules\Production\Services\ProductionReceiptCancellationService::class)->isReceiptOnlyRecovery($record);
@endphp
<div class="production-mobile-workflow">
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><h5>{{ __('production_daily_report.operations.'.$operation) }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.runs.show', $record) }}">{{ __('Back') }}</a></div><div class="card-body">{{ $record->run_number }} — {{ $record->product?->name }} — {{ $record->unit?->name }} <span class="badge bg-secondary">{{ __('production_execution.statuses.'.$record->status) }}</span></div></div>
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
@if($receiptOnlyRecovery)<div class="alert alert-info">{{ __('production_receipt_cancellation.receipt_only_recovery') }}</div>@endif
@if($receiptOnlyRecovery && $operation !== 'complete')
@elseif($operation === 'labor')
<div class="card" data-production-labor-planning><div class="card-header">{{ $record->actualDurationHours() === null ? '—' : $numbers->format($record->actualDurationHours()) }} {{ __('production_execution.fields.actual_hours') }}</div>@include('modules.production.runs.partials.labor-operation')</div>
@elseif($operation === 'crew')
<form class="card" method="POST" action="{{ route('admin.production.runs.shift-defaults', $record) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><div class="card-body row g-3">
<div class="col-md-4"><x-forms.label :label="__('production_execution.fields.shift')" /><x-forms.select variant="local" name="hr_shift_id" required>@foreach($hrShifts as $shift)<option value="{{ $shift->id }}">{{ $shift->name }}</option>@endforeach</x-forms.select></div>
@for($index = 0; $index < 4; $index++)<div class="col-12 row g-2"><div class="col-md-6"><x-forms.select variant="ajax" name="crew[{{ $index }}][employee_id]" :url="route('admin.production.runs.select2.workers', ['identifier' => 'id'])" :placeholder="__('production_execution.fields.worker_name')" /></div><div class="col-md-3"><x-forms.select variant="local" name="crew[{{ $index }}][role]">@foreach(['operator', 'technician', 'supervisor', 'foreman'] as $role)<option value="{{ $role }}">{{ __('production_execution.shift_evidence.role_'.$role) }}</option>@endforeach</x-forms.select></div><div class="col-md-3"><x-forms.numeric-input name="crew[{{ $index }}][planned_hours]" :scale="4" min="0" max="24" :placeholder="__('production_execution.fields.planned_hours')" /></div></div>@endfor
</div><div class="card-footer text-end"><button class="btn btn-primary">{{ __('Save') }}</button></div></form>
@elseif($operation === 'checklist')
@foreach($record->order->orderStageSnapshots->where('is_required', true) as $stage)
@php
    $role = collect($record->material_evidence_policy['stage_roles'] ?? [])->firstWhere('stage_public_id', $stage->public_id)['role'] ?? null;
@endphp
@if(in_array($role, ['checklist', 'quality_notification'], true) && $stage->status !== 'completed')<form class="card mb-3" method="POST" action="{{ route('admin.production.runs.checklist', $record) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" /><x-forms.input type="hidden" name="stage_public_id" :value="$stage->public_id" /><div class="card-header">{{ $stage->stage_name }}</div><div class="card-body"><x-forms.label :label="__('production_execution.evidence.confirmation_reason')" /><x-forms.input name="reason" required /></div><div class="card-footer"><button class="btn btn-primary">{{ __('production_execution.evidence.confirm_checklist') }}</button></div></form>@endif
@endforeach
@else
@php
    $transition = match($record->status) {'planned' => 'setup.start', 'setup' => 'setup.complete', 'ready' => 'start', 'held' => 'resume', default => null};
    $action = $operation === 'setup' ? $transition : $operation;
@endphp
@if($action !== null)
<form class="card" method="POST" action="{{ route('admin.production.runs.'.$action, $record) }}">@csrf
<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
<div class="card-body row g-3">
@if(in_array($operation, ['reserve', 'issue', 'return', 'account'], true))
<div class="col-md-6"><x-forms.label :label="__('Warehouse')" /><x-forms.select id="material-operation-store" variant="local" name="branch_store_id" required>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</x-forms.select></div>
@if($operation === 'account')
<div class="col-12"><p>{{ __('production_daily_report.materials_help') }}</p><x-forms.label :label="__('production_daily_report.report_to_settle')" /><x-forms.select variant="local" name="daily_progress_public_id" :required="$dailyEntries->isNotEmpty()"><option value="">—</option>@foreach($dailyEntries as $entry)<option value="{{ $entry->public_id }}">{{ $dates->formatDateTime($entry->recorded_at) }} — {{ $numbers->format($entry->good_base_quantity) }} {{ $record->product?->unit?->name }}</option>@endforeach</x-forms.select></div>
@endif
@if(in_array($operation, ['return', 'account'], true))
<div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Material') }}</th><th>{{ __('Unit') }}</th><th>{{ __('production_daily_report.unaccounted') }}</th><th>{{ $operation === 'return' ? __('Qty') : __('Consumed') }}</th>@if($operation === 'account')<th>{{ __('Waste') }}</th>@endif</tr></thead><tbody>
@foreach($record->requirements as $index => $line)
@php
    $unaccounted = bcsub(bcsub(bcadd((string) $line->issued_quantity, (string) $line->additional_issued_quantity, 8), (string) $line->returned_quantity, 8), bcadd((string) $line->consumed_quantity, (string) $line->waste_quantity, 8), 8);
@endphp
<tr><td>{{ $line->product?->name }}<x-forms.input type="hidden" name="lines[{{ $index }}][requirement_id]" :value="$line->id" /></td><td>{{ $line->unit?->name }}</td><td>{{ $numbers->format($unaccounted) }}</td><td><x-forms.numeric-input name="lines[{{ $index }}][{{ $operation === 'return' ? 'quantity' : 'consumed_quantity' }}]" :value="old('lines.'.$index.'.'.($operation === 'return' ? 'quantity' : 'consumed_quantity'))" :scale="8" min="0" :max="$unaccounted" :required="$operation === 'account'" /></td>@if($operation === 'account')<td><x-forms.numeric-input name="lines[{{ $index }}][waste_quantity]" :value="old('lines.'.$index.'.waste_quantity', '0')" :scale="8" min="0" :max="$unaccounted" required /></td>@endif</tr>
@if($line->product?->tracks_serials)
@php
    $serialUrl = route('admin.inventory.documents.select2.receipt-layers', ['stock_status' => 'production_staging', 'production_run_public_id' => $record->public_id, 'product_doc_num' => $line->product->doc_num, 'document_date' => now()->toDateString()]);
@endphp
<tr><td colspan="5">@foreach($operation === 'return' ? ['serial_receipt_layer_ids'] : ['consumed_receipt_layer_ids', 'waste_receipt_layer_ids'] as $field)<x-forms.label :label="__($field === 'waste_receipt_layer_ids' ? 'Waste' : ($operation === 'return' ? 'Qty' : 'Consumed')).' — '.__('inventory_serial.numbers')" /><x-forms.select variant="ajax" :multiple="true" name="lines[{{ $index }}][{{ $field }}][]" :url="$serialUrl" :data-extra-params="json_encode(['branch_store_id' => '#material-operation-store'])" />@endforeach</td></tr>
@endif
@if($operation === 'account' && $dailyEntries->isNotEmpty())
<tr><td colspan="5"><div class="row g-2"><div class="col-md-4"><x-forms.label :label="__('production_daily_report.waste_classification')" /><x-forms.select variant="local" name="lines[{{ $index }}][waste_classification]"><option value="">—</option>@foreach(['process_scrap', 'packaging_loss', 'roll_trim', 'rejected_output'] as $classification)<option value="{{ $classification }}">{{ __('production_execution.evidence.'.$classification) }}</option>@endforeach</x-forms.select></div><div class="col-md-8"><x-forms.label :label="__('production_execution.fields.notes')" /><x-forms.input name="lines[{{ $index }}][notes]" :value="old('lines.'.$index.'.notes')" maxlength="1000" /></div></div></td></tr>
@endif
@endforeach
</tbody></table></div>
@endif
@endif
@if($operation === 'cancel')<div class="col-12"><x-forms.label :label="__('Cancellation reason')" /><x-forms.textarea name="reason" required /></div>@endif
@if(in_array($operation, ['complete', 'setup'], true))<div class="col-12">{{ __('production_daily_report.operations.'.$operation) }} — {{ $record->product?->name }}</div>@endif
</div><div class="card-footer text-end"><button class="btn btn-primary">{{ __('Confirm') }}</button></div></form>
@endif
@endif
</div>
@endsection
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
