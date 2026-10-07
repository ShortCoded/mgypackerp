@extends('layouts.app')
@section('title', __('production_handover.warehouse_create'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<form method="POST" action="{{ route('admin.inventory.production-receipts.store', $record) }}" data-production-warehouse-receipt-form>
    @csrf<x-forms.input type="hidden" name="_submission_token" :value="old('_submission_token', (string) \Illuminate\Support\Str::uuid())" />
    <div class="card"><div class="card-header d-flex justify-content-between"><h5>{{ __('production_handover.warehouse_create') }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.handovers.show', $record) }}">{{ __('common.actions.back') }}</a></div>
    <div class="card-body"><p>{{ __('production_handover.warehouse_help') }}</p><p>{{ __('production_handover.handover') }}: {{ $record->doc_num }} · {{ $record->branchStore?->name }}</p>@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif<x-forms.label :label="__('production_handover.date')" required /><x-forms.input type="date" name="document_date" :value="old('document_date', now()->toDateString())" required /></div>
    <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>{{ __('Product') }} / {{ __('Unit') }}</th><th>{{ __('production_handover.handed') }}</th><th>{{ __('production_handover.received') }}</th><th>{{ __('production_handover.remaining_receipt') }}</th><th>{{ __('production_handover.actual_quantity') }}</th></tr></thead><tbody>
    @foreach($lines as $index => $line)
        <tr data-production-warehouse-receipt-line><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}<div class="small text-muted">{{ $line->transactionUnit?->name }}@if($line->serialIdentity) · {{ $line->serialIdentity->serial_number }}@endif</div><x-forms.input type="hidden" name="lines[{{ $index }}][line_public_id]" :value="$line->public_id" /></td>@foreach(['quantity', 'received_quantity', 'remaining_quantity'] as $field)<td dir="ltr">{{ $numbers->format(bcdiv((string) $line->{$field}, $line->conversion_factor, 8)) }}</td>@endforeach<td><x-forms.numeric-input name="lines[{{ $index }}][quantity]" :value="old('lines.'.$index.'.quantity', '0')" :scale="8" min="0" step="0.00000001" arrow-step="1" :aria-label="__('production_handover.actual_quantity').' — '.$line->product?->name.' — '.$line->transactionUnit?->name" /></td></tr>
    @endforeach
    </tbody></table></div><div class="card-body"><x-forms.label :label="__('production_execution.fields.notes')" /><x-forms.textarea name="notes" :value="old('notes')" /></div><div class="card-footer d-flex gap-2"><button class="btn btn-primary" type="submit">{{ __('production_handover.save_draft') }}</button><a class="btn btn-falcon-default" href="{{ route('admin.production.handovers.show', $record) }}">{{ __('Cancel') }}</a></div></div>
</form>
@endsection
