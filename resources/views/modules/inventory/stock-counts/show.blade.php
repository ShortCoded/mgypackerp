@extends('layouts.app')

@section('title', $record->doc_num)

@section('content')
<form id="count-form" method="POST" action="{{ route('admin.inventory.stock-counts.record', $record) }}">@csrf</form>
<div class="card">
    <div class="card-header d-flex justify-content-between"><div><h5 class="mb-1">{{ $record->doc_num }}</h5><span class="badge bg-secondary">{{ $record->status }}</span></div><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.stock-counts.print', $record) }}">{{ __('Print') }}</a></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Product') }}</th><th>{{ __('Status') }}</th><th>{{ __('Batch') }}</th><th>{{ __('System') }}</th><th>{{ __('Physical') }}</th><th>{{ __('Variance reason') }}</th></tr></thead><tbody>@foreach($record->lines as $index => $line)<tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}<input form="count-form" type="hidden" name="lines[{{ $index }}][line_id]" value="{{ $line->id }}"></td><td>{{ $line->stock_status }}</td><td>{{ $line->batch_lot }}</td><td>{{ $line->system_quantity }}</td><td><input form="count-form" class="form-control form-control-sm" type="number" step="0.00000001" min="0" name="lines[{{ $index }}][physical_quantity]" value="{{ $line->physical_quantity }}" @disabled($record->status !== 'draft') required></td><td><input form="count-form" class="form-control form-control-sm" name="lines[{{ $index }}][variance_reason]" value="{{ $line->variance_reason }}" @disabled($record->status !== 'draft')></td></tr>@endforeach</tbody></table></div>
    <div class="card-footer d-flex justify-content-end gap-2">@if($record->status === 'draft')<button form="count-form" class="btn btn-primary" type="submit">{{ __('Record count') }}</button>@elseif($record->status === 'counted')<form method="POST" action="{{ route('admin.inventory.stock-counts.approve', $record) }}">@csrf<button class="btn btn-success">{{ __('Approve variance') }}</button></form>@endif</div>
</div>
@endsection
