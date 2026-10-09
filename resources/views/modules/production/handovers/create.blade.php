@extends('layouts.app')
@section('title', __('production_handover.create'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<div class="production-mobile-workflow">
<form method="POST" action="{{ route('admin.production.handovers.store', $record) }}" data-production-handover-form>
    @csrf
    <x-forms.line-item-cards :line-label="__('Product')" />
    <x-forms.input type="hidden" name="submit_action" value="save" />
    <div class="card mb-3">
        <div class="card-header py-2"><div class="row flex-between-center g-2"><div class="col"><h5 class="mb-0">{{ __('production_handover.create') }}</h5></div><div class="col-auto">
            @include('modules.finance.partials.form-actions', ['mode' => 'create', 'record' => null, 'resource' => 'production.handovers', 'routePrefix' => 'admin.production.handovers', 'canEdit' => false, 'canClone' => false])
        </div></div></div>
        <div class="card-body">
            <p>{{ __('production_handover.handover_help') }}</p>
            @if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
            <div class="row g-3"><div class="col-md-6"><x-forms.label for="handover-document-date" :label="__('production_handover.date')" required /><x-forms.date-input id="handover-document-date" name="document_date" :value="old('document_date', now()->toDateString())" required /></div><div class="col-md-6"><x-forms.label for="handover-store" :label="__('production_handover.store')" required /><x-forms.select id="handover-store" name="branch_store_id" variant="local" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string) old('branch_store_id') === (string) $store->id)>{{ $store->name }}</option>@endforeach</x-forms.select></div></div>
        </div>
        <div class="table-responsive"><table class="table table-sm table-bordered align-middle erp-entry-lines mb-0"><thead class="bg-100"><tr><th>{{ __('Product') }}</th><th>{{ __('Unit') }}</th><th>{{ __('production_handover.produced') }}</th><th>{{ __('production_handover.quality_available') }}</th><th>{{ __('production_handover.committed') }}</th><th>{{ __('production_handover.remaining') }}</th><th>{{ __('production_handover.quantity') }}</th></tr></thead><tbody>
        @foreach($runs as $index => $run)
            @php($position = $quantities[$run->id])
            <tr data-production-handover-line><td>{{ $run->product->doc_num }} — {{ $run->product->name }}<div class="small text-muted">{{ $run->run_number }}</div><x-forms.input type="hidden" name="lines[{{ $index }}][run_public_id]" :value="$run->public_id" /></td><td>{{ $run->unit?->name }}</td>
                @foreach(['produced', 'quality_available', 'committed', 'remaining'] as $field)<td dir="ltr">{{ $position[$field] === null ? '—' : $numbers->format(bcdiv($position[$field], $run->conversion_factor, 8)) }}</td>@endforeach
                <td><x-forms.numeric-input name="lines[{{ $index }}][quantity]" :value="old('lines.'.$index.'.quantity', '0')" :scale="8" min="0" step="0.00000001" arrow-step="1" :aria-label="__('production_handover.quantity').' — '.$run->product->name.' — '.$run->unit?->name" />@if($run->product->tracks_serials)<x-forms.label :label="__('inventory_serial.numbers')" /><x-forms.textarea name="lines[{{ $index }}][serial_numbers]" rows="2" :value="old('lines.'.$index.'.serial_numbers')" />@endif</td></tr>
        @endforeach
        </tbody></table></div>
        <div class="card-body"><x-forms.label for="handover-notes" :label="__('production_execution.fields.notes')" /><x-forms.textarea id="handover-notes" name="notes" :value="old('notes')" /></div>
        <div class="card-footer d-flex gap-2"><button class="btn btn-primary" type="submit" data-submit-action="save" data-shortcut-action="form.save">{{ __('production_handover.save_draft') }}</button><a class="btn btn-falcon-default" href="{{ route('admin.production.runs.show', $record) }}">{{ __('Cancel') }}</a></div>
    </div>
</form>
</div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
