@extends('layouts.app')
@section('title', __('Purchase Order Delivery Schedule'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
<form method="POST" action="{{ route('admin.purchases.purchase-order-delivery-schedule.store', $record->doc_num) }}">
    @csrf
        <x-forms.line-item-cards />
    <div class="card mb-3"><div class="card-header py-2 d-flex justify-content-between align-items-center"><h5 class="mb-0">{{ __('Delivery Schedule for :document', ['document' => $record->doc_num]) }}</h5>@can('purchases.purchase_order_delivery_schedule.print')<a class="btn btn-falcon-default btn-sm" target="_blank" href="{{ route('admin.purchases.procurement.print', ['purchase-order-delivery-schedule', $record->doc_num]) }}">{{ __('Print') }}</a>@endcan</div><div class="card-body p-0">
        @if($errors->any())<div class="alert alert-danger m-3">{{ $errors->first() }}</div>@endif
        <div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>{{ __('Item') }}</th><th class="text-end">{{ __('Ordered') }}</th><th class="text-end">{{ __('Already scheduled') }}</th><th>{{ __('New date') }}</th><th>{{ __('New quantity') }}</th><th>{{ __('Notes') }}</th></tr></thead><tbody>
        @foreach($record->lines as $index => $line)
            <tr><td>{{ $line->product?->name }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->ordered_quantity) }}</td><td class="text-end" dir="ltr">{{ $numbers->format($line->deliverySchedules->where('status', '!=', 'cancelled')->sum('scheduled_quantity')) }}</td><td><x-forms.date-input name="schedules[{{ $index }}][scheduled_date]" /></td><td><x-forms.input type="hidden" name="schedules[{{ $index }}][purchase_order_line_public_id]" value="{{ $line->public_id }}" /><x-forms.numeric-input name="schedules[{{ $index }}][scheduled_quantity]" :scale="8" min="0.00000001" step="0.00000001" /></td><td><x-forms.input class="form-control" name="schedules[{{ $index }}][notes]" /></td></tr>
        @endforeach
        </tbody></table></div>
    </div></div>
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('Save schedules') }}</button></div>
</form>
@endsection
