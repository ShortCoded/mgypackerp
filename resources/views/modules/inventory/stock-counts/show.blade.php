@extends('layouts.app')

@section('title', $record->doc_num)

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between"><div><h5 class="mb-1">{{ $record->doc_num }}</h5><span class="badge bg-secondary">{{ __(str($record->status)->replace('_', ' ')->title()->toString()) }}</span></div>@can('inventory.stock_counts.print')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.stock-counts.print', $record) }}">{{ __('Print') }}</a>@endcan</div>
<form id="count-form" method="POST" action="{{ route('admin.inventory.stock-counts.record', $record) }}">@csrf<x-forms.line-item-cards />
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Status') }}</th><th>{{ __('Batch') }}</th><th>{{ __('System') }}</th><th>{{ __('Physical') }}</th><th>{{ __('Variance reason') }}</th></tr></thead><tbody>@foreach($record->lines as $index => $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}<x-forms.input form="count-form" type="hidden" name="lines[{{ $index }}][line_id]" value="{{ $line->id }}" /></td><td>{{ __(str($line->stock_status)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $line->batch_lot }}</td><td>{{ $line->system_quantity }}</td><td><x-forms.input form="count-form" class="form-control form-control-sm" type="number" step="0.00000001" min="0" name="lines[{{ $index }}][physical_quantity]" value="{{ $line->physical_quantity }}" :disabled="$record->status !== 'draft'" required /></td><td><x-forms.input form="count-form" class="form-control form-control-sm" name="lines[{{ $index }}][variance_reason]" value="{{ $line->variance_reason }}" :disabled="$record->status !== 'draft'" /></td></tr>@endforeach</tbody></table></div>
</form>
    <div class="card-footer d-flex justify-content-end gap-2">@if($record->status === 'draft')@can('inventory.stock_counts.record')<button form="count-form" class="btn btn-primary" type="submit">{{ __('Record count') }}</button>@endcan @elseif($record->status === 'counted')@can('inventory.stock_counts.approve')<form method="POST" action="{{ route('admin.inventory.stock-counts.approve', $record) }}">@csrf<button class="btn btn-success">{{ __('Approve variance') }}</button></form>@endcan @endif</div>
</div>
@endsection
