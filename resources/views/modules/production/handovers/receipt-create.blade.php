@extends('layouts.app')
@section('title', __('production_handover.warehouse_create'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<div class="production-mobile-workflow">
<form method="POST" action="{{ route('admin.inventory.production-receipts.store', $record) }}" data-production-warehouse-receipt-form>
    @csrf
    <x-forms.line-item-cards :line-label="__('Product')" />
    <x-forms.input type="hidden" name="submit_action" value="save" />
    <div class="card"><div class="card-header py-2"><div class="row flex-between-center g-2"><div class="col"><h5 class="mb-0">{{ __('production_handover.warehouse_create') }}</h5></div><div class="col-auto">
            @include('modules.finance.partials.form-actions', ['mode' => 'create', 'record' => null, 'resource' => 'inventory.production_receipts', 'routePrefix' => 'admin.inventory.production-receipts', 'canEdit' => false, 'canClone' => false])
        </div></div></div>
    <div class="card-body"><p>{{ __('production_handover.warehouse_help') }}</p><p>{{ __('production_handover.handover') }}: {{ $record->doc_num }} · {{ $record->branchStore?->name }}</p>@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif<x-forms.label for="handover-document-date" :label="__('production_handover.date')" required /><x-forms.date-input id="handover-document-date" name="document_date" :value="old('document_date', now()->toDateString())" required /></div>
    <div class="table-responsive"><table class="table table-sm table-bordered align-middle erp-entry-lines mb-0"><thead class="bg-100"><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('production_handover.handed') }}</th><th>{{ __('production_handover.received') }}</th><th>{{ __('production_handover.remaining_receipt') }}</th><th>{{ __('production_handover.actual_quantity') }}</th></tr></thead><tbody>
    @foreach($lines as $index => $line)
        <tr data-production-warehouse-receipt-line><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}@if($line->serialIdentity)<div class="small text-muted">{{ $line->serialIdentity->serial_number }}</div>@endif<x-forms.input type="hidden" name="lines[{{ $index }}][line_public_id]" :value="$line->public_id" /></td><td>{{ $line->transactionUnit?->name }}</td>@foreach(['quantity', 'received_quantity', 'remaining_quantity'] as $field)<td dir="ltr">{{ $numbers->format(bcdiv((string) $line->{$field}, $line->conversion_factor, 8)) }}</td>@endforeach<td><x-forms.numeric-input name="lines[{{ $index }}][quantity]" :value="old('lines.'.$index.'.quantity', '0')" :scale="8" min="0" step="0.00000001" arrow-step="1" :aria-label="__('production_handover.actual_quantity').' — '.$line->product?->name.' — '.$line->transactionUnit?->name" /></td></tr>
    @endforeach
    </tbody></table></div><div class="card-body"><x-forms.label for="handover-notes" :label="__('production_execution.fields.notes')" /><x-forms.textarea id="handover-notes" name="notes" :value="old('notes')" /></div><div class="card-footer d-flex gap-2"><button class="btn btn-primary" type="submit" data-submit-action="save" data-shortcut-action="form.save">{{ __('production_handover.save_draft') }}</button><a class="btn btn-falcon-default" href="{{ route('admin.production.handovers.show', $record) }}">{{ __('Cancel') }}</a></div></div>
</form>
</div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
