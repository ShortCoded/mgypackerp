@extends('layouts.app')

@section('title', __('Production Runs'))

@section('content')
<div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('Make-to-Stock Production Order') }}</h5></div><div class="card-body"><form method="POST" action="{{ route('admin.production.work-orders.make-to-stock.store') }}" class="row g-3 align-items-end">@csrf
    <div class="col-md-3"><label class="form-label">{{ __('Finished product') }}</label><select class="form-select" name="product_id" required>@foreach($finishedProducts as $product)<option value="{{ $product->id }}">{{ $product->doc_num }} — {{ $product->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label">{{ __('Production unit') }}</label><select class="form-select" name="unit_id"><option value="">{{ __('Product base unit') }}</option>@foreach($finishedUnits as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label">{{ __('Quantity') }}</label><input class="form-control" type="number" step="0.00000001" min="0.00000001" name="quantity" required></div>
    <div class="col-md-2"><label class="form-label">{{ __('Priority') }}</label><select class="form-select" name="priority"><option value="normal">{{ __('Normal') }}</option><option value="high">{{ __('High') }}</option><option value="urgent">{{ __('Urgent') }}</option><option value="low">{{ __('Low') }}</option></select></div>
    <div class="col-md-2"><label class="form-label">{{ __('Overproduction tolerance %') }}</label><input class="form-control" type="number" step="0.0001" min="0" max="100" name="overproduction_tolerance_percent" value="0" required></div>
    <div class="col-md-1"><button class="btn btn-primary w-100">{{ __('Create') }}</button></div>
</form></div></div>
<div class="card mb-3">
    <div class="card-header"><h5 class="mb-0">{{ __('Plan Production Run') }}</h5></div>
    <div class="card-body"><form method="POST" action="{{ route('admin.production.runs.store') }}" class="row g-3 align-items-end">@csrf
        <div class="col-md-3"><label class="form-label">{{ __('Released order line') }}</label><select class="form-select" name="production_order_line_id" required>@foreach($orders as $order)@foreach($order->lines as $line)<option value="{{ $line->id }}">{{ $order->doc_num }} / {{ $line->product?->name }} / {{ $line->quantity }}</option>@endforeach @endforeach</select></div>
        <div class="col-md-1"><label class="form-label">{{ __('Quantity') }}</label><input class="form-control" name="planned_quantity" type="number" step="0.00000001" min="0.00000001" required></div>
        <div class="col-md-2"><label class="form-label">{{ __('Starts') }}</label><input class="form-control" name="planned_start_at" type="datetime-local" required></div>
        <div class="col-md-2"><label class="form-label">{{ __('Ends') }}</label><input class="form-control" name="planned_end_at" type="datetime-local" required></div>
        <div class="col-md-1"><label class="form-label">{{ __('Shift') }}</label><select class="form-select" name="production_shift_id"><option value="">—</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}">{{ $shift->code }}</option>@endforeach</select></div>
        <div class="col-md-1"><label class="form-label">{{ __('Machine') }}</label><select class="form-select" name="production_machine_id"><option value="">—</option>@foreach($machines as $machine)<option value="{{ $machine->id }}">{{ $machine->code }}</option>@endforeach</select></div>
        <div class="col-md-1"><label class="form-label">{{ __('Mold') }}</label><select class="form-select" name="production_mold_id"><option value="">—</option>@foreach($molds as $mold)<option value="{{ $mold->id }}">{{ $mold->code }}</option>@endforeach</select></div>
        <div class="col-md-1"><button class="btn btn-primary w-100" type="submit">{{ __('Create') }}</button></div>
    </form></div>
</div>
<div class="card"><div class="card-header"><h5 class="mb-0">{{ __('Production Runs') }}</h5></div><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>{{ __('Run') }}</th><th>{{ __('Order') }}</th><th>{{ __('Product') }}</th><th>{{ __('Schedule') }}</th><th>{{ __('Machine / Mold') }}</th><th>{{ __('Planned') }}</th><th>{{ __('Good') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@forelse($records as $record)<tr><td><a href="{{ route('admin.production.runs.show', $record) }}">{{ $record->run_number }}</a></td><td>{{ $record->order?->doc_num }}</td><td>{{ $record->product?->name }}</td><td>{{ $record->planned_start_at?->format('Y-m-d H:i') }} → {{ $record->planned_end_at?->format('Y-m-d H:i') }}</td><td>{{ $record->machine?->name }} / {{ $record->mold?->name }}</td><td>{{ $record->planned_base_quantity }}</td><td>{{ $record->good_base_quantity }}</td><td><span class="badge bg-secondary">{{ str($record->status)->title() }}</span></td></tr>@empty<tr><td colspan="8" class="text-center py-5 text-500">{{ __('No production runs found.') }}</td></tr>@endforelse</tbody></table></div>@if($records->hasPages())<div class="card-footer">{{ $records->links() }}</div>@endif</div>
@endsection
